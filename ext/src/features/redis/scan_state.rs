//! The streaming state behind a cursor command (SCAN, HSCAN, SSCAN, ZSCAN).
//!
//! The cursor lives here, not on the PHP side: PHP pulls batches with next()
//! and never sees a cursor value. COUNT decides how much the server does per
//! round trip; the batch size decides how much crosses the boundary at a time,
//! and the two are different numbers.

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::Mutex;
use tokio_util::sync::CancellationToken;

use redis::aio::ConnectionLike;
use redis::Value;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::commands;
use super::errors::{message as fail, Kind};
use super::pools::Acquired;
use super::values;

/// A failure carries the kind PHP raises it as, like everywhere else in the
/// feature: a deadline is a timeout, a dropped socket is a connection failure,
/// and only the server's own refusal is a command failure. Flattening them into
/// one kind is how a scan that ran out of time came back as a
/// RedisCommandException with no code in it.
type Outcome = std::result::Result<(), (Kind, String)>;

/// The elements read but not yet handed to PHP, plus what it takes to ask for
/// more. Behind one mutex, because close() may arrive from a cancelled flow
/// while next() is in the middle of a round trip.
struct Cursor {
    /// The pooled connection the scan runs on. Held for the life of the cursor
    /// and released when it closes, so the pool's own accounting matches what
    /// is actually in use.
    acquired: Acquired,
    /// The cursor the last reply carried. "0" means the scan is over; None
    /// means it has not started.
    cursor: Option<Vec<u8>>,
    buffered: Vec<Value>,
    finished: bool,
}

pub struct ScanState {
    name: String,
    args: Vec<Vec<u8>>,
    batch_size: usize,
    /// The deadline one next() may take, from the payload. It bounds the whole
    /// call rather than one round trip: a MATCH that finds nothing walks the
    /// keyspace over many round trips, and it is the walk that has to end.
    timeout_ms: i64,
    message: Arc<Message>,
    cursor: Mutex<Option<Cursor>>,
    /// Ends a next() that is in the middle of a walk. Cancelled by close(), so
    /// the flow going away does not have to wait for the round trip in flight —
    /// and, more to the point, so close() is not queued behind a walk that could
    /// last as long as the keyspace does.
    cancel: CancellationToken,
    start_time: Instant,
}

impl ScanState {
    pub fn new(
        name: String,
        args: Vec<Vec<u8>>,
        batch_size: usize,
        timeout_ms: i64,
        message: Arc<Message>,
        acquired: Acquired,
    ) -> Self {
        ScanState {
            name,
            args,
            batch_size,
            timeout_ms,
            message,
            cursor: Mutex::new(Some(Cursor {
                acquired,
                cursor: None,
                buffered: Vec::new(),
                finished: false,
            })),
            cancel: CancellationToken::new(),
            start_time: Instant::now(),
        }
    }

    /// One round trip: <NAME> [key] <cursor> [MATCH …] [COUNT …]. The key, when
    /// the command takes one, is already the first of the stored arguments —
    /// the cursor goes after it, which is where every cursor command puts it.
    async fn fetch(&self, cursor: &mut Cursor) -> Outcome {
        let previous = cursor.cursor.clone().unwrap_or_else(|| b"0".to_vec());

        let mut args: Vec<Vec<u8>> = Vec::with_capacity(self.args.len() + 1);

        let takes_key = self.name != "SCAN";

        if takes_key && !self.args.is_empty() {
            args.push(self.args[0].clone());
            args.push(previous);
            args.extend(self.args[1..].iter().cloned());
        } else {
            args.push(previous);
            args.extend(self.args.iter().cloned());
        }

        let command = commands::build(&self.name, &args);

        let mut connection = cursor.acquired.connection();

        let value = connection
            .req_packed_command(&command)
            .await
            .map_err(|error| values::classify_error(&error))?;

        let Value::Array(mut parts) = value else {
            return Err(self.unexpected("an unexpected reply"));
        };

        if parts.len() != 2 {
            return Err(self.unexpected("an unexpected reply"));
        }

        let elements = match parts.pop() {
            Some(Value::Array(elements)) => elements,
            _ => return Err(self.unexpected("no element list")),
        };

        let next_cursor = match parts.pop() {
            Some(Value::BulkString(bytes)) => bytes,
            Some(Value::Int(number)) => number.to_string().into_bytes(),
            Some(Value::SimpleString(text)) => text.into_bytes(),
            _ => return Err(self.unexpected("no cursor")),
        };

        cursor.finished = next_cursor == b"0";
        cursor.cursor = Some(next_cursor);
        cursor.buffered.extend(elements);

        Ok(())
    }

    /// A reply this side cannot make sense of. It is the server's answer, so it
    /// is a command failure — the command asked for something the server does not
    /// answer the way a cursor command answers.
    fn unexpected(&self, what: &str) -> (Kind, String) {
        (Kind::Command, format!("{} answered with {what}", self.name))
    }

    /// One batch: keep asking the server until there is something to hand over
    /// or the walk is done. An empty answer with a non-zero cursor is normal —
    /// a MATCH that hits nothing in this slice of the keyspace — and returning
    /// it to PHP would end the iteration early.
    async fn pull_batch(&self) -> std::result::Result<Result, (Kind, String)> {
        let mut guard = self.cursor.lock().await;

        let Some(cursor) = guard.as_mut() else {
            return Err((Kind::State, "scan is closed".to_string()));
        };

        while cursor.buffered.is_empty() && !cursor.finished {
            self.fetch(cursor).await?;
        }

        let taken = cursor.buffered.len().min(self.batch_size);
        let batch: Vec<Value> = cursor.buffered.drain(..taken).collect();

        let payload = values::encode_values(&batch).map_err(|error| (Kind::Command, error))?;
        let has_next = !cursor.buffered.is_empty() || !cursor.finished;

        Ok(if has_next {
            Result::success_with_next(&self.message, payload, calc_execution_ms(self.start_time))
        } else {
            Result::success(&self.message, payload, calc_execution_ms(self.start_time))
        })
    }
}

/// Bounds a batch by the payload's deadline. Zero means the caller asked for
/// none, and then only the cancellation token ends it.
async fn with_deadline<F, T>(timeout_ms: i64, work: F) -> std::result::Result<T, (Kind, String)>
where
    F: std::future::Future<Output = std::result::Result<T, (Kind, String)>>,
{
    if timeout_ms <= 0 {
        return work.await;
    }

    match tokio::time::timeout(Duration::from_millis(timeout_ms as u64), work).await {
        Ok(outcome) => outcome,
        Err(_) => Err((
            Kind::Timeout,
            format!("the scan did not answer within {timeout_ms} ms"),
        )),
    }
}

impl StateContract for ScanState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            // Both mandatory requirements of a handler apply to a batch as much as
            // to a one-shot command: the deadline bounds it and the token ends it.
            // The token is checked first, so a cancelled stream answers at once
            // instead of after one more round trip.
            let outcome = tokio::select! {
                biased;

                _ = self.cancel.cancelled() => Err((
                    Kind::Stopped,
                    "closed by task stop".to_string(),
                )),
                outcome = with_deadline(self.timeout_ms, self.pull_batch()) => outcome,
            };

            match outcome {
                Ok(result) => result,
                Err((kind, error)) => Result::error(&self.message, fail(kind, &error)),
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Cancel before reaching for the mutex: a next() in the middle of a
            // long walk holds it, and close() waiting for that walk to end is
            // exactly what a flow being stopped must not do.
            self.cancel.cancel();

            // Dropping the cursor releases the pooled connection. Taking it out
            // of the mutex means a second close finds nothing to do rather than
            // releasing twice.
            let taken = self.cursor.lock().await.take();

            drop(taken);
        })
    }
}

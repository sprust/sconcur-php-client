//! The streaming state behind a cursor command (SCAN, HSCAN, SSCAN, ZSCAN).
//!
//! The cursor lives here, not on the PHP side: PHP pulls batches with next()
//! and never sees a cursor value. COUNT decides how much the server does per
//! round trip; the batch size decides how much crosses the boundary at a time,
//! and the two are different numbers.

use std::sync::Arc;
use std::time::Instant;
use tokio::sync::Mutex;

use redis::aio::ConnectionLike;
use redis::Value;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::commands;
use super::pools::Acquired;
use super::values;

type Outcome = std::result::Result<(), String>;

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
    message: Arc<Message>,
    errors: &'static Factory,
    cursor: Mutex<Option<Cursor>>,
    start_time: Instant,
}

impl ScanState {
    pub fn new(
        name: String,
        args: Vec<Vec<u8>>,
        batch_size: usize,
        message: Arc<Message>,
        errors: &'static Factory,
        acquired: Acquired,
    ) -> Self {
        ScanState {
            name,
            args,
            batch_size,
            message,
            errors,
            cursor: Mutex::new(Some(Cursor {
                acquired,
                cursor: None,
                buffered: Vec::new(),
                finished: false,
            })),
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
            .map_err(|error| values::describe_error(&error))?;

        let Value::Array(mut parts) = value else {
            return Err(format!("{} answered with an unexpected reply", self.name));
        };

        if parts.len() != 2 {
            return Err(format!("{} answered with an unexpected reply", self.name));
        }

        let elements = match parts.pop() {
            Some(Value::Array(elements)) => elements,
            _ => return Err(format!("{} answered without an element list", self.name)),
        };

        let next_cursor = match parts.pop() {
            Some(Value::BulkString(bytes)) => bytes,
            Some(Value::Int(number)) => number.to_string().into_bytes(),
            Some(Value::SimpleString(text)) => text.into_bytes(),
            _ => return Err(format!("{} answered without a cursor", self.name)),
        };

        cursor.finished = next_cursor == b"0";
        cursor.cursor = Some(next_cursor);
        cursor.buffered.extend(elements);

        Ok(())
    }
}

impl StateContract for ScanState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut guard = self.cursor.lock().await;

            let Some(cursor) = guard.as_mut() else {
                return Result::error(&self.message, self.errors.by_text("scan is closed"));
            };

            // An empty batch is not the end of a scan: the server may answer a
            // whole round trip with no elements and a non-zero cursor, and
            // returning that to PHP would end the iteration early. Keep asking
            // until there is something to hand over or the cursor comes back to
            // zero.
            while cursor.buffered.is_empty() && !cursor.finished {
                if let Err(error) = self.fetch(cursor).await {
                    return Result::error(&self.message, self.errors.by_text(&error));
                }
            }

            let taken = cursor.buffered.len().min(self.batch_size);
            let batch: Vec<Value> = cursor.buffered.drain(..taken).collect();

            let payload = values::encode_values(&batch);
            let has_next = !cursor.buffered.is_empty() || !cursor.finished;

            if has_next {
                Result::success_with_next(&self.message, payload, calc_execution_ms(self.start_time))
            } else {
                Result::success(&self.message, payload, calc_execution_ms(self.start_time))
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Dropping the cursor releases the pooled connection. Taking it out
            // of the mutex means a second close finds nothing to do rather than
            // releasing twice.
            let taken = self.cursor.lock().await.take();

            drop(taken);
        })
    }
}

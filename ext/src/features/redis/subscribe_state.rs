//! The streaming state of a subscription: PHP pulls batches of messages with
//! next(), one batch per crossing.
//!
//! A batch is not a delay. The state hands over whatever has arrived and waits
//! only when nothing has, so a lone message crosses immediately and a fast
//! publisher costs one crossing per batch instead of one per message.

use std::sync::Arc;
use std::time::Instant;

use futures_util::{FutureExt, StreamExt};
use redis::aio::PubSubStream;
use rmp::encode;
use tokio::sync::Mutex;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::errors::{message as fail, Kind};

pub struct SubscribeState {
    stream: Mutex<Option<PubSubStream>>,
    batch_size: usize,
    message: Arc<Message>,
    /// Ends a next() that is waiting on a message. Cancelled when the
    /// subscription closes, so a pull that nobody will answer does not hold the
    /// state's mutex forever.
    cancel: CancellationToken,
    start_time: Instant,
}

impl SubscribeState {
    pub fn new(
        stream: PubSubStream,
        batch_size: usize,
        message: Arc<Message>,
        cancel: CancellationToken,
    ) -> Self {
        SubscribeState {
            stream: Mutex::new(Some(stream)),
            batch_size,
            message,
            cancel,
            start_time: Instant::now(),
        }
    }
}

impl StateContract for SubscribeState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut guard = self.stream.lock().await;

            let Some(stream) = guard.as_mut() else {
                return Result::error(&self.message, fail(Kind::State, "subscription is closed"));
            };

            let mut messages: Vec<Vec<u8>> = Vec::new();

            // The first message is waited for; the rest are only taken if they
            // are already there.
            let first = tokio::select! {
                biased;

                _ = self.cancel.cancelled() => None,
                message = stream.next() => message,
            };

            let Some(first) = first else {
                // The stream ended: the connection went away, or the
                // subscription was closed while this call waited.
                return Result::success(&self.message, encode_messages(&[]), calc_execution_ms(self.start_time));
            };

            messages.push(encode_message(&first));

            while messages.len() < self.batch_size {
                match stream.next().now_or_never() {
                    Some(Some(message)) => messages.push(encode_message(&message)),
                    // Nothing ready, or the stream ended — either way this batch
                    // is what there is.
                    _ => break,
                }
            }

            Result::success_with_next(
                &self.message,
                encode_messages(&messages),
                calc_execution_ms(self.start_time),
            )
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            self.cancel.cancel();

            // Dropping the stream closes the connection, which is what
            // unsubscribes: the socket was the subscription.
            let taken = self.stream.lock().await.take();

            drop(taken);
        })
    }
}

/// One message as the map PHP builds a Dto\Message from: the kind, the channel,
/// the pattern that matched (empty unless the kind is pmsg) and the payload.
fn encode_message(message: &redis::Msg) -> Vec<u8> {
    let mut buffer = Vec::new();

    // Read as bytes, not as a name: a channel is a Redis key, and a key may be
    // any bytes at all.
    let channel: Vec<u8> = message.get_channel::<Vec<u8>>().unwrap_or_default();

    let pattern: Vec<u8> = message
        .get_pattern::<Option<Vec<u8>>>()
        .ok()
        .flatten()
        .unwrap_or_default();

    // from_pattern, not the pattern being empty: a psubscribe to an empty
    // pattern is legal, however pointless.
    let kind: &str = if message.from_pattern() { "pmsg" } else { "msg" };

    let _ = encode::write_map_len(&mut buffer, 4);

    let _ = encode::write_str(&mut buffer, "k");
    let _ = encode::write_str(&mut buffer, kind);

    let _ = encode::write_str(&mut buffer, "c");
    let _ = encode::write_bin(&mut buffer, &channel);

    let _ = encode::write_str(&mut buffer, "p");
    let _ = encode::write_bin(&mut buffer, &pattern);

    let _ = encode::write_str(&mut buffer, "d");
    let _ = encode::write_bin(&mut buffer, message.get_payload_bytes());

    buffer
}

/// The batch: a list of already-encoded messages.
fn encode_messages(messages: &[Vec<u8>]) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = encode::write_array_len(&mut buffer, messages.len() as u32);

    for message in messages {
        buffer.extend_from_slice(message);
    }

    buffer
}

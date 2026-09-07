//! RESP values on their way to PHP, and PHP arguments on their way to a command.
//!
//! Strings go across as MessagePack `bin`, not `str`: a Redis value is
//! arbitrary bytes, and declaring them UTF-8 breaks on the first compressed
//! blob somebody stored.

use redis::{ErrorKind, RedisError, Value};
use rmp::encode;

/// The key an error reply carries inside a pipeline answer, and the marker that
/// tells the PHP side this map is an error rather than a value a command
/// returned. PHP: SConcur\Features\Redis\Dto\ErrorReply.
const ERROR_MARKER_KEY: &str = "__sconcur_redis_error";

/// Encodes one reply. The command is not consulted: what the server sent is
/// what crosses, and shaping a reply into something friendlier is the PHP
/// facade's job, where it is visible in the method that does it.
pub fn encode_value(value: &Value) -> Vec<u8> {
    let mut buffer = Vec::new();

    write_value(&mut buffer, value);

    buffer
}

/// Encodes a list of replies — the answer to a pipeline.
pub fn encode_values(values: &[Value]) -> Vec<u8> {
    let mut buffer = Vec::new();

    write_array(&mut buffer, values);

    buffer
}

fn write_value(buffer: &mut Vec<u8>, value: &Value) {
    match value {
        Value::Nil => {
            let _ = encode::write_nil(buffer);
        }
        Value::Int(number) => {
            let _ = encode::write_sint(buffer, *number);
        }
        Value::BulkString(bytes) => write_bin(buffer, bytes),
        Value::SimpleString(text) => write_bin(buffer, text.as_bytes()),
        // The status reply of SET, DEL and the rest. PHP sees the string every
        // other client shows, rather than a boolean this side invented.
        Value::Okay => write_bin(buffer, b"OK"),
        Value::Array(items) | Value::Set(items) => write_array(buffer, items),
        Value::Map(pairs) => {
            let _ = encode::write_map_len(buffer, pairs.len() as u32);

            for (key, item) in pairs {
                write_value(buffer, key);
                write_value(buffer, item);
            }
        }
        Value::Double(number) => {
            let _ = encode::write_f64(buffer, *number);
        }
        Value::Boolean(flag) => {
            let _ = encode::write_bool(buffer, *flag);
        }
        Value::VerbatimString { text, .. } => write_bin(buffer, text.as_bytes()),
        // Without the num-bigint feature redis-rs hands the digits over as
        // bytes, which is what a number too large for an i64 has to stay on the
        // PHP side anyway.
        Value::BigNumber(digits) => write_bin(buffer, digits),
        // An attribute is metadata attached to a reply; the reply is what the
        // caller asked for.
        Value::Attribute { data, .. } => write_value(buffer, data),
        Value::Push { data, .. } => write_array(buffer, data),
        Value::ServerError(error) => {
            let detail = error.details().unwrap_or("").to_string();

            write_error_reply(buffer, error.code(), &detail);
        }
        // Value is non_exhaustive: a variant added by a later redis-rs must not
        // silently become nil, so it crosses as an error reply naming itself.
        _ => write_error_reply(buffer, "ERR", "unsupported reply type"),
    }
}

fn write_array(buffer: &mut Vec<u8>, items: &[Value]) {
    let _ = encode::write_array_len(buffer, items.len() as u32);

    for item in items {
        write_value(buffer, item);
    }
}

fn write_bin(buffer: &mut Vec<u8>, bytes: &[u8]) {
    let _ = encode::write_bin(buffer, bytes);
}

/// The failure of one command inside a pipeline: the rest of the pipeline ran, so
/// it travels as a value in its place rather than failing the whole answer.
/// PHP: SConcur\Features\Redis\Dto\ErrorReply.
fn write_error_reply(buffer: &mut Vec<u8>, code: &str, message: &str) {
    let _ = encode::write_map_len(buffer, 3);

    let _ = encode::write_str(buffer, ERROR_MARKER_KEY);
    let _ = encode::write_bool(buffer, true);

    let _ = encode::write_str(buffer, "code");
    let _ = encode::write_str(buffer, code);

    let _ = encode::write_str(buffer, "message");
    let _ = encode::write_str(buffer, message);
}

/// One command argument, as the bytes it goes on the wire as.
///
/// The PHP side accepts only strings, integers and floats and writes integers
/// and floats out as their decimal form, so a value that is not a string here
/// is a payload that did not come from it. Both MessagePack string kinds are
/// taken, because what PHP packs depends on whether the string is valid UTF-8.
pub fn decode_arg(value: &rmpv::Value) -> Result<Vec<u8>, String> {
    match value {
        rmpv::Value::Binary(bytes) => Ok(bytes.clone()),
        rmpv::Value::String(text) => Ok(text.as_bytes().to_vec()),
        other => Err(format!("a command argument must be a string, got {other}")),
    }
}

/// Every argument of one command, in order.
pub fn decode_args(values: &[rmpv::Value]) -> Result<Vec<Vec<u8>>, String> {
    values.iter().map(decode_arg).collect()
}

/// A driver error as the text PHP parses back into an exception. The Redis
/// error code leads, so the PHP side reads it off the first word instead of
/// matching the whole message with a pattern.
pub fn describe_error(error: &RedisError) -> String {
    let code = error.code().unwrap_or_else(|| match error.kind() {
        ErrorKind::Io => "IOERR",
        ErrorKind::AuthenticationFailed => "NOAUTH",
        _ => "ERR",
    });

    match error.detail() {
        Some(detail) => format!("{code} {detail}"),
        None => format!("{code} {error}"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use rmpv::decode::read_value;

    fn decode(bytes: &[u8]) -> rmpv::Value {
        read_value(&mut &bytes[..]).expect("the encoder wrote valid MessagePack")
    }

    #[test]
    fn nil_becomes_nil() {
        assert_eq!(decode(&encode_value(&Value::Nil)), rmpv::Value::Nil);
    }

    #[test]
    fn an_integer_stays_an_integer() {
        assert_eq!(decode(&encode_value(&Value::Int(-42))), rmpv::Value::from(-42));
    }

    #[test]
    fn a_bulk_string_crosses_as_binary() {
        let encoded = encode_value(&Value::BulkString(vec![0x00, 0xff, b'a']));

        match decode(&encoded) {
            rmpv::Value::Binary(bytes) => assert_eq!(bytes, vec![0x00, 0xff, b'a']),
            other => panic!("expected binary, got {other:?}"),
        }
    }

    #[test]
    fn okay_crosses_as_the_status_string() {
        match decode(&encode_value(&Value::Okay)) {
            rmpv::Value::Binary(bytes) => assert_eq!(bytes, b"OK".to_vec()),
            other => panic!("expected binary, got {other:?}"),
        }
    }

    #[test]
    fn nested_arrays_keep_their_shape() {
        let value = Value::Array(vec![
            Value::Int(1),
            Value::Array(vec![Value::BulkString(b"inner".to_vec()), Value::Nil]),
        ]);

        match decode(&encode_value(&value)) {
            rmpv::Value::Array(items) => {
                assert_eq!(items.len(), 2);

                match &items[1] {
                    rmpv::Value::Array(inner) => assert_eq!(inner.len(), 2),
                    other => panic!("expected a nested array, got {other:?}"),
                }
            }
            other => panic!("expected an array, got {other:?}"),
        }
    }

    #[test]
    fn a_map_crosses_as_a_map() {
        let value = Value::Map(vec![(Value::BulkString(b"k".to_vec()), Value::Int(7))]);

        match decode(&encode_value(&value)) {
            rmpv::Value::Map(pairs) => assert_eq!(pairs.len(), 1),
            other => panic!("expected a map, got {other:?}"),
        }
    }

    #[test]
    fn resp3_scalars_keep_their_types() {
        assert_eq!(decode(&encode_value(&Value::Double(1.5))), rmpv::Value::from(1.5));
        assert_eq!(decode(&encode_value(&Value::Boolean(true))), rmpv::Value::from(true));
    }

    #[test]
    fn an_error_reply_is_a_marked_map() {
        let mut buffer = Vec::new();

        write_error_reply(&mut buffer, "WRONGTYPE", "wrong kind of value");

        match decode(&buffer) {
            rmpv::Value::Map(pairs) => {
                assert_eq!(pairs.len(), 3);

                let has_marker = pairs
                    .iter()
                    .any(|(key, _)| key.as_str() == Some(ERROR_MARKER_KEY));

                assert!(has_marker, "the marker key must be present");
            }
            other => panic!("expected a map, got {other:?}"),
        }
    }

    #[test]
    fn a_pipeline_answer_is_a_list() {
        let encoded = encode_values(&[Value::Int(1), Value::Nil]);

        match decode(&encoded) {
            rmpv::Value::Array(items) => assert_eq!(items.len(), 2),
            other => panic!("expected an array, got {other:?}"),
        }
    }

    #[test]
    fn an_argument_is_taken_from_either_string_kind() {
        assert_eq!(
            decode_arg(&rmpv::Value::Binary(vec![1, 2, 3])).unwrap(),
            vec![1, 2, 3]
        );
        assert_eq!(
            decode_arg(&rmpv::Value::from("key")).unwrap(),
            b"key".to_vec()
        );
    }

    #[test]
    fn an_argument_that_is_not_a_string_is_refused() {
        let error = decode_arg(&rmpv::Value::from(7)).unwrap_err();

        assert!(error.contains("must be a string"), "{error}");
    }
}

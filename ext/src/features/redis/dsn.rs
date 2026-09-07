//! The DSN a Redis payload carries, and what it is allowed to say.
//!
//! redis-rs parses the URL itself, including `?protocol=`, so nothing here
//! rebuilds that. What it adds is the refusal: redis-rs reads the query
//! parameters it knows and ignores the rest, and a parameter silently ignored
//! means the connection does not behave the way the configuration says it does.

/// The query parameters redis-rs acts on. `db`, `user` and `pass` only mean
/// something on a unix-socket URL, where the path is the socket; on a TCP URL
/// the same three come from the URL itself and are refused here rather than
/// accepted into nothing.
const TCP_PARAMS: &[&str] = &["protocol"];
const UNIX_PARAMS: &[&str] = &["protocol", "db", "user", "pass"];

#[derive(Debug)]
pub struct Dsn {
    /// The URL as it goes to redis-rs, unchanged.
    pub url: String,
}

/// Validates the DSN and hands back what `redis::Client::open` is given. The
/// scheme check is here rather than left to redis-rs so the message names the
/// four schemes instead of reporting a parse failure.
pub fn parse(dsn: &str) -> Result<Dsn, String> {
    let trimmed = dsn.trim();

    if trimmed.is_empty() {
        return Err("empty dsn".to_string());
    }

    let is_unix = trimmed.starts_with("unix://") || trimmed.starts_with("redis+unix://");

    if !is_unix
        && !trimmed.starts_with("redis://")
        && !trimmed.starts_with("rediss://")
    {
        return Err(format!(
            "unsupported dsn scheme in {trimmed}: expected redis://, rediss://, unix:// or redis+unix://"
        ));
    }

    let allowed = if is_unix { UNIX_PARAMS } else { TCP_PARAMS };

    for name in query_names(trimmed) {
        if !allowed.contains(&name.as_str()) {
            return Err(format!(
                "unknown dsn parameter {name}: this dsn accepts {}",
                allowed.join(", ")
            ));
        }
    }

    Ok(Dsn {
        url: trimmed.to_string(),
    })
}

/// The parameter names of the query string, in order. Split by hand rather than
/// with a URL parser: only the names are needed, and percent-encoding cannot
/// appear in one that would pass the allow-list anyway.
fn query_names(dsn: &str) -> Vec<String> {
    let Some((_, query)) = dsn.split_once('?') else {
        return Vec::new();
    };

    // A fragment is not part of the query. redis-rs reads `#insecure` on a
    // rediss:// URL, so it is left to it rather than treated as a parameter.
    let query = query.split('#').next().unwrap_or(query);

    query
        .split('&')
        .filter(|pair| !pair.is_empty())
        .map(|pair| pair.split_once('=').map(|(name, _)| name).unwrap_or(pair).to_lowercase())
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_four_schemes() {
        for dsn in [
            "redis://127.0.0.1:6379/0",
            "rediss://user:pass@example.com:6380/1",
            "unix:///var/run/redis.sock",
            "redis+unix:///var/run/redis.sock?db=2",
        ] {
            assert!(parse(dsn).is_ok(), "{dsn} should parse");
        }
    }

    #[test]
    fn refuses_another_scheme() {
        let error = parse("http://127.0.0.1:6379").unwrap_err();

        assert!(error.contains("unsupported dsn scheme"), "{error}");
    }

    #[test]
    fn refuses_an_empty_dsn() {
        assert!(parse("   ").is_err());
    }

    #[test]
    fn accepts_the_protocol_parameter() {
        assert!(parse("redis://127.0.0.1:6379/0?protocol=resp3").is_ok());
    }

    #[test]
    fn refuses_an_unknown_parameter() {
        let error = parse("redis://127.0.0.1:6379/0?pool_size=4").unwrap_err();

        assert!(error.contains("unknown dsn parameter pool_size"), "{error}");
    }

    #[test]
    fn refuses_a_tcp_only_parameter_on_a_tcp_url() {
        // db belongs in the path of a TCP url; as a parameter it would be read
        // by nothing.
        assert!(parse("redis://127.0.0.1:6379/?db=3").is_err());
    }

    #[test]
    fn accepts_the_unix_parameters_on_a_socket_url() {
        assert!(parse("unix:///var/run/redis.sock?db=1&user=u&pass=p&protocol=3").is_ok());
    }

    #[test]
    fn keeps_the_dsn_verbatim() {
        let dsn = parse("redis://127.0.0.1:6379/7?protocol=resp3").unwrap();

        assert_eq!(dsn.url, "redis://127.0.0.1:6379/7?protocol=resp3");
    }

    #[test]
    fn leaves_the_insecure_fragment_to_the_driver() {
        assert!(parse("rediss://127.0.0.1:6379/0?protocol=3#insecure").is_ok());
    }
}

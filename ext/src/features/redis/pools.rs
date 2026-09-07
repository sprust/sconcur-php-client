//! The connections behind the feature: a small pool of multiplexed ones per
//! DSN for ordinary commands, and dedicated ones for the commands that cannot
//! share a socket.
//!
//! Redis serves one connection strictly in order, so what a connection is used
//! for decides how it may be shared:
//!
//! - an ordinary command shares a multiplexed connection with every other task,
//!   which is where the feature's whole gain comes from — thirty GETs from
//!   thirty coroutines go out as one pipeline and come back in one round trip;
//! - a blocking command (BLPOP, XREAD BLOCK, WAIT) holds the server's attention
//!   for as long as it waits, so on a shared socket it would stall every command
//!   queued behind it. It gets a connection of its own for the call;
//! - a subscription owns its connection outright, because the protocol puts the
//!   connection itself into subscriber mode.

use std::collections::HashMap;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use redis::aio::{ConnectionManager, ConnectionManagerConfig, MultiplexedConnection};
use redis::{AsyncConnectionConfig, Client};

use super::dsn;

const POOL_IDLE_TTL: Duration = Duration::from_secs(5 * 60);
const POOL_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

/// The pool size applied when the caller asks for none. One multiplexed
/// connection already carries any number of concurrent commands; more than one
/// exists so a single large value in flight does not hold up everything queued
/// behind it on the same socket.
const DEFAULT_POOL_SIZE: usize = 4;

/// The ceiling on what a caller may ask for. A pool is per DSN and per process,
/// and a worker pool multiplies it by the worker count on the server's side.
const MAX_POOL_SIZE: usize = 64;

/// How long dialling the server may take. Only the dial: everything after it is
/// bounded by the payload's own deadline, which is the number the caller set.
///
/// It exists because the driver's reconnect loop runs behind the commands, with
/// nobody's deadline over it — an attempt against a host that swallows packets
/// has to end by itself or the loop never gets to the next one. The driver's own
/// default is one second, which is a local-network number.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

/// The driver's per-command timeout, turned off.
///
/// redis-rs defaults it to 500 ms, and on this feature that is simply wrong: a
/// blocking command waits for minutes by design, and an ordinary one against a
/// busy server or a large value can take longer than half a second. The deadline
/// here is the payload's `to`, applied by the feature, and there must not be a
/// second one underneath it that nobody asked for.
const RESPONSE_TIMEOUT: Option<Duration> = None;

/// Identifies a pool by everything that changes what its connections are.
#[derive(PartialEq, Eq, Hash, Clone)]
struct PoolKey {
    dsn: String,
    pool_size: usize,
    conn_max_lifetime_ms: i64,
}

struct Entry {
    connections: Vec<ConnectionManager>,
    /// Round-robin cursor. Which connection a command lands on does not matter
    /// beyond spreading them, so this is a counter rather than a load measure.
    next_index: usize,
    opened_at: Instant,
    in_use: i64,
    last_used_at: Instant,
}

pub struct Pools {
    entries: Mutex<HashMap<PoolKey, Entry>>,
}

impl Pools {
    pub fn new() -> Self {
        Pools {
            entries: Mutex::new(HashMap::new()),
        }
    }
}

/// A borrowed multiplexed connection. Dropping it releases the pool's owner
/// count, so an early return cannot leak a reference the way a missed release
/// call would.
pub struct Acquired {
    key: PoolKey,
    connection: ConnectionManager,
}

impl Acquired {
    /// The connection to issue commands on. Cloned rather than borrowed:
    /// redis-rs wants `&mut`, and a ConnectionManager clone is a handle to the
    /// same multiplexed socket.
    pub fn connection(&self) -> ConnectionManager {
        self.connection.clone()
    }
}

impl Drop for Acquired {
    fn drop(&mut self) {
        get().release(&self.key);
    }
}

/// The pools live on the Core, like every other process-wide registry here, so
/// a fork discards them: a child that inherited the parent's entries would be
/// writing into sockets that belong to another process.
pub fn get() -> &'static Pools {
    crate::core::get().redis().pools()
}

/// Starts the idle sweeper. Called once the runtime exists (a spawn needs one),
/// not from the registry's constructor.
pub fn start_sweeper() {
    static STARTED: OnceLock<()> = OnceLock::new();

    STARTED.get_or_init(|| {
        tokio::spawn(async {
            let mut ticker = tokio::time::interval(POOL_SWEEP_INTERVAL);

            loop {
                ticker.tick().await;

                get().sweep();
            }
        });
    });
}

impl Pools {
    /// Returns one of the pool's multiplexed connections, opening the pool on
    /// first use, and marks it held. Like sql's pools this does not connect:
    /// the manager is lazy and the first command dials under its own deadline.
    pub fn acquire(
        &'static self,
        dsn: &str,
        pool_size: i64,
        conn_max_lifetime_ms: i64,
    ) -> Result<Acquired, String> {
        let key = PoolKey {
            dsn: dsn.to_string(),
            pool_size: normalize_pool_size(pool_size),
            conn_max_lifetime_ms,
        };

        let mut entries = self.entries.lock().unwrap();

        // A lifetime cap is honoured by dropping the pool and opening a new one:
        // the manager holds the socket, so retiring one connection means
        // replacing the handle that owns it.
        if let Some(entry) = entries.get(&key) {
            if is_expired(entry, conn_max_lifetime_ms) && entry.in_use == 0 {
                entries.remove(&key);
            }
        }

        if !entries.contains_key(&key) {
            let entry = build(&key)?;

            entries.insert(key.clone(), entry);
        }

        let entry = entries.get_mut(&key).expect("the entry was just inserted");

        let connection = entry.connections[entry.next_index % entry.connections.len()].clone();

        entry.next_index = entry.next_index.wrapping_add(1);
        entry.in_use += 1;
        entry.last_used_at = Instant::now();

        Ok(Acquired {
            key,
            connection,
        })
    }

    /// Opens a connection nothing else will use: one blocking command, one
    /// subscription. It is dialled here and dropped by its caller, never
    /// entering the pool — a socket parked inside BLPOP is not a socket
    /// anything else can borrow.
    pub async fn dedicated(&'static self, dsn: &str) -> Result<MultiplexedConnection, String> {
        let client = self.client(dsn)?;

        let config = AsyncConnectionConfig::new()
            .set_response_timeout(RESPONSE_TIMEOUT)
            .set_connection_timeout(Some(CONNECT_TIMEOUT));

        client
            .get_multiplexed_async_connection_with_config(&config)
            .await
            .map_err(|error| format!("connect: {error}"))
    }

    /// The client for a DSN, which is what opens a dedicated connection or a
    /// subscription. Cheap to build — it holds the parsed connection info, not
    /// a socket — so it is not cached.
    pub fn client(&'static self, dsn: &str) -> Result<Client, String> {
        let parsed = dsn::parse(dsn)?;

        Client::open(parsed.url.as_str()).map_err(|error| format!("open client: {error}"))
    }

    fn release(&self, key: &PoolKey) {
        if let Some(entry) = self.entries.lock().unwrap().get_mut(key) {
            if entry.in_use > 0 {
                entry.in_use -= 1;
            }

            entry.last_used_at = Instant::now();
        }
    }

    /// Removes idle, unreferenced pools. The removed managers close their
    /// sockets in their own Drop once the last clone goes, so nothing is closed
    /// under the lock.
    fn sweep(&self) {
        let now = Instant::now();

        self.entries
            .lock()
            .unwrap()
            .retain(|_, entry| entry.in_use > 0 || now.duration_since(entry.last_used_at) <= POOL_IDLE_TTL);
    }

    /// Called on extension shutdown.
    pub fn close_all(&self) {
        let drained: Vec<Entry> = self
            .entries
            .lock()
            .unwrap()
            .drain()
            .map(|(_, entry)| entry)
            .collect();

        drop(drained);
    }
}

fn normalize_pool_size(pool_size: i64) -> usize {
    if pool_size <= 0 {
        return DEFAULT_POOL_SIZE;
    }

    (pool_size as usize).min(MAX_POOL_SIZE)
}

fn is_expired(entry: &Entry, conn_max_lifetime_ms: i64) -> bool {
    if conn_max_lifetime_ms <= 0 {
        return false;
    }

    entry.opened_at.elapsed() >= Duration::from_millis(conn_max_lifetime_ms as u64)
}

fn build(key: &PoolKey) -> Result<Entry, String> {
    let parsed = dsn::parse(&key.dsn)?;

    let client = Client::open(parsed.url.as_str()).map_err(|error| format!("open client: {error}"))?;

    let mut connections = Vec::with_capacity(key.pool_size);

    for _ in 0..key.pool_size {
        // Lazy, so building the pool does no network work: the first command
        // through it dials, and does so under its own deadline. The retry
        // numbers are the manager's own defaults; what matters here is that a
        // dropped connection is re-established behind the commands rather than
        // ending the pool.
        let config = ConnectionManagerConfig::new()
            .set_response_timeout(RESPONSE_TIMEOUT)
            .set_connection_timeout(Some(CONNECT_TIMEOUT));

        let connection = client
            .get_connection_manager_lazy(config)
            .map_err(|error| format!("open connection: {error}"))?;

        connections.push(connection);
    }

    Ok(Entry {
        connections,
        next_index: 0,
        opened_at: Instant::now(),
        in_use: 0,
        last_used_at: Instant::now(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_missing_pool_size_falls_back_to_the_default() {
        assert_eq!(normalize_pool_size(0), DEFAULT_POOL_SIZE);
        assert_eq!(normalize_pool_size(-3), DEFAULT_POOL_SIZE);
    }

    #[test]
    fn a_pool_size_is_capped() {
        assert_eq!(normalize_pool_size(2), 2);
        assert_eq!(normalize_pool_size(10_000), MAX_POOL_SIZE);
    }

    #[test]
    fn a_pool_without_a_lifetime_never_expires() {
        let entry = Entry {
            connections: Vec::new(),
            next_index: 0,
            opened_at: Instant::now() - Duration::from_secs(3600),
            in_use: 0,
            last_used_at: Instant::now(),
        };

        assert!(!is_expired(&entry, 0));
        assert!(is_expired(&entry, 1000));
    }
}

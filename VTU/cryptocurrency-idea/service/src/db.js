import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const SCHEMA = `
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;

CREATE TABLE IF NOT EXISTS quotes (
  id TEXT PRIMARY KEY,
  side TEXT NOT NULL,
  asset TEXT NOT NULL,
  crypto_base TEXT NOT NULL,
  fiat_minor TEXT NOT NULL,
  rate TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  used INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  side TEXT NOT NULL,                 -- buy | sell
  asset TEXT NOT NULL,
  status TEXT NOT NULL,
  client_ref TEXT NOT NULL UNIQUE,    -- idempotency key from the PHP site
  user_ref TEXT NOT NULL,             -- site user id
  quote_id TEXT NOT NULL,
  crypto_base TEXT NOT NULL,          -- expected crypto amount (base units)
  received_base TEXT,                 -- sell: amount actually received
  fiat_minor TEXT NOT NULL,           -- fiat in minor units (kobo)
  rate TEXT NOT NULL,
  destination TEXT,                   -- buy: user's wallet address
  deposit_index INTEGER UNIQUE,       -- sell: HD index of deposit address
  deposit_address TEXT,
  signed_tx TEXT,
  tx_hash TEXT,
  tx_meta TEXT,                       -- nonce / expiry / inputs, per network
  broadcast_at INTEGER,
  first_seen_at INTEGER,
  expires_at INTEGER,
  last_checked_at INTEGER,
  sweep_status TEXT,                  -- pending | gas_sent | done
  sweep_tx TEXT,
  sweep_updated_at INTEGER,
  refunded INTEGER NOT NULL DEFAULT 0,
  error TEXT,
  note TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS orders_sweep ON orders(sweep_status);

CREATE TABLE IF NOT EXISTS manual_rates (
  asset TEXT PRIMARY KEY,
  buy_rate TEXT NOT NULL,
  sell_rate TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL,
  order_id TEXT NOT NULL,
  payload TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  next_at INTEGER NOT NULL,
  delivered_at INTEGER,
  last_error TEXT,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS counters (
  name TEXT PRIMARY KEY,
  value INTEGER NOT NULL
);
`;

export function openDb(path) {
  if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true });
  const db = new DatabaseSync(path);
  db.exec(SCHEMA);
  try { db.exec('ALTER TABLE orders ADD COLUMN tx_meta TEXT'); } catch { /* already there */ }
  return db;
}

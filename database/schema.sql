CREATE TABLE IF NOT EXISTS app_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    quote_refresh_seconds INTEGER NOT NULL DEFAULT 15,
    quote_refresh_only_trading_hours INTEGER NOT NULL DEFAULT 1,
    calculator_default_lot_size INTEGER NOT NULL DEFAULT 1,
    position_alert_gain_percent REAL NOT NULL DEFAULT 5,
    position_alert_loss_percent REAL NOT NULL DEFAULT 5,
    login_password_hash TEXT NOT NULL DEFAULT '',
    login_force_password_change INTEGER NOT NULL DEFAULT 1,
    login_password_updated_at TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS watchlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    side TEXT NOT NULL CHECK(side IN ('buy', 'sell')),
    price REAL NOT NULL,
    quantity INTEGER NOT NULL,
    fee REAL NOT NULL DEFAULT 0,
    trade_date TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_trades_symbol ON trades(symbol);
CREATE INDEX IF NOT EXISTS idx_trades_date ON trades(trade_date);

CREATE TABLE IF NOT EXISTS positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL DEFAULT '',
    quantity INTEGER NOT NULL,
    cost_price REAL NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_positions_symbol ON positions(symbol);

CREATE TABLE IF NOT EXISTS t_trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    first_side TEXT,
    first_price REAL,
    first_qty INTEGER,
    first_date TEXT,
    second_side TEXT,
    second_price REAL,
    second_qty INTEGER,
    second_date TEXT,
    status TEXT NOT NULL DEFAULT 'open',
    profit REAL NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_t_trades_symbol ON t_trades(symbol);
CREATE INDEX IF NOT EXISTS idx_t_trades_date ON t_trades(first_date);

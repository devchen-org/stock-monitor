<?php

declare(strict_types=1);

function ensureStorageDirectory(string $dbPath): void
{
    $directory = dirname($dbPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

function createConnection(array $config): PDO
{
    ensureStorageDirectory($config['db_path']);

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function initializeDatabase(PDO $pdo, array $config): void
{
    $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='trades'");
    $exists = $statement->fetchColumn();
    if (!$exists) {
        $schema = file_get_contents($config['schema_path']);
        if ($schema === false) {
            throw new RuntimeException('无法读取数据库初始化脚本');
        }
        $pdo->exec($schema);
    }

    migrateDatabase($pdo, $config);
}

function migrateDatabase(PDO $pdo, array $config): void
{
    ensureAppSettingsTable($pdo);
    ensureAppSettingsColumns($pdo);
    ensureDefaultAppSettings($pdo, $config);
    ensurePositionsTable($pdo);

    $columns = tableColumns($pdo, 't_trades');
    if ($columns === []) {
        return;
    }

    $addColumns = [
        'first_side' => "ALTER TABLE t_trades ADD COLUMN first_side TEXT",
        'first_price' => "ALTER TABLE t_trades ADD COLUMN first_price REAL",
        'first_qty' => "ALTER TABLE t_trades ADD COLUMN first_qty INTEGER",
        'first_date' => "ALTER TABLE t_trades ADD COLUMN first_date TEXT",
        'second_side' => "ALTER TABLE t_trades ADD COLUMN second_side TEXT",
        'second_price' => "ALTER TABLE t_trades ADD COLUMN second_price REAL",
        'second_qty' => "ALTER TABLE t_trades ADD COLUMN second_qty INTEGER",
        'second_date' => "ALTER TABLE t_trades ADD COLUMN second_date TEXT",
        'status' => "ALTER TABLE t_trades ADD COLUMN status TEXT NOT NULL DEFAULT 'open'",
        'alert_profit_gain' => "ALTER TABLE t_trades ADD COLUMN alert_profit_gain REAL",
        'alert_profit_loss' => "ALTER TABLE t_trades ADD COLUMN alert_profit_loss REAL",
        'updated_at' => "ALTER TABLE t_trades ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''",
    ];

    foreach ($addColumns as $name => $sql) {
        if (!in_array($name, $columns, true)) {
            $pdo->exec($sql);
        }
    }

    $legacyColumns = tableColumns($pdo, 't_trades');
    if (in_array('buy_price', $legacyColumns, true) && in_array('sell_price', $legacyColumns, true)) {
        $pdo->exec("UPDATE t_trades
            SET first_side = COALESCE(first_side, 'buy'),
                first_price = COALESCE(first_price, buy_price),
                first_qty = COALESCE(first_qty, buy_qty),
                first_date = COALESCE(first_date, trade_date),
                second_side = COALESCE(second_side, 'sell'),
                second_price = COALESCE(second_price, sell_price),
                second_qty = COALESCE(second_qty, sell_qty),
                second_date = COALESCE(second_date, trade_date),
                status = CASE WHEN sell_price > 0 THEN 'closed' ELSE status END,
                updated_at = CASE WHEN updated_at = '' THEN created_at ELSE updated_at END
            WHERE first_side IS NULL AND buy_price > 0");
    }
}

function ensureAppSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        quote_refresh_seconds INTEGER NOT NULL DEFAULT 15,
        quote_refresh_only_trading_hours INTEGER NOT NULL DEFAULT 1,
        calculator_default_lot_size INTEGER NOT NULL DEFAULT 1,
        position_alert_gain_percent REAL NOT NULL DEFAULT 5,
        position_alert_loss_percent REAL NOT NULL DEFAULT 5,
        webhook_channel TEXT NOT NULL DEFAULT '',
        webhook_url TEXT NOT NULL DEFAULT '',
        login_password_hash TEXT NOT NULL DEFAULT '',
        login_force_password_change INTEGER NOT NULL DEFAULT 1,
        login_password_updated_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}

function ensureAppSettingsColumns(PDO $pdo): void
{
    $columns = tableColumns($pdo, 'app_settings');
    $addColumns = [
        'calculator_default_lot_size' => "ALTER TABLE app_settings ADD COLUMN calculator_default_lot_size INTEGER NOT NULL DEFAULT 1",
        'position_alert_gain_percent' => "ALTER TABLE app_settings ADD COLUMN position_alert_gain_percent REAL NOT NULL DEFAULT 5",
        'position_alert_loss_percent' => "ALTER TABLE app_settings ADD COLUMN position_alert_loss_percent REAL NOT NULL DEFAULT 5",
        'webhook_channel' => "ALTER TABLE app_settings ADD COLUMN webhook_channel TEXT NOT NULL DEFAULT ''",
        'webhook_url' => "ALTER TABLE app_settings ADD COLUMN webhook_url TEXT NOT NULL DEFAULT ''",
        'login_password_hash' => "ALTER TABLE app_settings ADD COLUMN login_password_hash TEXT NOT NULL DEFAULT ''",
        'login_force_password_change' => "ALTER TABLE app_settings ADD COLUMN login_force_password_change INTEGER NOT NULL DEFAULT 1",
        'login_password_updated_at' => "ALTER TABLE app_settings ADD COLUMN login_password_updated_at TEXT NOT NULL DEFAULT ''",
    ];

    foreach ($addColumns as $name => $sql) {
        if (!in_array($name, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function ensureDefaultAppSettings(PDO $pdo, array $config): void
{
    $statement = $pdo->query('SELECT COUNT(*) FROM app_settings');
    $count = (int) $statement->fetchColumn();
    if ($count > 0) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO app_settings (
            id,
            quote_refresh_seconds,
            quote_refresh_only_trading_hours,
            calculator_default_lot_size,
            position_alert_gain_percent,
            position_alert_loss_percent,
            webhook_channel,
            webhook_url,
            login_password_hash,
            login_force_password_change,
            login_password_updated_at,
            updated_at
        ) VALUES (
            1,
            :quote_refresh_seconds,
            :quote_refresh_only_trading_hours,
            :calculator_default_lot_size,
            :position_alert_gain_percent,
            :position_alert_loss_percent,
            :webhook_channel,
            :webhook_url,
            :login_password_hash,
            :login_force_password_change,
            :login_password_updated_at,
            :updated_at
        )'
    );
    $statement->execute([
        ':quote_refresh_seconds' => (int) $config['quote_refresh_seconds'],
        ':quote_refresh_only_trading_hours' => !empty($config['quote_refresh_only_trading_hours']) ? 1 : 0,
        ':calculator_default_lot_size' => 1,
        ':position_alert_gain_percent' => 5,
        ':position_alert_loss_percent' => 5,
        ':webhook_channel' => '',
        ':webhook_url' => '',
        ':login_password_hash' => '',
        ':login_force_password_change' => 1,
        ':login_password_updated_at' => '',
        ':updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function ensurePositionsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS positions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        symbol TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL DEFAULT '',
        quantity INTEGER NOT NULL,
        cost_price REAL NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}

function tableColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->query("PRAGMA table_info($table)");
    $rows = $statement->fetchAll();
    return array_column($rows, 'name');
}

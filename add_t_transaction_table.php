<?php
date_default_timezone_set('Asia/Shanghai');

// 数据库文件路径
$dbFile = __DIR__ . '/stock_monitor.db';

// 连接SQLite数据库
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 创建做T交易记录表
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS t_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stock_code TEXT NOT NULL,
        stock_name TEXT,
        buy_price REAL NOT NULL,
        sell_price REAL NOT NULL,
        shares INTEGER NOT NULL,
        buy_time DATETIME NOT NULL,
        sell_time DATETIME NOT NULL,
        profit REAL NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    echo "做T交易记录表创建成功！\n";
} catch (Exception $e) {
    echo "创建表失败: " . $e->getMessage() . "\n";
}

// 显示表结构
try {
    $stmt = $pdo->query('PRAGMA table_info(t_transactions)');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n表结构:\n";
    foreach ($columns as $column) {
        echo "{$column['name']} ({$column['type']})" . ($column['notnull'] ? ' NOT NULL' : '') . "\n";
    }
} catch (Exception $e) {
    echo "查询表结构失败: " . $e->getMessage() . "\n";
}
?>
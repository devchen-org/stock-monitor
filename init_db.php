<?php
date_default_timezone_set('Asia/Shanghai');

// 数据库文件路径
$dbFile = __DIR__ . '/stock_monitor.db';

// 连接SQLite数据库
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 创建持仓表
$pdo->exec('CREATE TABLE IF NOT EXISTS holdings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    name TEXT,
    shares REAL NOT NULL,
    cost REAL NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 创建配置表
$pdo->exec('CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL UNIQUE,
    value TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 创建股票历史数据表
$pdo->exec('CREATE TABLE IF NOT EXISTS stock_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    name TEXT,
    price REAL NOT NULL,
    change REAL,
    change_percent REAL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 插入默认配置
$defaultSettings = [
    ['key' => 'api_type', 'value' => 'sina'],
    ['key' => 'refresh_interval', 'value' => '5'],
    ['key' => 'trading_time', 'value' => 'false'],
    ['key' => 'wechat_webhook', 'value' => ''],
    ['key' => 'buy_lots', 'value' => '1'],
    ['key' => 'sell_lots', 'value' => '1']
];

foreach ($defaultSettings as $setting) {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    $stmt->execute([$setting['key'], $setting['value']]);
}

// 从配置文件导入持仓数据
$configFile = __DIR__ . '/stocks_config.txt';
if (file_exists($configFile)) {
    $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line);
            if (count($parts) === 3) {
                list($code, $shares, $cost) = $parts;
                $name = '';
            } elseif (count($parts) === 4) {
                list($code, $name, $shares, $cost) = $parts;
            } else {
                continue;
            }
            
            // 检查是否已存在
            $stmt = $pdo->prepare('SELECT id FROM holdings WHERE code = ?');
            $stmt->execute([trim($code)]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO holdings (code, name, shares, cost) VALUES (?, ?, ?, ?)');
                $stmt->execute([trim($code), $name, (float)$shares, (float)$cost]);
            }
        }
    }
}

echo "数据库初始化完成！\n";
echo "数据库文件: " . $dbFile . "\n";

// 显示持仓数据
$stmt = $pdo->query('SELECT * FROM holdings');
$holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n持仓数据:\n";
foreach ($holdings as $holding) {
    echo "代码: {$holding['code']}, 名称: {$holding['name']}, 持仓: {$holding['shares']}, 成本价: {$holding['cost']}\n";
}

// 显示配置数据
$stmt = $pdo->query('SELECT * FROM settings');
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n配置数据:\n";
foreach ($settings as $setting) {
    echo "{$setting['key']}: {$setting['value']}\n";
}
?>
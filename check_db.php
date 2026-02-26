<?php
date_default_timezone_set('Asia/Shanghai');

// 连接到SQLite数据库
$dbFile = 'stock_monitor.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 检查t_transactions表结构
$stmt = $pdo->query("PRAGMA table_info(t_transactions)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "t_transactions表结构：\n";
foreach ($columns as $column) {
    echo "{$column['name']} ({$column['type']})\n";
}

// 检查是否存在未完成的做T交易记录
$stmt = $pdo->query("SELECT * FROM t_transactions WHERE status = 'pending' ORDER BY created_at DESC");
$pendingTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n未完成的做T交易记录：\n";
if (empty($pendingTransactions)) {
    echo "没有未完成的做T交易记录\n";
} else {
    foreach ($pendingTransactions as $transaction) {
        echo "ID: {$transaction['id']}, 股票: {$transaction['stock_code']} - {$transaction['stock_name']}, 状态: {$transaction['status']}\n";
    }
}
?>
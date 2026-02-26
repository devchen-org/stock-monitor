<?php
date_default_timezone_set('Asia/Shanghai');

// 数据库文件路径
$dbFile = __DIR__ . '/stock_monitor.db';

// 连接SQLite数据库
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 修改做T交易记录表，添加状态字段
try {
    // 首先检查表是否存在
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='t_transactions'");
    if ($stmt->fetch()) {
        // 检查状态字段是否存在
        $stmt = $pdo->query("PRAGMA table_info(t_transactions)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasStatusColumn = false;
        foreach ($columns as $column) {
            if ($column['name'] == 'status') {
                $hasStatusColumn = true;
                break;
            }
        }
        
        if (!$hasStatusColumn) {
            // 添加状态字段
            $pdo->exec("ALTER TABLE t_transactions ADD COLUMN status TEXT DEFAULT 'completed'");
            echo "添加状态字段成功！\n";
        } else {
            echo "状态字段已存在，无需添加！\n";
        }
    } else {
        echo "表不存在，需要先创建表！\n";
    }
} catch (Exception $e) {
    echo "修改表结构失败: " . $e->getMessage() . "\n";
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
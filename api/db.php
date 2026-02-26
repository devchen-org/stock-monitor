<?php
date_default_timezone_set('Asia/Shanghai');
class Database {
    private static $pdo;
    
    public static function getConnection() {
        if (!self::$pdo) {
            $dbFile = __DIR__ . '/../stock_monitor.db';
            self::$pdo = new PDO('sqlite:' . $dbFile);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }
    
    // 获取所有持仓
    public static function getHoldings() {
        $stmt = self::getConnection()->query('SELECT * FROM holdings ORDER BY code');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取单个持仓
    public static function getHolding($id) {
        $stmt = self::getConnection()->prepare('SELECT * FROM holdings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 添加持仓
    public static function addHolding($code, $name, $shares, $cost) {
        $stmt = self::getConnection()->prepare('INSERT INTO holdings (code, name, shares, cost) VALUES (?, ?, ?, ?)');
        $stmt->execute([$code, $name, $shares, $cost]);
        return self::getConnection()->lastInsertId();
    }
    
    // 更新持仓
    public static function updateHolding($id, $shares, $cost) {
        $stmt = self::getConnection()->prepare('UPDATE holdings SET shares = ?, cost = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        return $stmt->execute([$shares, $cost, $id]);
    }
    
    // 删除持仓
    public static function deleteHolding($id) {
        $stmt = self::getConnection()->prepare('DELETE FROM holdings WHERE id = ?');
        return $stmt->execute([$id]);
    }
    
    // 获取所有配置
    public static function getSettings() {
        $stmt = self::getConnection()->query('SELECT * FROM settings');
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['key']] = $setting['value'];
        }
        return $result;
    }
    
    // 获取单个配置
    public static function getSetting($key) {
        $stmt = self::getConnection()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['value'] : null;
    }
    
    // 更新配置
    public static function updateSetting($key, $value) {
        $stmt = self::getConnection()->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        return $stmt->execute([$key, $value]);
    }
    
    // 保存股票历史数据
    public static function saveStockHistory($code, $name, $price, $change, $changePercent) {
        $stmt = self::getConnection()->prepare('INSERT INTO stock_history (code, name, price, change, change_percent) VALUES (?, ?, ?, ?, ?)');
        return $stmt->execute([$code, $name, $price, $change, $changePercent]);
    }
    
    // 获取股票历史数据
    public static function getStockHistory($code, $limit = 10) {
        $stmt = self::getConnection()->prepare('SELECT * FROM stock_history WHERE code = ? ORDER BY timestamp DESC LIMIT ?');
        $stmt->execute([$code, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 添加做T交易记录
    public static function addTTransaction($stockCode, $stockName, $buyPrice, $sellPrice, $shares, $buyTime, $sellTime, $profit, $status = 'completed') {
        $stmt = self::getConnection()->prepare('INSERT INTO t_transactions (stock_code, stock_name, buy_price, sell_price, shares, buy_time, sell_time, profit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        return $stmt->execute([$stockCode, $stockName, $buyPrice, $sellPrice, $shares, $buyTime, $sellTime, $profit, $status]);
    }
    
    // 更新做T交易记录
    public static function updateTTransaction($id, $data) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $setClauses[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        
        $stmt = self::getConnection()->prepare('UPDATE t_transactions SET ' . implode(', ', $setClauses) . ' WHERE id = ?');
        return $stmt->execute($params);
    }
    
    // 获取未完成的做T交易记录
    public static function getPendingTTransactions() {
        $stmt = self::getConnection()->query('SELECT * FROM t_transactions WHERE status = "pending" ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取做T交易记录
    public static function getTTransactions($stockCode = null) {
        if ($stockCode) {
            $stmt = self::getConnection()->prepare('SELECT * FROM t_transactions WHERE stock_code = ? ORDER BY created_at DESC');
            $stmt->execute([$stockCode]);
        } else {
            $stmt = self::getConnection()->query('SELECT * FROM t_transactions ORDER BY created_at DESC');
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 删除做T交易记录
    public static function deleteTTransaction($id) {
        $stmt = self::getConnection()->prepare('DELETE FROM t_transactions WHERE id = ?');
        return $stmt->execute([$id]);
    }
    
    // 获取做T交易统计
    public static function getTTransactionStats($stockCode = null) {
        if ($stockCode) {
            $stmt = self::getConnection()->prepare('SELECT COUNT(*) as count, SUM(profit) as total_profit FROM t_transactions WHERE stock_code = ?');
            $stmt->execute([$stockCode]);
        } else {
            $stmt = self::getConnection()->query('SELECT COUNT(*) as count, SUM(profit) as total_profit FROM t_transactions');
        }
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
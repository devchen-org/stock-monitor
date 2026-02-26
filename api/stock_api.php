<?php
date_default_timezone_set('Asia/Shanghai');
class StockAPI {
    private const API_URLS = [
        'sina' => 'http://hq.sinajs.cn/list=',
        'tencent' => 'http://qt.gtimg.cn/q=',
    ];
    
    // 获取股票价格
    public static function getStockPrices($codes, $apiType = 'sina') {
        if ($apiType === 'tencent') {
            return self::getTencentStockPrices($codes);
        } else {
            return self::getSinaStockPrices($codes);
        }
    }
    
    // 从新浪接口获取股票数据
    private static function getSinaStockPrices($codes) {
        $results = [];
        $url = self::API_URLS['sina'] . implode(',', $codes);
        
        $response = @file_get_contents($url);
        if ($response === false) {
            return $results;
        }
        
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            if (preg_match('/var hq_str_(\w+)="(.*)";/', $line, $matches)) {
                $code = $matches[1];
                $dataStr = $matches[2];
                $fields = explode(',', $dataStr);
                
                if (count($fields) >= 32 && !empty($fields[0])) {
                    // 计算涨跌额和涨跌幅
                    $now = (float) $fields[3];
                    $yesterday = (float) $fields[2];
                    $change = $now - $yesterday;
                    $changePercent = $yesterday > 0 ? round($change / $yesterday * 100, 2) : 0;
                    
                    $results[$code] = [
                        'name' => $fields[0],
                        'now' => $now,
                        'change' => $change,
                        'changePercent' => $changePercent,
                        'high' => (float) $fields[4],
                        'low' => (float) $fields[5],
                        'time' => $fields[31],
                    ];
                }
            }
        }
        
        return $results;
    }
    
    // 从腾讯接口获取股票数据
    private static function getTencentStockPrices($codes) {
        $results = [];
        $url = self::API_URLS['tencent'] . implode(',', $codes);
        
        $response = @file_get_contents($url);
        if ($response === false) {
            return $results;
        }
        
        $response = mb_convert_encoding($response, "UTF-8", "GBK");
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            if (preg_match('/v_(\w+)="(.*)"/', $line, $matches)) {
                $code = $matches[1];
                $dataStr = $matches[2];
                $fields = explode('~', $dataStr);
                
                if (count($fields) >= 35 && !empty($fields[1])) {
                    $results[$code] = [
                        'name' => $fields[1],
                        'now' => (float) $fields[3],
                        'change' => (float) $fields[31],
                        'changePercent' => (float) $fields[32],
                        'high' => (float) $fields[33],
                        'low' => (float) $fields[34],
                        'time' => $fields[30],
                    ];
                }
            }
        }
        
        return $results;
    }
    
    // 计算盈亏
    public static function calculateProfit($holding, $currentPrice) {
        $marketValue = round($holding['shares'] * $currentPrice, 3);
        $costValue = round($holding['shares'] * $holding['cost'], 3);
        $profit = round($marketValue - $costValue, 3);
        $profitRate = $costValue > 0 ? round($profit / $costValue * 100, 3) : 0;
        
        return [$marketValue, $profit, $profitRate];
    }
    
    // 计算买入后的成本价
    public static function calculateNewCostAfterBuy($holding, $currentPrice, $lots) {
        $sharesToBuy = $lots * 100;
        $currentCostValue = $holding['shares'] * $holding['cost'];
        $newShares = $holding['shares'] + $sharesToBuy;
        $newCostValue = $currentCostValue + ($sharesToBuy * $currentPrice);
        $newCost = $newShares > 0 ? $newCostValue / $newShares : 0;
        
        return round($newCost, 3);
    }
    
    // 计算卖出后的成本价
    public static function calculateNewCostAfterSell($holding, $currentPrice, $lots) {
        $sharesToSell = $lots * 100;
        $newShares = max(0, $holding['shares'] - $sharesToSell);
        
        if ($newShares <= 0) {
            return 0;
        }
        
        $currentCostValue = $holding['shares'] * $holding['cost'];
        $newCost = $currentCostValue / $newShares;
        
        return round($newCost, 3);
    }
    
    // 检查是否为交易时间
    public static function isTradingTime() {
        $now = time();
        $dayOfWeek = (int) date('N', $now);
        $hour = (int) date('H', $now);
        $minute = (int) date('i', $now);
        $timeValue = $hour * 60 + $minute;
        
        if ($dayOfWeek >= 6) {
            return false;
        }
        
        $morningStart = 9 * 60 + 30;
        $morningEnd = 11 * 60 + 30;
        $afternoonStart = 13 * 60;
        $afternoonEnd = 15 * 60;
        
        return ($timeValue >= $morningStart && $timeValue <= $morningEnd) ||
               ($timeValue >= $afternoonStart && $timeValue <= $afternoonEnd);
    }
}
?>
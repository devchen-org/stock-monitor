#!/usr/bin/env php
<?php
date_default_timezone_set('Asia/Shanghai');

class StockMonitor
{
    private const API_URLS = [
        'sina' => 'http://hq.sinajs.cn/list=',
        'tencent' => 'http://qt.gtimg.cn/q=',
    ];

    private const COLORS = [
        'reset' => "\033[0m",
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
    ];

    private $holdings = [];
    private $configFile;
    private $apiType = 'sina';
    private $refreshInterval = 5;
    private $onlyTradingTime = false;
    private $wechatWebhook = '';
    private $formatCache = [];
    private $strWidthCache = [];
    private $buyLots = 1;
    private $sellLots = 1;

    public function __construct($configFile, $apiType = 'sina')
    {
        $this->configFile = $configFile;
        $this->apiType = $apiType;
        $this->loadConfig();
    }

    private function loadConfig()
    {
        if (!file_exists($this->configFile)) {
            $this->error("配置文件不存在\n");
        }

        $lines = file($this->configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $newHoldings = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (in_array(strtolower($line), ['sina', 'tencent'])) {
                $this->apiType = strtolower($line);
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = array_map('trim', explode('=', $line, 2));
                switch (strtolower($key)) {
                    case 'interval':
                        $this->refreshInterval = (int) $value;
                        break;
                    case 'trading_time':
                        $this->onlyTradingTime = strtolower($value) === 'true' || $value === '1';
                        break;
                    case 'timezone':
                        date_default_timezone_set($value);
                        break;
                    case 'wechat_webhook':
                        $this->wechatWebhook = $value;
                        break;
                    case 'buy_lots':
                        $this->buyLots = max(1, (int) $value);
                        break;
                    case 'sell_lots':
                        $this->sellLots = max(1, (int) $value);
                        break;
                }
                continue;
            }

            $parts = explode('|', $line);
            if (count($parts) === 3) {
                list($code, $shares, $cost) = $parts;
                $newHoldings[] = [
                    'code' => trim($code),
                    'shares' => (float) $shares,
                    'cost' => (float) $cost,
                ];
            } elseif (count($parts) === 4) {
                list($code, $name, $shares, $cost) = $parts;
                $newHoldings[] = [
                    'code' => trim($code),
                    'shares' => (float) $shares,
                    'cost' => (float) $cost,
                ];
            }
        }

        if (empty($newHoldings)) {
            $this->error('配置文件中没有有效的股票数据');
        }

        $this->holdings = $newHoldings;
        unset($newHoldings, $lines);
        
        if (count($this->formatCache) > 1000) {
            $this->formatCache = [];
        }
        
        if (count($this->strWidthCache) > 500) {
            $this->strWidthCache = [];
        }
    }

    private function isTradingTime()
    {
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

    private function getSinaStockPrices($codes)
    {
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
                    
                    bcscale(3);
                    $change = (float) bcsub((string)$now, (string)$yesterday, 3);
                    $changePercent = $yesterday > 0 ? (float) bcdiv(bcmul((string)$change, '100', 3), (string)$yesterday, 3) : 0;

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

    private function getTencentStockPrices($codes)
    {
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

    private function getStockPrices($codes)
    {
        if ($this->apiType === 'tencent') {
            return $this->getTencentStockPrices($codes);
        } else {
            return $this->getSinaStockPrices($codes);
        }
    }

    private function strWidth($str)
    {
        if (isset($this->strWidthCache[$str])) {
            return $this->strWidthCache[$str];
        }
        
        $width = 0;
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $char)) {
                $width += 2;
            } else {
                $width += 1;
            }
        }
        
        $this->strWidthCache[$str] = $width;
        return $width;
    }

    private function padRight($str, $width)
    {
        $currentWidth = $this->strWidth($str);
        if ($currentWidth >= $width) {
            return $str;
        }
        return $str . str_repeat(' ', $width - $currentWidth);
    }

    private function clearScreen()
    {
        echo "\033[H\033[J";
    }

    private function color($text, $colorName)
    {
        return self::COLORS[$colorName] . $text . self::COLORS['reset'];
    }

    private function formatNumber($num, $decimal = 3)
    {
        $key = $num . '_' . $decimal;
        if (!isset($this->formatCache[$key])) {
            $this->formatCache[$key] = number_format($num, $decimal, '.', ',');
        }
        return $this->formatCache[$key];
    }

    private function drawBorder($left, $middle, $right, $widths)
    {
        $result = self::COLORS['gray'] . '+';
        $count = count($widths);
        foreach ($widths as $i => $width) {
            $result .= str_repeat('-', $width + 2);
            if ($i < $count - 1) {
                $result .= '+';
            }
        }
        $result .= "+\033[0m\n";
        echo $result;
    }

    private function drawRow($cells, $widths, $colorName = 'white')
    {
        $result = self::COLORS[$colorName] . '|';
        $count = count($cells);
        foreach ($cells as $i => $cell) {
            $result .= ' ' . $this->padRight($cell, $widths[$i]) . ' |';
        }
        $result .= "\033[0m\n";
        echo $result;
    }

    private function calculateProfit($holding, $currentPrice)
    {
        bcscale(3);
        
        $marketValue = (float) bcmul((string)$holding['shares'], (string)$currentPrice, 3);
        $costValue = (float) bcmul((string)$holding['shares'], (string)$holding['cost'], 3);
        $profit = (float) bcsub((string)$marketValue, (string)$costValue, 3);
        $profitRate = $costValue > 0 ? (float) bcdiv(bcmul((string)$profit, '100', 3), (string)$costValue, 3) : 0;

        return [$marketValue, $profit, $profitRate];
    }

    private function calculateNewCostAfterBuy($holding, $currentPrice, $lots)
    {
        bcscale(3);
        
        $sharesToBuy = $lots * 100;
        $currentCostValue = (float) bcmul((string)$holding['shares'], (string)$holding['cost'], 3);
        $newShares = (float) bcadd((string)$holding['shares'], (string)$sharesToBuy, 3);
        $newCostValue = (float) bcadd((string)$currentCostValue, (float) bcmul((string)$sharesToBuy, (string)$currentPrice, 3), 3);
        $newCost = $newShares > 0 ? (float) bcdiv((string)$newCostValue, (string)$newShares, 3) : 0;

        return $newCost;
    }

    private function calculateNewCostAfterSell($holding, $currentPrice, $lots)
    {
        bcscale(3);
        
        $sharesToSell = $lots * 100;
        $newShares = max(0, (float) bcsub((string)$holding['shares'], (string)$sharesToSell, 3));
        
        if ($newShares <= 0) {
            return 0;
        }
        
        $currentCostValue = (float) bcmul((string)$holding['shares'], (string)$holding['cost'], 3);
        $newCost = (float) bcdiv((string)$currentCostValue, (string)$newShares, 3);

        return $newCost;
    }

    private function countdownWait($seconds, $status = '')
    {
        $gray = self::COLORS['gray'];
        $reset = "\033[0m";
        $clearLine = str_repeat(' ', 100) . "\r";
        
        for ($i = $seconds; $i >= 1; $i--) {
            echo $gray . $status . " 下次刷新: {$i}秒" . $reset . "\r";
            flush();
            sleep(1);
        }
        echo $clearLine;
    }

    private function error($message)
    {
        echo $message . "\n";
        exit(1);
    }
    
    private function generateTableContent($sortedHoldings, $originalHeaders, $originalWidths)
    {
        $content = "股票持仓收益实时监控 - " . date('Y-m-d H:i:s') . "\n\n";
        
        // 只保留需要的字段：名称、涨跌、涨跌幅、现价、成本价
        $requiredFields = ['名称', '涨跌', '涨跌幅', '现价', '成本价'];
        $fieldIndices = [];
        
        // 获取需要的字段索引
        foreach ($requiredFields as $field) {
            $index = array_search($field, $originalHeaders);
            if ($index !== false) {
                $fieldIndices[] = $index;
            }
        }
        
        // 准备表头和宽度
        $headers = [];
        $widths = [];
        foreach ($fieldIndices as $index) {
            $headers[] = $originalHeaders[$index];
            $widths[] = $originalWidths[$index];
        }
        
        // 添加表头
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        $content .= "|";
        foreach ($headers as $i => $header) {
            $content .= " " . $this->padRight($header, $widths[$i]) . " |";
        }
        $content .= "\n";
        
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        // 添加数据行
        foreach ($sortedHoldings as $holding) {
            $info = $holding['stockInfo'];
            
            // 准备原始数据
            $originalCells = [
                    $holding['code'],
                    $info['name'],
                    ($info['change'] > 0 ? '+' : '') . $this->formatNumber($info['change']),
                    $this->formatNumber($info['changePercent']) . '%',
                    $this->formatNumber($info['high']),
                    $this->formatNumber($info['low']),
                    $this->formatNumber($info['now']),
                    $this->formatNumber($holding['cost']),
                    $this->formatNumber($this->calculateNewCostAfterBuy($holding, $info['now'], $this->buyLots)),
                    $this->formatNumber($this->calculateNewCostAfterSell($holding, $info['now'], $this->sellLots)),
                    $this->formatNumber($holding['shares'], 0),
                    $this->formatNumber($holding['profitInfo'][0]),
                    (($holding['profitInfo'][1] > 0) ? '+' : '') . $this->formatNumber($holding['profitInfo'][1]),
                    $this->formatNumber($holding['profitInfo'][2]) . '%',
            ];
            
            // 只保留需要的字段
            $cells = [];
            foreach ($fieldIndices as $index) {
                $cells[] = $originalCells[$index];
            }
            
            $content .= "|";
            foreach ($cells as $i => $cell) {
                $content .= " " . $this->padRight($cell, $widths[$i]) . " |";
            }
            $content .= "\n";
        }
        
        // 添加底部边框
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        return $content;
    }
    
    private function sendWechatMessage($content)
    {
        if (empty($this->wechatWebhook)) {
            return ['success' => false, 'message' => '企业微信webhook未配置，跳过发送'];
        }
        
        $data = [
            "msgtype" => "text",
            "text" => [
                "content" => $content
            ]
        ];
        
        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ],
        ];
        
        $context  = stream_context_create($options);
        $result = @file_get_contents($this->wechatWebhook, false, $context);
        
        if ($result === false) {
            return ['success' => false, 'message' => '企业微信消息发送失败'];
        } else {
            $response = json_decode($result, true);
            if (isset($response['errcode']) && $response['errcode'] === 0) {
                return ['success' => true, 'message' => '已发送表格到企业微信'];
            } else {
                $errorMsg = isset($response['errmsg']) ? $response['errmsg'] : '未知错误';
                return ['success' => false, 'message' => "企业微信消息发送失败: {$errorMsg}"];
            }
        }
    }

    public function run()
    {
        try {
            // 增加列宽以适应新增的列
            $widths = [12, 14, 8, 8, 8, 8, 8, 8, 10, 14, 14, 12, 14, 14];

            $apiName = $this->apiType === 'tencent' ? '腾讯' : '新浪';
            $tradingMode = $this->onlyTradingTime ? '(交易时间)' : '';
            $wechatResult = ['success' => false, 'message' => ''];
            $nonTradingMessage = '';

            while (true) {
                $this->clearScreen();

                $title = $this->color(" 股票持仓收益实时监控", 'cyan');
                $title .= $this->color(" ({$apiName}接口)", 'yellow');
                $title .= $this->color($tradingMode, 'gray');
                $title .= $this->color(" - " . date('Y-m-d H:i:s'), 'white');
                $title .= $this->color("  间隔: {$this->refreshInterval}秒 (Ctrl+C退出)", 'gray');
                echo "\n" . $title . "\n\n";

                $this->drawBorder('tl', 'mt', 'tr', $widths);

                // 添加新的表头
                $headers = ['代码', '名称', '涨跌', '涨跌幅', '最高价', '最低价', '现价', '成本价', "买{$this->buyLots}手后成本价", "卖{$this->sellLots}手后成本价", '持仓', '市值', '盈亏额', '盈亏率'];
                $this->drawRow($headers, $widths, 'cyan');

                $this->drawBorder('ml', 'mc', 'mr', $widths);

                $totalMarketValue = 0;
                $totalCost = 0;
                $rowCount = 0;
                $hasError = false;

                if (!$this->onlyTradingTime || $this->isTradingTime()) {
                    $codes = array_column($this->holdings, 'code');
                    $stockData = $this->getStockPrices($codes);

                    if (empty($stockData) && !empty($codes)) {
                        $hasError = true;
                    }

                    // 准备排序数据
                    $sortedHoldings = [];
                    foreach ($this->holdings as $holding) {
                        $code = $holding['code'];
                        if (isset($stockData[$code])) {
                            $info = $stockData[$code];
                            $holding['stockInfo'] = $info;
                            $holding['profitInfo'] = $this->calculateProfit($holding, $info['now']);
                            $sortedHoldings[] = $holding;
                        }
                    }
                    
                    // 按涨跌幅排序
                    usort($sortedHoldings, function($a, $b) {
                        return $b['stockInfo']['changePercent'] <=> $a['stockInfo']['changePercent'];
                    });

                    // 输出排序后的持仓数据
                    foreach ($sortedHoldings as $holding) {
                        $code = $holding['code'];
                        $info = $holding['stockInfo'];
                        list($marketValue, $profit, $profitRate) = $holding['profitInfo'];

                        $totalMarketValue += $marketValue;
                        $totalCost += $holding['shares'] * $holding['cost'];
                        $rowCount++;

                        $rowColor = $info['changePercent'] < 0 ? 'green' : ($info['changePercent'] > 0 ? 'red' : 'white');
                        $profitSign = $profit > 0 ? '+' : '';
                        $changeSign = $info['change'] > 0 ? '+' : '';

                        $cells = [
                                $holding['code'],
                                $info['name'],
                                $changeSign . $this->formatNumber($info['change']),
                                $this->formatNumber($info['changePercent']) . '%',
                                $this->formatNumber($info['high']),
                                $this->formatNumber($info['low']),
                                $this->formatNumber($info['now']),
                                $this->formatNumber($holding['cost']),
                                $this->formatNumber($this->calculateNewCostAfterBuy($holding, $info['now'], $this->buyLots)),
                                $this->formatNumber($this->calculateNewCostAfterSell($holding, $info['now'], $this->sellLots)),
                                $this->formatNumber($holding['shares'], 0),
                                $this->formatNumber($marketValue),
                                $profitSign . $this->formatNumber($profit),
                                $this->formatNumber($profitRate) . '%',
                        ];
                        $this->drawRow($cells, $widths, $rowColor);
                    }
                    
                    // 输出请求失败的持仓
                    foreach ($this->holdings as $holding) {
                        $code = $holding['code'];
                        if (!isset($stockData[$code])) {
                            $cells = [$holding['code'], '请求失败', '-', '-', '-', '-', '-', '-', '-', '-', '-', '-', '-', '-'];
                            $this->drawRow($cells, $widths, 'yellow');
                            $hasError = true;
                            $rowCount++;
                        }
                    }
                    
                    // 生成表格内容并发送微信消息
                    $wechatResult = ['success' => false, 'message' => ''];
                    if (!empty($sortedHoldings)) {
                        $tableContent = $this->generateTableContent($sortedHoldings, $headers, $widths);
                        $wechatResult = $this->sendWechatMessage($tableContent);
                    }
                    
                    $nonTradingMessage = '';
                } else {
                    $nonTradingMessage = self::COLORS['yellow'] . "当前非交易时间，暂停数据更新\n" .
                                        "交易时间: 周一至周五 09:30-11:30, 13:00-15:00" .
                                        self::COLORS['reset'] . "\n";
                    
                    foreach ($this->holdings as $holding) {
                        $cells = [
                                $holding['code'], '--', '--', '--', '--', '--', '--',
                                $this->formatNumber($holding['cost']),
                                $this->formatNumber($holding['shares'], 0), '--', '--', '--'
                        ];
                        $this->drawRow($cells, $widths, 'gray');
                    }
                    $rowCount = count($this->holdings);
                }

                $this->drawBorder('ml', 'mc', 'mr', $widths);

                bcscale(3);
                $totalProfit = (float) bcsub((string)$totalMarketValue, (string)$totalCost, 3);
                $totalProfitRate = $totalCost > 0 ? (float) bcdiv(bcmul((string)$totalProfit, '100', 3), (string)$totalCost, 3) : 0;
                $totalSign = $totalProfit > 0 ? '+' : '';

                echo self::COLORS['gray'] . "|";
                echo " " . $this->padRight('', $widths[0]) . " |";
                echo " " . $this->padRight('', $widths[1]) . " |";
                echo " " . $this->padRight('', $widths[2]) . " |";
                echo " " . $this->padRight('', $widths[3]) . " |";
                echo " " . $this->padRight('', $widths[4]) . " |";
                echo " " . $this->padRight('', $widths[5]) . " |";
                echo " " . $this->padRight('', $widths[6]) . " |";
                echo " " . $this->padRight('', $widths[7]) . " |";
                echo " " . $this->padRight('', $widths[8]) . " |";
                echo " " . $this->padRight('', $widths[9]) . " |";
                echo " " . $this->padRight($this->color("总计 ({$rowCount}只)", 'yellow'), $widths[10]) . " |";
                echo " " . $this->padRight('', $widths[11]) . " |";
                echo " " . $this->padRight($totalSign . $this->formatNumber($totalProfit), $widths[12]) . " |";
                echo " " . $this->padRight($this->formatNumber($totalProfitRate) . '%', $widths[13]) . " |\033[0m\n";

                $this->drawBorder('bl', 'mb', 'br', $widths);

                echo "\n";
                if ($hasError) {
                    echo $this->color("  接口请求失败，请检查网络或尝试切换接口", 'yellow') . "\n";
                }
                if ($totalProfit > 0) {
                    echo $this->color("  盈利: " . $totalSign . $this->formatNumber($totalProfit) . " (" . $this->formatNumber($totalProfitRate) . "%)", 'green');
                } elseif ($totalProfit < 0) {
                    echo $this->color("  亏损: " . $totalSign . $this->formatNumber($totalProfit) . " (" . $this->formatNumber($totalProfitRate) . "%)", 'red');
                } else {
                    echo $this->color("  持平: 0.00 (0.00%)", 'white');
                }
                echo "\n";
                
                // 显示非交易时间提示
                if (!empty($nonTradingMessage)) {
                    echo "  " . $nonTradingMessage;
                }
                
                // 显示微信消息发送结果
                if (!empty($wechatResult['message'])) {
                    $color = $wechatResult['success'] ? 'green' : ($wechatResult['message'] === '企业微信webhook未配置，跳过发送' ? 'yellow' : 'red');
                    echo $this->color("  " . $wechatResult['message'], $color) . "\n";
                }

                $status = $this->onlyTradingTime ? ($this->isTradingTime() ? '交易中' : '非交易') : '';
                $this->countdownWait($this->refreshInterval, $status);
                
                $this->loadConfig();
                
                unset($sortedHoldings, $stockData, $tableContent);
            }
        } catch (Exception $e) {
        } finally {
            $this->clearScreen();
            echo "\n" . $this->color("  监控已停止，感谢使用！", 'cyan') . "\n\n";
        }
    }
}

$configFile = __DIR__ . '/stocks_config.txt';
$apiType = 'sina';

$monitor = new StockMonitor($configFile, $apiType);
$monitor->run();

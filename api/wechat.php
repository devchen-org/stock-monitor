<?php
date_default_timezone_set('Asia/Shanghai');
class Wechat {
    // 发送企业微信消息
    public static function sendMessage($content, $webhook) {
        if (empty($webhook)) {
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
        
        $context = stream_context_create($options);
        $result = @file_get_contents($webhook, false, $context);
        
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
    
    // 生成表格内容
    public static function generateTableContent($sortedHoldings) {
        $content = "股票持仓收益实时监控 - " . date('Y-m-d H:i:s') . "\n\n";
        
        // 只保留需要的字段：名称、涨跌、涨跌幅、现价、成本价
        $headers = ['名称', '涨跌', '涨跌幅', '现价', '成本价'];
        $widths = [14, 8, 8, 8, 8];
        
        // 添加表头
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        $content .= "|";
        foreach ($headers as $i => $header) {
            $content .= " " . self::padRight($header, $widths[$i]) . " |";
        }
        $content .= "\n";
        
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        // 添加数据行
        foreach ($sortedHoldings as $holding) {
            if (isset($holding['stockInfo'])) {
                $info = $holding['stockInfo'];
                
                $cells = [
                    $info['name'],
                    ($info['change'] > 0 ? '+' : '') . self::formatNumber($info['change']),
                    self::formatNumber($info['changePercent']) . '%',
                    self::formatNumber($info['now']),
                    self::formatNumber($holding['cost'])
                ];
                
                $content .= "|";
                foreach ($cells as $i => $cell) {
                    $content .= " " . self::padRight($cell, $widths[$i]) . " |";
                }
                $content .= "\n";
            }
        }
        
        // 添加底部边框
        $content .= "+";
        foreach ($widths as $width) {
            $content .= str_repeat("-", $width + 2) . "+";
        }
        $content .= "\n";
        
        return $content;
    }
    
    // 字符串宽度计算
    private static function strWidth($str) {
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
        return $width;
    }
    
    // 右填充
    private static function padRight($str, $width) {
        $currentWidth = self::strWidth($str);
        if ($currentWidth >= $width) {
            return $str;
        }
        return $str . str_repeat(' ', $width - $currentWidth);
    }
    
    // 数字格式化
    private static function formatNumber($num, $decimal = 2) {
        return number_format($num, $decimal, '.', ',');
    }
}
?>
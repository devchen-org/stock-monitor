<?php
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';
require_once 'stock_api.php';
require_once 'wechat.php';

// 处理不同的API端点
$path = $_SERVER['PATH_INFO'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'];

switch ($path) {
    case '/holdings':
        handleHoldings($method);
        break;
    case '/settings':
        handleSettings($method);
        break;
    case '/stock-data':
        handleStockData();
        break;
    case '/wechat/send':
        handleWechatSend();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found']);
        break;
}

// 处理持仓相关操作
function handleHoldings($method) {
    switch ($method) {
        case 'GET':
            // 获取所有持仓
            $holdings = Database::getHoldings();
            echo json_encode($holdings);
            break;
        case 'POST':
            // 添加持仓
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['code'], $data['shares'], $data['cost'])) {
                $id = Database::addHolding(
                    $data['code'],
                    $data['name'] ?? '',
                    $data['shares'],
                    $data['cost']
                );
                echo json_encode(['id' => $id, 'success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;
        case 'PUT':
            // 更新持仓
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['id'], $data['shares'], $data['cost'])) {
                $success = Database::updateHolding($data['id'], $data['shares'], $data['cost']);
                echo json_encode(['success' => $success]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;
        case 'DELETE':
            // 删除持仓
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['id'])) {
                $success = Database::deleteHolding($data['id']);
                echo json_encode(['success' => $success]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
}

// 处理配置相关操作
function handleSettings($method) {
    switch ($method) {
        case 'GET':
            // 获取所有配置
            $settings = Database::getSettings();
            echo json_encode($settings);
            break;
        case 'POST':
            // 更新配置
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['key'], $data['value'])) {
                $success = Database::updateSetting($data['key'], $data['value']);
                echo json_encode(['success' => $success]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
}

// 处理股票数据获取
function handleStockData() {
    $holdings = Database::getHoldings();
    $settings = Database::getSettings();
    
    $apiType = $settings['api_type'] ?? 'sina';
    $onlyTradingTime = $settings['trading_time'] === 'true';
    
    $codes = array_column($holdings, 'code');
    $stockData = [];
    $hasError = false;
    $nonTradingMessage = '';
    
    if (!$onlyTradingTime || StockAPI::isTradingTime()) {
        if (!empty($codes)) {
            $stockData = StockAPI::getStockPrices($codes, $apiType);
            if (empty($stockData)) {
                $hasError = true;
            }
        }
    } else {
        $nonTradingMessage = '当前非交易时间，暂停数据更新';
    }
    
    // 准备排序数据
    $sortedHoldings = [];
    foreach ($holdings as $holding) {
        $code = $holding['code'];
        if (isset($stockData[$code])) {
            $info = $stockData[$code];
            $holding['stockInfo'] = $info;
            $holding['profitInfo'] = StockAPI::calculateProfit($holding, $info['now']);
            $sortedHoldings[] = $holding;
        }
    }
    
    // 按涨跌幅排序
    usort($sortedHoldings, function($a, $b) {
        return $b['stockInfo']['changePercent'] <=> $a['stockInfo']['changePercent'];
    });
    
    // 计算总计
    $totalMarketValue = 0;
    $totalCost = 0;
    foreach ($sortedHoldings as $holding) {
        $totalMarketValue += $holding['profitInfo'][0];
        $totalCost += $holding['shares'] * $holding['cost'];
    }
    $totalProfit = $totalMarketValue - $totalCost;
    $totalProfitRate = $totalCost > 0 ? ($totalProfit / $totalCost * 100) : 0;
    
    // 构造响应数据
    $response = [
        'holdings' => $sortedHoldings,
        'stockData' => $stockData,
        'total' => [
            'marketValue' => $totalMarketValue,
            'cost' => $totalCost,
            'profit' => $totalProfit,
            'profitRate' => $totalProfitRate
        ],
        'hasError' => $hasError,
        'nonTradingMessage' => $nonTradingMessage,
        'settings' => $settings,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
}

// 处理微信消息发送
function handleWechatSend() {
    $data = json_decode(file_get_contents('php://input'), true);
    $content = $data['content'] ?? '';
    
    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing content']);
        return;
    }
    
    $wechatWebhook = Database::getSetting('wechat_webhook');
    if (empty($wechatWebhook)) {
        echo json_encode(['success' => false, 'message' => '企业微信webhook未配置']);
        return;
    }
    
    $result = Wechat::sendMessage($content, $wechatWebhook);
    echo json_encode($result);
}
?>
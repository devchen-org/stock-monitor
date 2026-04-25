<?php

declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/repositories/TradeRepository.php';
require __DIR__ . '/../app/repositories/PositionRepository.php';
require __DIR__ . '/../app/services/PositionService.php';
require __DIR__ . '/../app/services/CalculatorService.php';

$pdo = createConnection($config);
initializeDatabase($pdo, $config);
ensurePositionsTable($pdo);

$positionRepository = new PositionRepository($pdo);
$existingPositions = $positionRepository->all();
if ($existingPositions !== []) {
    fwrite(STDOUT, "positions 表已有数据，已跳过迁移。\n");
    exit(0);
}

$tradeRepository = new TradeRepository($pdo);
$calculatorService = new CalculatorService();
$positionService = new PositionService($calculatorService);
$trades = $tradeRepository->all();
$summary = $positionService->summarize($trades);
$items = array_map(static function (array $item): array {
    $now = date('Y-m-d H:i:s');

    return [
        'symbol' => strtoupper((string) ($item['symbol'] ?? '')),
        'name' => trim((string) ($item['name'] ?? '')),
        'quantity' => (int) ($item['quantity'] ?? 0),
        'cost_price' => (float) ($item['cost_price'] ?? 0),
        'created_at' => $now,
        'updated_at' => $now,
    ];
}, array_values($summary['positions'] ?? []));

if ($items === []) {
    fwrite(STDOUT, "未从交易流水中生成任何持仓，已跳过迁移。\n");
    exit(0);
}

$positionRepository->replaceAll($items);
fwrite(STDOUT, sprintf("持仓迁移完成，共导入 %d 条记录。\n", count($items)));

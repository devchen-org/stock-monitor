<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

require __DIR__ . '/helpers/response.php';
require __DIR__ . '/helpers/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/repositories/TradeRepository.php';
require __DIR__ . '/repositories/PositionRepository.php';
require __DIR__ . '/repositories/WatchlistRepository.php';
require __DIR__ . '/repositories/TTradeRepository.php';
require __DIR__ . '/repositories/AppSettingsRepository.php';
require __DIR__ . '/services/PositionService.php';
require __DIR__ . '/services/CalculatorService.php';
require __DIR__ . '/services/QuoteService.php';
require __DIR__ . '/router.php';

startAuthSession($config);

$pdo = createConnection($config);
initializeDatabase($pdo, $config);

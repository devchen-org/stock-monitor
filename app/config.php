<?php

declare(strict_types=1);

return [
    'db_path' => dirname(__DIR__) . '/storage/data.db',
    'schema_path' => dirname(__DIR__) . '/database/schema.sql',
    'quote_refresh_seconds' => 15,
    'quote_refresh_only_trading_hours' => true,
    'auth_session_lifetime' => 2592000,
    'default_fee_rate' => 0.0003,
    'default_stamp_duty_rate' => 0.001,
    'quote_base_url' => 'http://qt.gtimg.cn/q=',
];

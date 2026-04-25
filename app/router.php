<?php

declare(strict_types=1);

function routeRequest(PDO $pdo, array $config): void
{
    $tradeRepository = new TradeRepository($pdo);
    $positionRepository = new PositionRepository($pdo);
    $watchlistRepository = new WatchlistRepository($pdo);
    $tTradeRepository = new TTradeRepository($pdo);
    $appSettingsRepository = new AppSettingsRepository($pdo);
    $calculatorService = new CalculatorService();
    $quoteService = new QuoteService($config);
    $positionService = new PositionService($calculatorService);
    $webhookService = new WebhookService();

    $action = $_GET['action'] ?? '';
    if ($action === '') {
        return;
    }

    $anonymousActions = [
        'auth.status',
        'auth.login',
        'auth.change-password',
        'auth.logout',
    ];

    try {
        switch ($action) {
            case 'auth.status':
                successResponse(authStatusPayload($appSettingsRepository));
                break;

            case 'auth.login':
                requirePost();
                $authSettings = $appSettingsRepository->getAuthSettings();
                $passwordHash = (string) ($authSettings['password_hash'] ?? '');
                if ($passwordHash === '') {
                    errorResponse('请先设置登录密码', 403, ['must_change_password' => true]);
                }

                $password = requirePassword($_POST['password'] ?? '', '登录密码');
                if (!password_verify($password, $passwordHash)) {
                    errorResponse('登录密码错误', 422);
                }

                loginWithPassword($config);
                successResponse(authStatusPayload($appSettingsRepository));
                break;

            case 'auth.logout':
                requirePost();
                logoutAuthSession();
                startAuthSession($config);
                successResponse(['logged_in' => false]);
                break;

            case 'auth.change-password':
                requirePost();
                $authSettings = $appSettingsRepository->getAuthSettings();
                $passwordConfigured = (string) ($authSettings['password_hash'] ?? '') !== '';
                $currentPassword = trim((string) ($_POST['current_password'] ?? ''));
                $newPassword = requirePassword($_POST['new_password'] ?? '', '新密码');
                $confirmPassword = requirePassword($_POST['confirm_password'] ?? '', '确认密码');

                if ($newPassword !== $confirmPassword) {
                    throw new InvalidArgumentException('两次输入的新密码不一致');
                }

                if ($passwordConfigured) {
                    requireAuthenticatedSession($appSettingsRepository);
                    requirePasswordForVerification($appSettingsRepository, $currentPassword);
                }

                $appSettingsRepository->updatePassword(password_hash($newPassword, PASSWORD_DEFAULT));
                loginWithPassword($config);
                successResponse(authStatusPayload($appSettingsRepository));
                break;

            case 'auth.force-password-change':
                requireAuthenticatedRequest($appSettingsRepository, [], $action);
                requirePost();
                $password = requirePassword($_POST['password'] ?? '', '当前密码');
                requirePasswordForVerification($appSettingsRepository, $password);
                $appSettingsRepository->setForcePasswordChange(true);
                successResponse(['must_change_password' => true]);
                break;
        }

        requireAuthenticatedRequest($appSettingsRepository, $anonymousActions, $action);

        switch ($action) {
            case 'positions':
                $positions = $positionRepository->all();
                $symbols = array_unique(array_column($positions, 'symbol'));
                $quotes = $quoteService->getBatch($symbols);
                $payload = $positionService->hydratePositions($positions, $quotes);
                pushPositionWebhookAlerts($appSettingsRepository, $webhookService, $payload['positions'] ?? []);
                successResponse($payload);
                break;

            case 'position.create':
                requirePost();
                $id = $positionRepository->create(normalizePositionPayload($_POST));
                successResponse(['id' => $id]);
                break;

            case 'position.update':
                requirePost();
                $id = requirePositiveInt($_POST['id'] ?? null, '记录ID');
                $positionRepository->update($id, normalizePositionPayload($_POST, false));
                successResponse(['id' => $id]);
                break;

            case 'position.delete':
                requirePost();
                $positionRepository->delete(requirePositiveInt($_POST['id'] ?? null, '记录ID'));
                successResponse();
                break;

            case 'positions.import':
                requirePost();
                $items = parseImportedPositions($_POST['payload'] ?? '');
                $positionRepository->replaceAll($items);
                successResponse(['count' => count($items)]);
                break;

            case 'trades':
                $trades = $tradeRepository->all();
                $symbols = array_unique(array_column($trades, 'symbol'));
                $quotes = $quoteService->getBatch($symbols);
                foreach ($trades as &$trade) {
                    $symbol = strtoupper((string) ($trade['symbol'] ?? ''));
                    $quoteName = trim((string) ($quotes[$symbol]['name'] ?? ''));
                    if ($quoteName !== '') {
                        $trade['name'] = $quoteName;
                    }
                }
                unset($trade);
                successResponse(['items' => $trades]);
                break;

            case 'trade.create':
                requirePost();
                $data = [
                    'symbol' => requireSymbol($_POST['symbol'] ?? ''),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'side' => requireIn((string) ($_POST['side'] ?? ''), ['buy', 'sell']),
                    'price' => requirePositiveFloat($_POST['price'] ?? null, '成交价'),
                    'quantity' => requirePositiveInt($_POST['quantity'] ?? null, '数量'),
                    'fee' => 0.0,
                    'trade_date' => requireDate($_POST['trade_date'] ?? ''),
                    'note' => trim((string) ($_POST['note'] ?? '')),
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                $id = $tradeRepository->create($data);
                successResponse(['id' => $id]);
                break;

            case 'trade.delete':
                requirePost();
                $tradeRepository->delete(requirePositiveInt($_POST['id'] ?? null, '记录ID'));
                successResponse();
                break;

            case 'watchlist':
                successResponse(['items' => $watchlistRepository->all()]);
                break;

            case 'watchlist.create':
                requirePost();
                $id = $watchlistRepository->create([
                    'symbol' => requireSymbol($_POST['symbol'] ?? ''),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                successResponse(['id' => $id]);
                break;

            case 'watchlist.delete':
                requirePost();
                $watchlistRepository->delete(requirePositiveInt($_POST['id'] ?? null, '记录ID'));
                successResponse();
                break;

            case 'watchlist.quotes':
                $items = $watchlistRepository->all();
                $symbols = array_column($items, 'symbol');
                successResponse([
                    'items' => $items,
                    'quotes' => array_values($quoteService->getBatch($symbols)),
                ]);
                break;

            case 'settings.get':
                successResponse(formatAppSettings($appSettingsRepository->get(), $config));
                break;

            case 'settings.refresh.update':
                requirePost();
                $quoteRefreshSeconds = requireMinimumInt($_POST['quote_refresh_seconds'] ?? null, 3, '刷新频率');
                $quoteRefreshOnlyTradingHours = toBool($_POST['quote_refresh_only_trading_hours'] ?? false);
                $calculatorDefaultLotSize = requireMinimumInt($_POST['calculator_default_lot_size'] ?? null, 1, '默认手数');
                $positionAlertGainPercent = requirePositiveFloat($_POST['position_alert_gain_percent'] ?? null, '涨幅提醒');
                $positionAlertLossPercent = requirePositiveFloat($_POST['position_alert_loss_percent'] ?? null, '跌幅提醒');
                $webhookChannel = normalizeWebhookChannel($_POST['webhook_channel'] ?? '');
                $webhookUrl = normalizeWebhookUrl($_POST['webhook_url'] ?? '');
                validateWebhookSettings($webhookChannel, $webhookUrl);
                $appSettingsRepository->saveDisplaySettings(
                    $quoteRefreshSeconds,
                    $quoteRefreshOnlyTradingHours,
                    $calculatorDefaultLotSize,
                    $positionAlertGainPercent,
                    $positionAlertLossPercent,
                    $webhookChannel,
                    $webhookUrl
                );
                successResponse(formatAppSettings($appSettingsRepository->get(), $config));
                break;

            case 'webhook.test':
                requirePost();
                $webhookChannel = normalizeWebhookChannel($_POST['webhook_channel'] ?? '');
                $webhookUrl = normalizeWebhookUrl($_POST['webhook_url'] ?? '');
                validateWebhookSettings($webhookChannel, $webhookUrl, true);
                if (!$webhookService->sendTestMessage($webhookChannel, $webhookUrl)) {
                    errorResponse('测试 webhook 消息发送失败', 502);
                }
                successResponse(['message' => '测试 webhook 消息已发送']);
                break;

            case 'ttrades':
                $records = $tTradeRepository->all();
                $symbols = array_unique(array_column($records, 'symbol'));
                $quotes = $quoteService->getBatch($symbols);
                foreach ($records as &$record) {
                    $record = enrichOpenTTradeRecord($record, $quotes, $calculatorService);
                }
                unset($record);
                pushOpenTTradeWebhookAlerts($appSettingsRepository, $webhookService, $records);
                successResponse(['items' => $records]);
                break;

            case 'ttrade.estimate':
                requirePost();
                $id = requirePositiveInt($_POST['id'] ?? null, '记录ID');
                $price = requirePositiveFloat($_POST['price'] ?? null, '试算价格');
                $record = $tTradeRepository->findById($id);

                if ($record === null) {
                    errorResponse('做T记录不存在', 404);
                }

                if ((string) ($record['status'] ?? '') !== 'open') {
                    errorResponse('仅未完成的做T记录支持试算', 422);
                }

                successResponse(buildOpenTTradeEstimate($record, $price, $calculatorService));
                break;

            case 'ttrade.alert.update':
                requirePost();
                $id = requirePositiveInt($_POST['id'] ?? null, '记录ID');
                $record = $tTradeRepository->findById($id);
                if ($record === null) {
                    errorResponse('做T记录不存在', 404);
                }

                if ((string) ($record['status'] ?? '') !== 'open') {
                    errorResponse('仅未完成的做T记录支持设置收益提醒', 422);
                }

                $alertProfitGain = normalizeOptionalPositiveFloat($_POST['alert_profit_gain'] ?? null, '正收益提醒');
                $alertProfitLoss = normalizeOptionalPositiveFloat($_POST['alert_profit_loss'] ?? null, '负收益提醒');
                $tTradeRepository->updateAlertThresholds($id, $alertProfitGain, $alertProfitLoss);
                successResponse([
                    'id' => $id,
                    'alert_profit_gain' => $alertProfitGain,
                    'alert_profit_loss' => $alertProfitLoss,
                ]);
                break;

            case 'ttrade.create':
                requirePost();
                $symbol = requireSymbol($_POST['symbol'] ?? '');
                $side = requireIn((string) ($_POST['side'] ?? ''), ['buy', 'sell']);
                $price = requirePositiveFloat($_POST['price'] ?? null, '成交价');
                $quantity = requirePositiveInt($_POST['quantity'] ?? null, '数量');
                $tradeDate = requireDate($_POST['trade_date'] ?? '');
                $note = trim((string) ($_POST['note'] ?? ''));
                $name = trim((string) ($_POST['name'] ?? ''));
                $now = date('Y-m-d H:i:s');
                $openTrade = $tTradeRepository->findOpenBySymbol($symbol);

                if ($openTrade !== null) {
                    if ((string) $openTrade['first_side'] === $side) {
                        $mergedData = $calculatorService->mergeTTradeEntry(
                            (string) $openTrade['first_side'],
                            (float) $openTrade['first_price'],
                            (int) $openTrade['first_qty'],
                            $side,
                            $price,
                            $quantity
                        );
                        $tTradeRepository->updateOpenTrade((int) $openTrade['id'], [
                            'name' => $name !== '' ? $name : (string) ($openTrade['name'] ?? ''),
                            'first_price' => $mergedData['merged_price'],
                            'first_qty' => $mergedData['merged_qty'],
                            'note' => $note !== '' ? $note : (string) ($openTrade['note'] ?? ''),
                            'updated_at' => $now,
                        ]);
                        successResponse([
                            'id' => (int) $openTrade['id'],
                            'status' => 'open',
                            'merged' => $mergedData,
                        ]);
                        break;
                    }

                    $profitData = $calculatorService->calculateTProfit(
                        (string) $openTrade['first_side'],
                        (float) $openTrade['first_price'],
                        (int) $openTrade['first_qty'],
                        $side,
                        $price,
                        $quantity
                    );
                    $tTradeRepository->closeTrade((int) $openTrade['id'], [
                        'second_side' => $side,
                        'second_price' => $price,
                        'second_qty' => $quantity,
                        'second_date' => $tradeDate,
                        'profit' => $profitData['profit'],
                        'note' => $note,
                        'updated_at' => $now,
                    ]);
                    successResponse([
                        'id' => (int) $openTrade['id'],
                        'status' => 'closed',
                        'profit' => $profitData,
                    ]);
                    break;
                }

                $id = $tTradeRepository->createOpen([
                    'symbol' => $symbol,
                    'name' => $name,
                    'first_side' => $side,
                    'first_price' => $price,
                    'first_qty' => $quantity,
                    'first_date' => $tradeDate,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                successResponse([
                    'id' => $id,
                    'status' => 'open',
                ]);
                break;

            case 'ttrade.delete':
                requirePost();
                $tTradeRepository->delete(requirePositiveInt($_POST['id'] ?? null, '记录ID'));
                successResponse();
                break;

            case 'ttrade.stats':
                successResponse($positionService->summarizeTTrades($tTradeRepository->all()));
                break;


            case 'quote.batch':
                $symbols = explode(',', (string) ($_GET['symbols'] ?? ''));
                successResponse(['items' => array_values($quoteService->getBatch($symbols))]);
                break;

            default:
                errorResponse('未知操作', 404);
        }
    } catch (InvalidArgumentException $exception) {
        errorResponse($exception->getMessage(), 422);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: watchlists.symbol')) {
            errorResponse('该股票已在自选中', 409);
        }
        if (str_contains($exception->getMessage(), 'UNIQUE constraint failed: positions.symbol')) {
            errorResponse('该股票已在持仓中', 409);
        }
        errorResponse('数据库操作失败', 500, ['detail' => $exception->getMessage()]);
    } catch (Throwable $exception) {
        errorResponse('系统异常', 500, ['detail' => $exception->getMessage()]);
    }
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new InvalidArgumentException('请求方法错误');
    }
}

function requireSymbol(mixed $value): string
{
    $symbol = strtoupper(trim((string) $value));
    if ($symbol === '') {
        throw new InvalidArgumentException('股票代码不能为空');
    }

    if (preg_match('/^(SH|SZ)?\d{6}$/', $symbol) !== 1) {
        throw new InvalidArgumentException('股票代码格式不正确');
    }

    if (strlen($symbol) === 6) {
        return str_starts_with($symbol, '6') ? 'SH' . $symbol : 'SZ' . $symbol;
    }

    return $symbol;
}

function requirePassword(mixed $value, string $label): string
{
    $password = trim((string) $value);
    if ($password === '') {
        throw new InvalidArgumentException($label . '不能为空');
    }

    if (mb_strlen($password) < 6) {
        throw new InvalidArgumentException($label . '长度不能少于 6 位');
    }

    return $password;
}

function requireIn(string $value, array $allowed): string
{
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException('参数不合法');
    }
    return $value;
}

function requirePositiveFloat(mixed $value, string $label): float
{
    if (!is_numeric($value) || (float) $value <= 0) {
        throw new InvalidArgumentException($label . '必须大于 0');
    }
    return (float) $value;
}

function requireNonNegativeFloat(mixed $value, string $label): float
{
    if (!is_numeric($value) || (float) $value < 0) {
        throw new InvalidArgumentException($label . '不能小于 0');
    }
    return (float) $value;
}

function normalizeOptionalPositiveFloat(mixed $value, string $label): ?float
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);
    if ($string === '') {
        return null;
    }

    if (!is_numeric($string) || (float) $string <= 0) {
        throw new InvalidArgumentException($label . '必须大于 0');
    }

    return round((float) $string, 2);
}

function requirePositiveInt(mixed $value, string $label): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
        throw new InvalidArgumentException($label . '必须大于 0');
    }
    return (int) $value;
}

function requireMinimumInt(mixed $value, int $minimum, string $label): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum) {
        throw new InvalidArgumentException($label . '不能小于 ' . $minimum);
    }

    return (int) $value;
}

function requireDate(mixed $value): string
{
    $date = trim((string) $value);
    if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new InvalidArgumentException('日期格式不正确');
    }
    return $date;
}

function toBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
}

function normalizePositionPayload(array $input, bool $create = true): array
{
    $now = date('Y-m-d H:i:s');

    return [
        'symbol' => requireSymbol($input['symbol'] ?? ''),
        'name' => trim((string) ($input['name'] ?? '')),
        'quantity' => requirePositiveInt($input['quantity'] ?? null, '数量'),
        'cost_price' => requireNonNegativeFloat($input['cost_price'] ?? null, '成本价'),
        'created_at' => $create ? $now : trim((string) ($input['created_at'] ?? $now)),
        'updated_at' => $now,
    ];
}

function parseImportedPositions(mixed $value): array
{
    $payload = trim((string) $value);
    if ($payload === '') {
        throw new InvalidArgumentException('导入内容不能为空');
    }

    $lines = preg_split('/\r\n|\r|\n/', $payload) ?: [];
    $items = [];
    $seenSymbols = [];

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map(static fn (string $part): string => trim($part), explode(',', $line));
        if (count($parts) !== 4) {
            throw new InvalidArgumentException('第 ' . ($index + 1) . ' 行格式不正确，请使用：代码,名称,数量,成本价');
        }

        [$symbol, $name, $quantity, $costPrice] = $parts;
        $normalized = normalizePositionPayload([
            'symbol' => $symbol,
            'name' => $name,
            'quantity' => $quantity,
            'cost_price' => $costPrice,
        ]);

        if (isset($seenSymbols[$normalized['symbol']])) {
            throw new InvalidArgumentException('导入内容存在重复股票代码：' . $normalized['symbol']);
        }

        $seenSymbols[$normalized['symbol']] = true;
        $items[] = $normalized;
    }

    if ($items === []) {
        throw new InvalidArgumentException('导入内容不能为空');
    }

    return $items;
}

function normalizeWebhookChannel(mixed $value): string
{
    $channel = strtolower(trim((string) $value));
    if ($channel === '') {
        return '';
    }

    return requireIn($channel, ['wechat', 'feishu']);
}

function normalizeWebhookUrl(mixed $value): string
{
    return trim((string) $value);
}

function validateWebhookSettings(string $channel, string $url, bool $requireUrl = false): void
{
    if ($url === '') {
        if ($requireUrl) {
            throw new InvalidArgumentException('请填写 webhook 地址');
        }
        return;
    }

    if ($channel === '') {
        throw new InvalidArgumentException('请先选择消息通知渠道');
    }

    $validatedUrl = filter_var($url, FILTER_VALIDATE_URL);
    if ($validatedUrl === false) {
        throw new InvalidArgumentException('webhook 地址格式不正确');
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('webhook 地址仅支持 http 或 https');
    }
}

function pushPositionWebhookAlerts(AppSettingsRepository $appSettingsRepository, WebhookService $webhookService, array $positions): void
{
    if ($positions === []) {
        return;
    }

    $settings = $appSettingsRepository->get();
    $channel = normalizeWebhookChannel($settings['webhook_channel'] ?? '');
    $url = normalizeWebhookUrl($settings['webhook_url'] ?? '');
    if ($channel === '' || $url === '') {
        return;
    }

    $gainThreshold = isset($settings['position_alert_gain_percent'])
        ? max(0.1, (float) $settings['position_alert_gain_percent'])
        : 5.0;
    $lossThreshold = isset($settings['position_alert_loss_percent'])
        ? max(0.1, (float) $settings['position_alert_loss_percent'])
        : 5.0;

    foreach ($positions as $position) {
        $changePercent = $position['change_percent'] ?? null;
        if (!is_numeric($changePercent)) {
            continue;
        }

        $changePercent = (float) $changePercent;
        if ($changePercent >= $gainThreshold) {
            $webhookService->sendPositionAlert($channel, $url, $position, 'gain', $gainThreshold);
            continue;
        }

        if ($changePercent <= -$lossThreshold) {
            $webhookService->sendPositionAlert($channel, $url, $position, 'loss', $lossThreshold);
        }
    }
}

function pushOpenTTradeWebhookAlerts(AppSettingsRepository $appSettingsRepository, WebhookService $webhookService, array $records): void
{
    if ($records === []) {
        return;
    }

    $settings = $appSettingsRepository->get();
    $channel = normalizeWebhookChannel($settings['webhook_channel'] ?? '');
    $url = normalizeWebhookUrl($settings['webhook_url'] ?? '');
    if ($channel === '' || $url === '') {
        return;
    }

    foreach ($records as $record) {
        if ((string) ($record['status'] ?? '') !== 'open') {
            continue;
        }

        $estimate = $record['estimate'] ?? null;
        $profit = is_array($estimate) ? ($estimate['profit'] ?? null) : null;
        if (!is_numeric($profit)) {
            continue;
        }

        $profit = round((float) $profit, 2);
        $gainThreshold = normalizeOptionalPositiveFloat($record['alert_profit_gain'] ?? null, '正收益提醒');
        $lossThreshold = normalizeOptionalPositiveFloat($record['alert_profit_loss'] ?? null, '负收益提醒');

        if ($gainThreshold !== null && $profit >= $gainThreshold) {
            $webhookService->sendTTradeAlert($channel, $url, $record, 'gain', $gainThreshold);
            continue;
        }

        if ($lossThreshold !== null && $profit <= -$lossThreshold) {
            $webhookService->sendTTradeAlert($channel, $url, $record, 'loss', $lossThreshold);
        }
    }
}

function formatAppSettings(?array $settings, array $config): array
{
    return [
        'quote_refresh_seconds' => isset($settings['quote_refresh_seconds'])
            ? max(3, (int) $settings['quote_refresh_seconds'])
            : max(3, (int) $config['quote_refresh_seconds']),
        'quote_refresh_only_trading_hours' => isset($settings['quote_refresh_only_trading_hours'])
            ? toBool($settings['quote_refresh_only_trading_hours'])
            : !empty($config['quote_refresh_only_trading_hours']),
        'calculator_default_lot_size' => isset($settings['calculator_default_lot_size'])
            ? max(1, (int) $settings['calculator_default_lot_size'])
            : 1,
        'position_alert_gain_percent' => isset($settings['position_alert_gain_percent'])
            ? max(0.1, (float) $settings['position_alert_gain_percent'])
            : 5.0,
        'position_alert_loss_percent' => isset($settings['position_alert_loss_percent'])
            ? max(0.1, (float) $settings['position_alert_loss_percent'])
            : 5.0,
        'webhook_channel' => normalizeWebhookChannel($settings['webhook_channel'] ?? ''),
        'webhook_url' => trim((string) ($settings['webhook_url'] ?? '')),
        'updated_at' => (string) ($settings['updated_at'] ?? ''),
    ];
}

function enrichOpenTTradeRecord(array $record, array $quotes, CalculatorService $calculatorService): array
{
    $symbol = strtoupper((string) ($record['symbol'] ?? ''));
    $quote = $quotes[$symbol] ?? [];
    $quoteName = trim((string) ($quote['name'] ?? ''));
    if ($quoteName !== '') {
        $record['name'] = $quoteName;
    }

    if ((string) ($record['status'] ?? '') !== 'open') {
        return $record;
    }

    $latestPrice = $quote['price'] ?? null;
    $record['estimate'] = null;
    if ($latestPrice === null || $latestPrice === '') {
        return $record;
    }

    $record['estimate'] = buildOpenTTradeEstimate($record, (float) $latestPrice, $calculatorService);
    $record['estimate']['quote_time'] = trim((string) ($quote['time'] ?? ''));

    return $record;
}

function buildOpenTTradeEstimate(array $record, float $price, CalculatorService $calculatorService): array
{
    $secondSide = ((string) ($record['first_side'] ?? 'buy')) === 'buy' ? 'sell' : 'buy';
    $profitData = $calculatorService->calculateTProfit(
        (string) $record['first_side'],
        (float) $record['first_price'],
        (int) $record['first_qty'],
        $secondSide,
        $price,
        (int) $record['first_qty']
    );

    return [
        'second_side' => $secondSide,
        'second_price' => round($price, 3),
        'second_qty' => (int) $record['first_qty'],
        'matched_qty' => (int) $profitData['matched_qty'],
        'profit' => round((float) $profitData['profit'], 2),
    ];
}

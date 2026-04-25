<?php

declare(strict_types=1);

final class WebhookService
{
    public function sendPositionAlert(string $channel, string $webhookUrl, array $position, string $type, float $threshold): bool
    {
        $channel = trim($channel);
        $webhookUrl = trim($webhookUrl);
        if ($channel === '' || $webhookUrl === '') {
            return false;
        }

        $payload = match ($channel) {
            'wechat' => $this->buildWeChatPayload($position, $type, $threshold),
            'feishu' => $this->buildFeishuPayload($position, $type, $threshold),
            default => null,
        };

        if ($payload === null) {
            return false;
        }

        return $this->postJson($webhookUrl, $payload);
    }

    public function sendTestMessage(string $channel, string $webhookUrl): bool
    {
        return $this->sendPositionAlert($channel, $webhookUrl, [
            'symbol' => 'SH600000',
            'name' => '测试股票',
            'change_percent' => 5.123,
            'latest_price' => 10.520,
            'cost_price' => 9.880,
        ], 'gain', 5.0);
    }

    public function sendTTradeAlert(string $channel, string $webhookUrl, array $record, string $type, float $threshold): bool
    {
        $channel = trim($channel);
        $webhookUrl = trim($webhookUrl);
        if ($channel === '' || $webhookUrl === '') {
            return false;
        }

        $payload = match ($channel) {
            'wechat' => $this->buildTTradeWeChatPayload($record, $type, $threshold),
            'feishu' => $this->buildTTradeFeishuPayload($record, $type, $threshold),
            default => null,
        };

        if ($payload === null) {
            return false;
        }

        return $this->postJson($webhookUrl, $payload);
    }

    private function buildWeChatPayload(array $position, string $type, float $threshold): array
    {
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $this->buildMarkdownMessage($position, $type, $threshold),
            ],
        ];
    }

    private function buildFeishuPayload(array $position, string $type, float $threshold): array
    {
        return [
            'msg_type' => 'text',
            'content' => [
                'text' => $this->buildPlainMessage($position, $type, $threshold),
            ],
        ];
    }

    private function buildTTradeWeChatPayload(array $record, string $type, float $threshold): array
    {
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $this->buildTTradeMarkdownMessage($record, $type, $threshold),
            ],
        ];
    }

    private function buildTTradeFeishuPayload(array $record, string $type, float $threshold): array
    {
        return [
            'msg_type' => 'text',
            'content' => [
                'text' => $this->buildTTradePlainMessage($record, $type, $threshold),
            ],
        ];
    }

    private function buildMarkdownMessage(array $position, string $type, float $threshold): string
    {
        $title = $type === 'gain' ? '涨幅提醒' : '跌幅提醒';
        $symbol = trim((string) ($position['symbol'] ?? ''));
        $name = trim((string) ($position['name'] ?? ''));
        $changePercent = $this->formatPercent($position['change_percent'] ?? null);
        $latestPrice = $this->formatPrice($position['latest_price'] ?? null);
        $costPrice = $this->formatPrice($position['cost_price'] ?? null);
        $thresholdText = $type === 'gain'
            ? sprintf('%.1f%%', $threshold)
            : sprintf('-%.1f%%', $threshold);

        return implode("\n", [
            sprintf('## %s', $title),
            sprintf('> %s %s', $name !== '' ? $name : '未命名股票', $symbol !== '' ? '(' . $symbol . ')' : ''),
            sprintf('> 当前涨跌幅：<font color="warning">%s</font>', $changePercent),
            sprintf('> 触发阈值：%s', $thresholdText),
            sprintf('> 当前价：%s', $latestPrice),
            sprintf('> 成本价：%s', $costPrice),
            sprintf('> 推送时间：%s', date('Y-m-d H:i:s')),
        ]);
    }

    private function buildPlainMessage(array $position, string $type, float $threshold): string
    {
        $title = $type === 'gain' ? '涨幅提醒' : '跌幅提醒';
        $symbol = trim((string) ($position['symbol'] ?? ''));
        $name = trim((string) ($position['name'] ?? ''));
        $changePercent = $this->formatPercent($position['change_percent'] ?? null);
        $latestPrice = $this->formatPrice($position['latest_price'] ?? null);
        $costPrice = $this->formatPrice($position['cost_price'] ?? null);
        $thresholdText = $type === 'gain'
            ? sprintf('%.1f%%', $threshold)
            : sprintf('-%.1f%%', $threshold);

        return implode("\n", [
            $title,
            sprintf('股票：%s %s', $name !== '' ? $name : '未命名股票', $symbol !== '' ? '(' . $symbol . ')' : ''),
            sprintf('当前涨跌幅：%s', $changePercent),
            sprintf('触发阈值：%s', $thresholdText),
            sprintf('当前价：%s', $latestPrice),
            sprintf('成本价：%s', $costPrice),
            sprintf('推送时间：%s', date('Y-m-d H:i:s')),
        ]);
    }

    private function buildTTradeMarkdownMessage(array $record, string $type, float $threshold): string
    {
        $title = $type === 'gain' ? '做T正收益提醒' : '做T负收益提醒';
        $symbol = trim((string) ($record['symbol'] ?? ''));
        $name = trim((string) ($record['name'] ?? ''));
        $estimate = is_array($record['estimate'] ?? null) ? $record['estimate'] : [];
        $profit = $this->formatMoney($estimate['profit'] ?? null);
        $firstSide = ((string) ($record['first_side'] ?? 'buy')) === 'buy' ? '买入' : '卖出';
        $secondSide = ((string) ($estimate['second_side'] ?? 'sell')) === 'buy' ? '买入' : '卖出';
        $firstPrice = $this->formatPrice($record['first_price'] ?? null);
        $firstQty = $this->formatInteger($record['first_qty'] ?? null);
        $secondPrice = $this->formatPrice($estimate['second_price'] ?? null);
        $matchedQty = $this->formatInteger($estimate['matched_qty'] ?? null);
        $thresholdText = $type === 'gain'
            ? sprintf('>= %s', $this->formatMoney($threshold))
            : sprintf('<= -%s', $this->formatMoney($threshold));
        $quoteTime = trim((string) ($estimate['quote_time'] ?? ''));

        return implode("\n", [
            sprintf('## %s', $title),
            sprintf('> %s %s', $name !== '' ? $name : '未命名股票', $symbol !== '' ? '(' . $symbol . ')' : ''),
            sprintf('> 当前试算收益：<font color="warning">%s</font>', $profit),
            sprintf('> 触发阈值：%s', $thresholdText),
            sprintf('> 首次记录：%s %s × %s', $firstSide, $firstPrice, $firstQty),
            sprintf('> 当前试算：按 %s %s × %s 完成', $secondSide, $secondPrice, $matchedQty),
            sprintf('> 行情时间：%s', $quoteTime !== '' ? $quoteTime : '--'),
            sprintf('> 推送时间：%s', date('Y-m-d H:i:s')),
        ]);
    }

    private function buildTTradePlainMessage(array $record, string $type, float $threshold): string
    {
        $title = $type === 'gain' ? '做T正收益提醒' : '做T负收益提醒';
        $symbol = trim((string) ($record['symbol'] ?? ''));
        $name = trim((string) ($record['name'] ?? ''));
        $estimate = is_array($record['estimate'] ?? null) ? $record['estimate'] : [];
        $profit = $this->formatMoney($estimate['profit'] ?? null);
        $firstSide = ((string) ($record['first_side'] ?? 'buy')) === 'buy' ? '买入' : '卖出';
        $secondSide = ((string) ($estimate['second_side'] ?? 'sell')) === 'buy' ? '买入' : '卖出';
        $firstPrice = $this->formatPrice($record['first_price'] ?? null);
        $firstQty = $this->formatInteger($record['first_qty'] ?? null);
        $secondPrice = $this->formatPrice($estimate['second_price'] ?? null);
        $matchedQty = $this->formatInteger($estimate['matched_qty'] ?? null);
        $thresholdText = $type === 'gain'
            ? sprintf('>= %s', $this->formatMoney($threshold))
            : sprintf('<= -%s', $this->formatMoney($threshold));
        $quoteTime = trim((string) ($estimate['quote_time'] ?? ''));

        return implode("\n", [
            $title,
            sprintf('股票：%s %s', $name !== '' ? $name : '未命名股票', $symbol !== '' ? '(' . $symbol . ')' : ''),
            sprintf('当前试算收益：%s', $profit),
            sprintf('触发阈值：%s', $thresholdText),
            sprintf('首次记录：%s %s × %s', $firstSide, $firstPrice, $firstQty),
            sprintf('当前试算：按 %s %s × %s 完成', $secondSide, $secondPrice, $matchedQty),
            sprintf('行情时间：%s', $quoteTime !== '' ? $quoteTime : '--'),
            sprintf('推送时间：%s', date('Y-m-d H:i:s')),
        ]);
    }

    private function postJson(string $url, array $payload): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }

        $statusLine = $http_response_header[0] ?? '';
        return preg_match('/\s2\d\d\s/', $statusLine) === 1;
    }

    private function formatPercent(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '--';
        }

        $number = (float) $value;
        return sprintf('%s%.3f%%', $number > 0 ? '+' : '', $number);
    }

    private function formatPrice(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '--';
        }

        return sprintf('%.3f', (float) $value);
    }

    private function formatMoney(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '--';
        }

        return sprintf('%.2f', (float) $value);
    }

    private function formatInteger(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '--';
        }

        return (string) (int) $value;
    }
}

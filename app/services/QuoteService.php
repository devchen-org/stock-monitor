<?php

declare(strict_types=1);

final class QuoteService
{
    public function __construct(private array $config)
    {
    }

    public function getBatch(array $symbols): array
    {
        $normalized = [];
        foreach ($symbols as $symbol) {
            $item = $this->normalizeSymbol((string) $symbol);
            if ($item !== null) {
                $normalized[$item['display']] = $item['vendor'];
            }
        }

        if ($normalized === []) {
            return [];
        }

        $url = $this->config['quote_base_url'] . implode(',', array_values($normalized));
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: Mozilla/5.0\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return [];
        }

        $raw = $this->convertToUtf8($raw);

        $quotes = [];
        $lines = preg_split('/\r?\n/', trim($raw));
        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            if (preg_match('/(?:var\s+hq_str_|v_)(.+?)=/', $line, $matches) !== 1) {
                continue;
            }

            $vendorSymbol = $matches[1] ?? null;
            if ($vendorSymbol === null) {
                continue;
            }

            $payload = trim(substr($line, strpos($line, '=') + 1), ";'");
            $values = explode('~', trim($payload, '"'));
            if (count($values) < 35) {
                continue;
            }

            $display = array_search($vendorSymbol, $normalized, true);
            if ($display === false) {
                $display = strtoupper($vendorSymbol);
            }

            $name = trim((string) ($values[1] ?? ''));
            $price = $this->toNullableFloat($values[3] ?? null);
            $open = $this->toNullableFloat($values[5] ?? null);
            $prevClose = $this->toNullableFloat($values[4] ?? null);
            $change = $this->toNullableFloat($values[31] ?? null);
            $changePercent = $this->toNullableFloat($values[32] ?? null);
            $high = $this->toNullableFloat($values[33] ?? null);
            $low = $this->toNullableFloat($values[34] ?? null);

            $quotes[$display] = [
                'symbol' => $display,
                'name' => $name,
                'price' => $price !== null ? round($price, 3) : null,
                'change' => $change !== null ? round($change, 3) : null,
                'change_percent' => $changePercent !== null ? round($changePercent, 3) : null,
                'time' => trim((string) ($values[30] ?? '')),
                'open' => $open !== null ? round($open, 3) : null,
                'prev_close' => $prevClose !== null ? round($prevClose, 3) : null,
                'high' => $high !== null ? round($high, 3) : null,
                'low' => $low !== null ? round($low, 3) : null,
            ];
        }

        return $quotes;
    }

    private function normalizeSymbol(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        if (preg_match('/^(SH|SZ)\d{6}$/', $symbol) === 1) {
            return [
                'display' => $symbol,
                'vendor' => strtolower($symbol),
            ];
        }

        if (preg_match('/^6\d{5}$/', $symbol) === 1) {
            return [
                'display' => 'SH' . $symbol,
                'vendor' => 'sh' . $symbol,
            ];
        }

        if (preg_match('/^[03]\d{5}$/', $symbol) === 1) {
            return [
                'display' => 'SZ' . $symbol,
                'vendor' => 'sz' . $symbol,
            ];
        }

        return null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function convertToUtf8(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8,GB18030,GBK,CP936');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $value;
    }
}

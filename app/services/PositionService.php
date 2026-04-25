<?php

declare(strict_types=1);

final class PositionService
{
    public function __construct(private CalculatorService $calculatorService)
    {
    }

    public function summarize(array $trades, array $quotesBySymbol = []): array
    {
        $positions = [];
        $orderedTrades = $this->sortTradesForPositionSummary($trades);

        foreach ($orderedTrades as $trade) {
            $symbol = strtoupper((string) $trade['symbol']);
            if (!isset($positions[$symbol])) {
                $positions[$symbol] = [
                    'symbol' => $symbol,
                    'name' => trim((string) $trade['name']),
                    'quantity' => 0,
                    'cost_amount' => 0.0,
                    'cost_price' => 0.0,
                    'latest_price' => null,
                    'change' => null,
                    'change_percent' => null,
                    'open' => null,
                    'prev_close' => null,
                    'high' => null,
                    'low' => null,
                    'quote_time' => '',
                    'market_value' => 0.0,
                    'profit' => 0.0,
                    'profit_rate' => 0.0,
                ];
            }

            $price = (float) $trade['price'];
            $quantity = (int) $trade['quantity'];

            if ($trade['side'] === 'buy') {
                $positions[$symbol]['quantity'] += $quantity;
                $positions[$symbol]['cost_amount'] += ($price * $quantity);
            } else {
                $currentQty = $positions[$symbol]['quantity'];
                if ($currentQty <= 0) {
                    continue;
                }
                $soldQty = min($quantity, $currentQty);
                $remainingQty = $currentQty - $soldQty;

                $positions[$symbol]['quantity'] = $remainingQty;
                if ($remainingQty > 0) {
                    $positions[$symbol]['cost_amount'] = max(0.0, $positions[$symbol]['cost_amount'] - ($price * $soldQty));
                }
            }

            if ($positions[$symbol]['quantity'] > 0) {
                $positions[$symbol]['cost_price'] = $positions[$symbol]['cost_amount'] / $positions[$symbol]['quantity'];
            } else {
                $positions[$symbol]['cost_amount'] = 0.0;
                $positions[$symbol]['cost_price'] = 0.0;
            }
        }

        $result = [];
        $summary = [
            'total_cost' => 0.0,
            'total_market_value' => 0.0,
            'total_profit' => 0.0,
            'total_profit_rate' => 0.0,
        ];

        foreach ($positions as $symbol => $position) {
            if ($position['quantity'] <= 0) {
                continue;
            }

            $quote = $quotesBySymbol[$symbol] ?? null;
            $latestPrice = $quote['price'] ?? null;
            $marketValue = $latestPrice !== null ? $latestPrice * $position['quantity'] : 0.0;
            $profit = $latestPrice !== null ? $marketValue - $position['cost_amount'] : 0.0;
            $profitRate = $position['cost_amount'] > 0 ? ($profit / $position['cost_amount']) * 100 : 0.0;
            $quoteName = trim((string) ($quote['name'] ?? ''));

            if ($quoteName !== '') {
                $position['name'] = $quoteName;
            }

            $position['latest_price'] = $latestPrice !== null ? round((float) $latestPrice, 3) : null;
            $position['change'] = $quote['change'] ?? null;
            $position['change_percent'] = $quote['change_percent'] ?? null;
            $position['open'] = $quote['open'] ?? null;
            $position['prev_close'] = $quote['prev_close'] ?? null;
            $position['high'] = $quote['high'] ?? null;
            $position['low'] = $quote['low'] ?? null;
            $position['quote_time'] = trim((string) ($quote['time'] ?? ''));
            $position['market_value'] = round($marketValue, 2);
            $position['profit'] = round($profit, 2);
            $position['profit_rate'] = round($profitRate, 3);
            $position['cost_amount'] = round($position['cost_amount'], 2);
            $position['cost_price'] = round($position['cost_price'], 4);

            $summary['total_cost'] += $position['cost_amount'];
            $summary['total_market_value'] += $position['market_value'];
            $summary['total_profit'] += $position['profit'];
            $result[] = $position;
        }

        if ($summary['total_cost'] > 0) {
            $summary['total_profit_rate'] = round(($summary['total_profit'] / $summary['total_cost']) * 100, 3);
        }

        $summary['total_cost'] = round($summary['total_cost'], 2);
        $summary['total_market_value'] = round($summary['total_market_value'], 2);
        $summary['total_profit'] = round($summary['total_profit'], 2);

        return [
            'positions' => array_values($result),
            'summary' => $summary,
        ];
    }

    public function hydratePositions(array $positions, array $quotesBySymbol = []): array
    {
        $result = [];
        $summary = [
            'total_cost' => 0.0,
            'total_market_value' => 0.0,
            'total_profit' => 0.0,
            'total_profit_rate' => 0.0,
        ];

        foreach ($positions as $position) {
            $symbol = strtoupper((string) ($position['symbol'] ?? ''));
            $quantity = (int) ($position['quantity'] ?? 0);
            if ($quantity <= 0 || $symbol === '') {
                continue;
            }

            $costPrice = round((float) ($position['cost_price'] ?? 0), 4);
            $costAmount = round($quantity * $costPrice, 2);
            $quote = $quotesBySymbol[$symbol] ?? null;
            $latestPrice = $quote['price'] ?? null;
            $marketValue = $latestPrice !== null ? $latestPrice * $quantity : 0.0;
            $profit = $latestPrice !== null ? $marketValue - $costAmount : 0.0;
            $profitRate = $costAmount > 0 ? ($profit / $costAmount) * 100 : 0.0;
            $quoteName = trim((string) ($quote['name'] ?? ''));
            $name = $quoteName !== '' ? $quoteName : trim((string) ($position['name'] ?? ''));

            $item = [
                'id' => (int) ($position['id'] ?? 0),
                'symbol' => $symbol,
                'name' => $name,
                'quantity' => $quantity,
                'cost_amount' => $costAmount,
                'cost_price' => $costPrice,
                'latest_price' => $latestPrice !== null ? round((float) $latestPrice, 3) : null,
                'change' => $quote['change'] ?? null,
                'change_percent' => $quote['change_percent'] ?? null,
                'open' => $quote['open'] ?? null,
                'prev_close' => $quote['prev_close'] ?? null,
                'high' => $quote['high'] ?? null,
                'low' => $quote['low'] ?? null,
                'quote_time' => trim((string) ($quote['time'] ?? '')),
                'market_value' => round($marketValue, 2),
                'profit' => round($profit, 2),
                'profit_rate' => round($profitRate, 3),
            ];

            $summary['total_cost'] += $item['cost_amount'];
            $summary['total_market_value'] += $item['market_value'];
            $summary['total_profit'] += $item['profit'];
            $result[] = $item;
        }

        if ($summary['total_cost'] > 0) {
            $summary['total_profit_rate'] = round(($summary['total_profit'] / $summary['total_cost']) * 100, 3);
        }

        $summary['total_cost'] = round($summary['total_cost'], 2);
        $summary['total_market_value'] = round($summary['total_market_value'], 2);
        $summary['total_profit'] = round($summary['total_profit'], 2);

        return [
            'positions' => array_values($result),
            'summary' => $summary,
        ];
    }

    public function summarizeTTrades(array $records): array
    {
        $totalProfit = 0.0;
        $bySymbol = [];
        $openCount = 0;

        foreach ($records as $record) {
            $symbol = strtoupper((string) $record['symbol']);
            $status = (string) ($record['status'] ?? 'closed');
            $profit = (float) $record['profit'];

            if ($status === 'open') {
                $openCount++;
                continue;
            }

            $totalProfit += $profit;
            if (!isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = 0.0;
            }
            $bySymbol[$symbol] += $profit;
        }

        $symbolStats = [];
        foreach ($bySymbol as $symbol => $profit) {
            $symbolStats[] = [
                'symbol' => $symbol,
                'profit' => round($profit, 2),
            ];
        }

        return [
            'total_profit' => round($totalProfit, 2),
            'open_count' => $openCount,
            'by_symbol' => $symbolStats,
        ];
    }

    private function sortTradesForPositionSummary(array $trades): array
    {
        usort($trades, static function (array $left, array $right): int {
            $dateComparison = strcmp((string) ($left['trade_date'] ?? ''), (string) ($right['trade_date'] ?? ''));
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $trades;
    }
}

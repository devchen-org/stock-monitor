<?php

declare(strict_types=1);

final class CalculatorService
{
    public function mergeTTradeEntry(string $firstSide, float $firstPrice, int $firstQty, string $secondSide, float $secondPrice, int $secondQty): array
    {
        if ($firstPrice <= 0 || $secondPrice <= 0 || $firstQty <= 0 || $secondQty <= 0) {
            throw new InvalidArgumentException('做T参数不合法');
        }

        if ($firstSide !== $secondSide) {
            throw new InvalidArgumentException('仅相同方向记录支持合并');
        }

        $mergedQty = $firstQty + $secondQty;
        $mergedPrice = (($firstPrice * $firstQty) + ($secondPrice * $secondQty)) / $mergedQty;

        return [
            'merged_qty' => $mergedQty,
            'merged_price' => round($mergedPrice, 3),
        ];
    }

    public function calculateTProfit(string $firstSide, float $firstPrice, int $firstQty, string $secondSide, float $secondPrice, int $secondQty): array
    {
        if ($firstPrice <= 0 || $secondPrice <= 0 || $firstQty <= 0 || $secondQty <= 0) {
            throw new InvalidArgumentException('做T参数不合法');
        }

        if ($firstSide === $secondSide) {
            throw new InvalidArgumentException('同向记录请先合并后再计算收益');
        }

        $matchedQty = min($firstQty, $secondQty);
        $buyPrice = $firstSide === 'buy' ? $firstPrice : $secondPrice;
        $sellPrice = $firstSide === 'sell' ? $firstPrice : $secondPrice;
        $buyAmount = $matchedQty * $buyPrice;
        $sellAmount = $matchedQty * $sellPrice;
        $profit = $sellAmount - $buyAmount;

        return [
            'matched_qty' => $matchedQty,
            'buy_amount' => round($buyAmount, 2),
            'sell_amount' => round($sellAmount, 2),
            'profit' => round($profit, 2),
        ];
    }
}

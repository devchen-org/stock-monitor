<?php

declare(strict_types=1);

final class TTradeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM t_trades ORDER BY COALESCE(second_date, first_date) DESC, id DESC');
        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM t_trades WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function findOpenBySymbol(string $symbol): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM t_trades WHERE symbol = :symbol AND status = 'open' ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([':symbol' => $symbol]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function createOpen(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO t_trades (
                symbol, name,
                buy_price, buy_qty, sell_price, sell_qty, fee, trade_date,
                first_side, first_price, first_qty, first_date,
                second_side, second_price, second_qty, second_date,
                status, profit, alert_profit_gain, alert_profit_loss, note, created_at, updated_at
            ) VALUES (
                :symbol, :name,
                :buy_price, :buy_qty, :sell_price, :sell_qty, :fee, :trade_date,
                :first_side, :first_price, :first_qty, :first_date,
                :second_side, :second_price, :second_qty, :second_date,
                :status, :profit, :alert_profit_gain, :alert_profit_loss, :note, :created_at, :updated_at
            )'
        );
        $statement->execute([
            ':symbol' => $data['symbol'],
            ':name' => $data['name'],
            ':buy_price' => 0,
            ':buy_qty' => 0,
            ':sell_price' => 0,
            ':sell_qty' => 0,
            ':fee' => 0,
            ':trade_date' => $data['first_date'],
            ':first_side' => $data['first_side'],
            ':first_price' => $data['first_price'],
            ':first_qty' => $data['first_qty'],
            ':first_date' => $data['first_date'],
            ':second_side' => null,
            ':second_price' => null,
            ':second_qty' => null,
            ':second_date' => null,
            ':status' => 'open',
            ':profit' => 0,
            ':alert_profit_gain' => null,
            ':alert_profit_loss' => null,
            ':note' => $data['note'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateOpenTrade(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_trades
             SET name = :name,
                 first_price = :first_price,
                 first_qty = :first_qty,
                 note = :note,
                 updated_at = :updated_at
             WHERE id = :id AND status = :status'
        );
        $statement->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':first_price' => $data['first_price'],
            ':first_qty' => $data['first_qty'],
            ':note' => $data['note'],
            ':updated_at' => $data['updated_at'],
            ':status' => 'open',
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('未完成做T记录不存在');
        }
    }

    public function closeTrade(int $id, array $data): void
    {
        $record = $this->findById($id);
        if ($record === null) {
            throw new RuntimeException('做T记录不存在');
        }

        if ((string) $record['first_side'] === 'buy') {
            $buyPrice = (float) $record['first_price'];
            $buyQty = (int) $record['first_qty'];
            $sellPrice = (float) $data['second_price'];
            $sellQty = (int) $data['second_qty'];
        } else {
            $buyPrice = (float) $data['second_price'];
            $buyQty = (int) $data['second_qty'];
            $sellPrice = (float) $record['first_price'];
            $sellQty = (int) $record['first_qty'];
        }

        $statement = $this->pdo->prepare(
            'UPDATE t_trades
             SET buy_price = :buy_price,
                 buy_qty = :buy_qty,
                 sell_price = :sell_price,
                 sell_qty = :sell_qty,
                 fee = :fee,
                 trade_date = :trade_date,
                 second_side = :second_side,
                 second_price = :second_price,
                 second_qty = :second_qty,
                 second_date = :second_date,
                 status = :status,
                 profit = :profit,
                 note = :note,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':buy_price' => $buyPrice,
            ':buy_qty' => $buyQty,
            ':sell_price' => $sellPrice,
            ':sell_qty' => $sellQty,
            ':fee' => 0,
            ':trade_date' => $data['second_date'],
            ':second_side' => $data['second_side'],
            ':second_price' => $data['second_price'],
            ':second_qty' => $data['second_qty'],
            ':second_date' => $data['second_date'],
            ':status' => 'closed',
            ':profit' => $data['profit'],
            ':note' => $data['note'],
            ':updated_at' => $data['updated_at'],
        ]);
    }

    public function updateAlertThresholds(int $id, ?float $gain, ?float $loss): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE t_trades
             SET alert_profit_gain = :alert_profit_gain,
                 alert_profit_loss = :alert_profit_loss,
                 updated_at = :updated_at
             WHERE id = :id AND status = :status'
        );
        $statement->execute([
            ':id' => $id,
            ':alert_profit_gain' => $gain,
            ':alert_profit_loss' => $loss,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':status' => 'open',
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('未完成做T记录不存在');
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM t_trades WHERE id = :id');
        $statement->execute([':id' => $id]);
    }
}

<?php

declare(strict_types=1);

final class TradeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM trades ORDER BY trade_date DESC, id DESC');
        return $statement->fetchAll();
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO trades (symbol, name, side, price, quantity, fee, trade_date, note, created_at)
             VALUES (:symbol, :name, :side, :price, :quantity, :fee, :trade_date, :note, :created_at)'
        );
        $statement->execute([
            ':symbol' => $data['symbol'],
            ':name' => $data['name'],
            ':side' => $data['side'],
            ':price' => $data['price'],
            ':quantity' => $data['quantity'],
            ':fee' => $data['fee'],
            ':trade_date' => $data['trade_date'],
            ':note' => $data['note'],
            ':created_at' => $data['created_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM trades WHERE id = :id');
        $statement->execute([':id' => $id]);
    }
}

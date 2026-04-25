<?php

declare(strict_types=1);

final class PositionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM positions ORDER BY symbol ASC, id ASC');
        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM positions WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO positions (symbol, name, quantity, cost_price, created_at, updated_at)
             VALUES (:symbol, :name, :quantity, :cost_price, :created_at, :updated_at)'
        );
        $statement->execute([
            ':symbol' => $data['symbol'],
            ':name' => $data['name'],
            ':quantity' => $data['quantity'],
            ':cost_price' => $data['cost_price'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE positions
             SET symbol = :symbol,
                 name = :name,
                 quantity = :quantity,
                 cost_price = :cost_price,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':symbol' => $data['symbol'],
            ':name' => $data['name'],
            ':quantity' => $data['quantity'],
            ':cost_price' => $data['cost_price'],
            ':updated_at' => $data['updated_at'],
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM positions WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    public function replaceAll(array $items): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM positions');
            $statement = $this->pdo->prepare(
                'INSERT INTO positions (symbol, name, quantity, cost_price, created_at, updated_at)
                 VALUES (:symbol, :name, :quantity, :cost_price, :created_at, :updated_at)'
            );

            foreach ($items as $item) {
                $statement->execute([
                    ':symbol' => $item['symbol'],
                    ':name' => $item['name'],
                    ':quantity' => $item['quantity'],
                    ':cost_price' => $item['cost_price'],
                    ':created_at' => $item['created_at'],
                    ':updated_at' => $item['updated_at'],
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}

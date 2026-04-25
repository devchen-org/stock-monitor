<?php

declare(strict_types=1);

final class WatchlistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM watchlists ORDER BY id DESC');
        return $statement->fetchAll();
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO watchlists (symbol, name, created_at) VALUES (:symbol, :name, :created_at)'
        );
        $statement->execute([
            ':symbol' => $data['symbol'],
            ':name' => $data['name'],
            ':created_at' => $data['created_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM watchlists WHERE id = :id');
        $statement->execute([':id' => $id]);
    }
}

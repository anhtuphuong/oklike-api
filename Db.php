<?php

declare(strict_types=1);

final class Db
{
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $host = (string)($dbConfig['host'] ?? '127.0.0.1');
        $port = (int)($dbConfig['port'] ?? 3306);
        $name = (string)($dbConfig['name'] ?? '');
        $user = (string)($dbConfig['user'] ?? '');
        $pass = (string)($dbConfig['pass'] ?? '');
        $charset = (string)($dbConfig['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->ensureSchema();
    }

    public function insertOrder(int $serviceId, string $link, int $quantity, string $smmOrderId, float $charge): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (service_id, link, quantity, smm_order_id, charge) VALUES (:service_id, :link, :quantity, :smm_order_id, :charge)'
        );

        $stmt->execute([
            ':service_id' => $serviceId,
            ':link' => $link,
            ':quantity' => $quantity,
            ':smm_order_id' => $smmOrderId,
            ':charge' => $charge,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findByV2OrderId(int $v2OrderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $v2OrderId]);

        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id INT UNSIGNED NOT NULL,
    link TEXT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    smm_order_id VARCHAR(64) NOT NULL,
    charge DECIMAL(20,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_smm_order_id (smm_order_id),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $this->pdo->exec($sql);
    }
}

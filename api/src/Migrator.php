<?php
declare(strict_types=1);

namespace KT;

use PDO;

final class Migrator
{
    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= dirname(__DIR__) . '/migrations';
    }

    /** @return string[] names of the migrations applied by this call */
    public function migrate(PDO $pdo): array
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach ($this->statements((string)file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Split on ";" at end of line; "--" comment lines are dropped. Do not put ";" mid-line in strings. */
    private function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}

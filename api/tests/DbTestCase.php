<?php
declare(strict_types=1);

namespace KT\Tests;

use KT\Db;
use KT\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected static PDO $pdo;

    public static function dropAllTables(PDO $pdo): void
    {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db !== 'kt_test') {
            throw new \RuntimeException("Refusing to drop tables in database '$db' (expected kt_test)");
        }
        $tables = $pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function setUpBeforeClass(): void
    {
        self::$pdo = Db::pdo();
        self::dropAllTables(self::$pdo);
        (new Migrator())->migrate(self::$pdo);
    }

    protected function setUp(): void
    {
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    protected function makeSupplier(string $name): int
    {
        $st = self::$pdo->prepare('INSERT INTO suppliers (name) VALUES (?)');
        $st->execute([$name]);
        return (int)self::$pdo->lastInsertId();
    }

    protected function makeProduct(string $name, string $baseUnit, ?int $categoryId = null): int
    {
        $st = self::$pdo->prepare('INSERT INTO products (name, category_id, base_unit) VALUES (?, ?, ?)');
        $st->execute([$name, $categoryId, $baseUnit]);
        return (int)self::$pdo->lastInsertId();
    }
}

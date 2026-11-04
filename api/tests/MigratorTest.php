<?php
declare(strict_types=1);

namespace KT\Tests;

use KT\Db;
use KT\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function test_migrate_idempotent(): void
    {
        $pdo = Db::pdo();
        DbTestCase::dropAllTables($pdo);
        $m = new Migrator();
        $applied = $m->migrate($pdo);
        $this->assertSame('001_init.sql', $applied[0]);
        $this->assertSame([], $m->migrate($pdo));
        $v = $pdo->query("SELECT v FROM settings WHERE k='iva_rate'")->fetchColumn();
        $this->assertSame('0.19', $v);
    }

    public function test_settings_seeded(): void
    {
        $pdo = Db::pdo();
        DbTestCase::dropAllTables($pdo);
        (new Migrator())->migrate($pdo);
        $rows = $pdo->query('SELECT k, v FROM settings ORDER BY k')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(
            ['alert_threshold_pct' => '10', 'iva_rate' => '0.19', 'recent_days' => '90', 'timezone' => 'America/Santiago'],
            $rows
        );
    }
}

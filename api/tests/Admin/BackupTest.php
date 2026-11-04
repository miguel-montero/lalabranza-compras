<?php
declare(strict_types=1);

namespace KT\Tests\Admin;

use KT\Admin\AdminController;
use KT\Admin\Backup;
use KT\Http\Request;
use KT\Http\Router;
use KT\Tests\ApiTestCase;

final class BackupTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        AdminController::configure([]);
        parent::tearDown();
    }

    public function test_dump_restores(): void
    {
        $s = $this->makeSupplier("O'Brien \\ \"Hnos\"\nLtda ñandú 🍅");
        $p = $this->makeProduct('Tomate', 'kg');
        $this->make($s, [$this->line($p, 1300)], ['notes' => "línea1\nlínea2 ' \\n"]);
        $before = (int) self::$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
        $this->assertSame(1, $before);
        $sql = Backup::dump(self::$pdo);
        $this->assertStringNotContainsString('INSERT INTO `login_attempts`', $sql);
        $this->assertStringContainsString('schema_migrations', $sql);
        $notes = self::$pdo->query('SELECT notes FROM receipts')->fetchColumn();
        $sname = self::$pdo->query('SELECT name FROM suppliers')->fetchColumn();

        self::$pdo->commit(); // DDL below would commit anyway; keep the state explicit
        self::dropAllTables(self::$pdo); // refuses anything but kt_test
        $this->assertSame([], self::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        self::$pdo->exec($sql);

        $this->assertSame($before, (int) self::$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn());
        $this->assertSame($notes, self::$pdo->query('SELECT notes FROM receipts')->fetchColumn());
        $this->assertSame($sname, self::$pdo->query('SELECT name FROM suppliers')->fetchColumn());
        $this->assertGreaterThan(0, (int) self::$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());
        // cleanup so the following tests start from a known state
        self::$pdo->exec('DELETE FROM receipt_items');
        self::$pdo->exec('DELETE FROM receipts');
        self::$pdo->exec('DELETE FROM audit_log');
        self::$pdo->exec('DELETE FROM products');
        self::$pdo->exec('DELETE FROM suppliers');
    }

    private function cron(?string $token): int
    {
        $router = new Router();
        (require __DIR__ . '/../../src/routes.php')($router);
        $q = $token === null ? [] : ['token' => $token];
        // no session, no CSRF header: the route must be public
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        return $router->dispatch(new Request('GET', '/api/cron/backup', $q))->status;
    }

    public function test_cron_token_required(): void
    {
        $dir = sys_get_temp_dir() . '/kt-backup-' . bin2hex(random_bytes(4));
        $good = str_repeat('a', 40);
        AdminController::configure(['cron_token' => 'short', 'backup_dir' => $dir]);
        $this->assertSame(403, $this->cron('short'));

        AdminController::configure(['cron_token' => $good, 'backup_dir' => $dir]);
        $this->assertSame(403, $this->cron(null));
        $this->assertSame(403, $this->cron(str_repeat('b', 40)));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame(200, $this->cron($good));
        $files = glob($dir . '/kt-backup-*.sql');
        $this->assertCount(1, $files);
        array_map('unlink', $files);
        rmdir($dir);

        AdminController::configure([]);
        $this->assertSame(403, $this->cron($good));
    }

    public function test_download_requires_session_and_names_file(): void
    {
        [$st] = $this->call('GET', '/admin/backup');
        $this->assertSame(200, $st);
    }

    public function test_prune_deletes_old_files_only(): void
    {
        $dir = sys_get_temp_dir() . '/kt-prune-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $old = "$dir/kt-backup-20200101-0000.sql";
        $new = "$dir/kt-backup-20990101-0000.sql";
        $other = "$dir/notes.txt";
        foreach ([$old, $new, $other] as $f) {
            file_put_contents($f, 'x');
        }
        touch($old, time() - 40 * 86400);
        touch($other, time() - 40 * 86400);
        $this->assertSame(1, Backup::prune($dir, 30));
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
        $this->assertFileExists($other);
        array_map('unlink', [$new, $other]);
        rmdir($dir);
    }
}

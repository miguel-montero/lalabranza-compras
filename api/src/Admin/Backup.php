<?php
declare(strict_types=1);

namespace KT\Admin;

use PDO;

/** PHP-native SQL dump (no exec / mysqldump). Restorable by running the file in phpMyAdmin or the mysql client. */
final class Backup
{
    /** Tables whose rows are not backed up (structure is still included). */
    private const SKIP_DATA = ['login_attempts'];
    private const BATCH = 200;

    public static function dump(PDO $pdo): string
    {
        $out = "-- La Labranza kitchen price tracker backup\n"
            . '-- Generated ' . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n\n";

        $tables = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $t) {
            $id = '`' . str_replace('`', '``', (string) $t) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $id")->fetch(PDO::FETCH_NUM)[1];
            $out .= "DROP TABLE IF EXISTS $id;\n$create;\n\n";
            if (in_array($t, self::SKIP_DATA, true)) {
                continue;
            }
            $st = $pdo->query("SELECT * FROM $id");
            $batch = [];
            $cols = null;
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cols ??= '(' . implode(', ', array_map(
                    static fn($c) => '`' . str_replace('`', '``', (string) $c) . '`',
                    array_keys($row)
                )) . ')';
                $batch[] = '(' . implode(', ', array_map(static fn($v) => self::literal($pdo, $v), $row)) . ')';
                if (count($batch) >= self::BATCH) {
                    $out .= "INSERT INTO $id $cols VALUES\n" . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }
            if ($batch) {
                $out .= "INSERT INTO $id $cols VALUES\n" . implode(",\n", $batch) . ";\n";
            }
            $out .= "\n";
        }
        return $out . "SET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n";
    }

    private static function literal(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        return $pdo->quote((string) $v);
    }

    public static function filename(?\DateTimeInterface $now = null): string
    {
        return 'kt-backup-' . ($now ?? new \DateTimeImmutable('now'))->format('Ymd-Hi') . '.sql';
    }

    /** Writes a dump into $dir (created if needed). @return string the file path */
    public static function writeTo(PDO $pdo, string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de respaldos');
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', '');
        }
        $path = rtrim($dir, '/') . '/' . self::filename();
        if (file_put_contents($path, self::dump($pdo)) === false) {
            throw new \RuntimeException('No se pudo escribir el respaldo');
        }
        @chmod($path, 0640);
        return $path;
    }

    /** Deletes kt-backup-*.sql older than $days. @return int files deleted */
    public static function prune(string $dir, int $days = 30): int
    {
        $n = 0;
        $cutoff = time() - $days * 86400;
        foreach (glob(rtrim($dir, '/') . '/kt-backup-*.sql') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $cutoff && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }
}

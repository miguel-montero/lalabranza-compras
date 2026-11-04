<?php
declare(strict_types=1);

namespace KT;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    /** Config keys: db_dsn, db_user, db_pass (env KT_DB_DSN / KT_DB_USER / KT_DB_PASS win). */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = getenv('KT_DB_DSN') ?: (self::$config['db_dsn'] ?? null);
        if (!$dsn) {
            throw new \RuntimeException('Database DSN is not configured');
        }
        $user = getenv('KT_DB_USER');
        $pass = getenv('KT_DB_PASS');
        $user = $user !== false ? $user : (self::$config['db_user'] ?? null);
        $pass = $pass !== false ? $pass : (self::$config['db_pass'] ?? null);
        if (!str_contains($dsn, 'charset=')) {
            $dsn .= ';charset=utf8mb4';
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . self::tzOffset($pdo) . "'");
        return self::$pdo = $pdo;
    }

    /** Offset (+HH:MM) of the timezone in settings, computed in PHP so MySQL tz tables are not required. */
    private static function tzOffset(PDO $pdo): string
    {
        $name = 'America/Santiago';
        try {
            $v = $pdo->query("SELECT v FROM settings WHERE k = 'timezone'")->fetchColumn();
            if (is_string($v) && $v !== '') {
                $name = $v;
            }
        } catch (\PDOException) {
            // settings table does not exist yet (before first migration)
        }
        try {
            $secs = (new \DateTimeZone($name))->getOffset(new \DateTimeImmutable('now'));
        } catch (\Exception) {
            $secs = 0;
        }
        $sign = $secs < 0 ? '-' : '+';
        $secs = abs($secs);
        return sprintf('%s%02d:%02d', $sign, intdiv($secs, 3600), intdiv($secs % 3600, 60));
    }
}

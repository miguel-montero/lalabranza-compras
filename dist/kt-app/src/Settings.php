<?php
declare(strict_types=1);

namespace KT;

use KT\Http\HttpError;

final class Settings
{
    public static function get(string $k): string
    {
        $st = Db::pdo()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        if ($v === false) {
            throw new \OutOfBoundsException("Unknown setting '$k'");
        }
        return (string)$v;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $out = [];
        foreach (Db::pdo()->query('SELECT k, v FROM settings ORDER BY k') as $row) {
            $out[$row['k']] = (string)$row['v'];
        }
        return $out;
    }

    /** Validates every pair first, then writes them all (all-or-nothing). */
    public static function set(array $kv): void
    {
        $clean = [];
        foreach ($kv as $k => $v) {
            $clean[(string)$k] = self::validate((string)$k, $v);
        }
        if (!$clean) {
            return;
        }
        $st = Db::pdo()->prepare(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        foreach ($clean as $k => $v) {
            $st->execute([$k, $v]);
        }
    }

    private static function validate(string $k, mixed $v): string
    {
        $bad = static fn() => new HttpError(422, 'invalid_setting', ['key' => $k]);
        if (!is_int($v) && !is_float($v) && !is_string($v)) {
            throw $bad();
        }
        $s = trim((string)$v);
        switch ($k) {
            case 'iva_rate':
                return self::number($s, 0.0, 1.0, $bad);
            case 'alert_threshold_pct':
                return self::number($s, 0.0, 100.0, $bad);
            case 'recent_days':
                if (!preg_match('/^[0-9]+$/', $s) || (int)$s < 1 || (int)$s > 3650) {
                    throw $bad();
                }
                return (string)(int)$s;
            case 'timezone':
                try {
                    new \DateTimeZone($s);
                } catch (\Exception) {
                    throw $bad();
                }
                return $s;
            default:
                throw $bad();
        }
    }

    private static function number(string $s, float $min, float $max, callable $bad): string
    {
        if (!is_numeric($s)) {
            throw $bad();
        }
        $n = (float)$s;
        if (!is_finite($n) || $n < $min || $n > $max) {
            throw $bad();
        }
        return $s;
    }
}

<?php
declare(strict_types=1);

namespace KT\Auth;

use KT\Db;
use KT\Http\HttpError;

final class Auth
{
    public const COOKIE = 'KTSESS';
    public const IDLE_SECONDS = 12 * 3600;
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;
    private const MIN_PASSWORD = 10;

    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cli = PHP_SAPI === 'cli';
        session_name(self::COOKIE);
        $opts = [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure' => (self::$config['cookie_secure'] ?? true) !== false,
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'use_strict_mode' => true,
            'gc_maxlifetime' => self::IDLE_SECONDS,
        ];
        if ($cli) {
            // Tests supply the session id themselves and have no cookies / headers.
            $opts['use_cookies'] = false;
            $opts['cache_limiter'] = '';
            $opts['use_strict_mode'] = false;
        }
        session_start($opts);
        $now = time();
        $last = $_SESSION['last_activity'] ?? null;
        if (is_int($last) && $now - $last > self::IDLE_SECONDS) {
            $_SESSION = [];
        }
        $_SESSION['last_activity'] = $now;
    }

    /** @throws HttpError 429 after 5 failures from $ip in 15 minutes. */
    public static function login(string $password, string $ip): bool
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= self::MAX_FAILURES) {
            throw new HttpError(429, 'too_many_attempts');
        }

        $row = $pdo->query('SELECT id, password_hash FROM app_user ORDER BY id LIMIT 1')->fetch();
        $ok = $row && password_verify($password, $row['password_hash']);
        if (!$ok) {
            $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
            $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
            return false;
        }

        self::startSession();
        if (!headers_sent() || PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        } else {
            // CLI with output already emitted (test runner): regeneration needs headers; rotate manually.
            session_write_close();
            session_id(bin2hex(random_bytes(16)));
            session_start(['use_cookies' => false, 'cache_limiter' => '']);
        }
        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf']);
        return true;
    }

    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION['uid']);
    }

    /** @throws HttpError 401 */
    public static function requireUser(): void
    {
        if (!self::check()) {
            throw new HttpError(401, 'unauthenticated');
        }
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(self::COOKIE, '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    public static function setPassword(string $plain): void
    {
        if (strlen($plain) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters');
        }
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $pdo = Db::pdo();
        $id = $pdo->query('SELECT id FROM app_user ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            $pdo->prepare('INSERT INTO app_user (password_hash) VALUES (?)')->execute([$hash]);
        } else {
            $pdo->prepare('UPDATE app_user SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
        }
    }

    /** Verify the single user's current password (no throttling; caller already has a session). */
    public static function verifyPassword(string $plain): bool
    {
        $h = Db::pdo()->query('SELECT password_hash FROM app_user ORDER BY id LIMIT 1')->fetchColumn();
        return is_string($h) && password_verify($plain, $h);
    }
}

<?php
declare(strict_types=1);

namespace KT\Tests\Auth;

use KT\Auth\Auth;
use KT\Auth\Csrf;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Router;
use KT\Tests\DbTestCase;

final class AuthTest extends DbTestCase
{
    private const PW = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        Auth::setPassword(self::PW);
    }

    protected function tearDown(): void
    {
        $this->endSession();
        parent::tearDown();
    }

    private function endSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    private function router(): Router
    {
        $r = new Router();
        (require __DIR__ . '/../../src/routes.php')($r);
        return $r;
    }

    private function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $res = $this->router()->dispatch(
            new Request($method, '/api' . $path, [], $headers, $body === null ? '' : json_encode($body))
        );
        return [$res->status, json_decode($res->body, true)];
    }

    public function test_login_success_and_wrong_password(): void
    {
        $this->assertFalse(Auth::login('nope nope nope', '1.1.1.1'));
        $this->assertTrue(Auth::login(self::PW, '1.1.1.1'));
        Auth::requireUser();
        $this->addToAssertionCount(1);
    }

    public function test_throttle_after_five_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(Auth::login('bad bad bad bad', '2.2.2.2'));
        }
        try {
            Auth::login(self::PW, '2.2.2.2');
            $this->fail('expected throttle');
        } catch (HttpError $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('too_many_attempts', $e->errorCode);
        }
        // other IPs are unaffected
        $this->assertTrue(Auth::login(self::PW, '3.3.3.3'));
    }

    public function test_protected_route_401_without_session(): void
    {
        [$status, $body] = $this->call('GET', '/settings');
        $this->assertSame(401, $status);
        $this->assertSame('unauthenticated', $body['error']);
    }

    public function test_me_and_login_are_public(): void
    {
        [$status, $body] = $this->call('GET', '/auth/me');
        $this->assertSame(200, $status);
        $this->assertFalse($body['authenticated']);
        $this->assertArrayNotHasKey('csrf_token', $body);

        [$status, $body] = $this->call('GET', '/health');
        $this->assertSame(200, $status);
    }

    public function test_login_route_then_me_then_mutation_needs_csrf(): void
    {
        [$status, $body] = $this->call('POST', '/auth/login', ['password' => self::PW]);
        $this->assertSame(200, $status);
        $token = $body['csrf_token'];
        $this->assertSame(64, strlen($token));

        [, $me] = $this->call('GET', '/auth/me');
        $this->assertTrue($me['authenticated']);
        $this->assertSame($token, $me['csrf_token']);

        [$status] = $this->call('GET', '/settings');
        $this->assertSame(200, $status);

        [$status, $body] = $this->call('PUT', '/settings', ['recent_days' => 30]);
        $this->assertSame(403, $status);
        $this->assertSame('csrf', $body['error']);

        [$status] = $this->call('PUT', '/settings', ['recent_days' => 30], ['X-CSRF-Token' => 'wrong']);
        $this->assertSame(403, $status);

        [$status, $body] = $this->call('PUT', '/settings', ['recent_days' => 30], ['X-CSRF-Token' => $token]);
        $this->assertSame(200, $status);
        $this->assertSame('30', $body['recent_days']);
    }

    public function test_mutation_403_without_csrf(): void
    {
        $this->assertTrue(Auth::login(self::PW, '1.1.1.1'));
        [$status, $body] = $this->call('POST', '/auth/logout');
        $this->assertSame(403, $status);
        $this->assertSame('csrf', $body['error']);
        $this->assertSame(64, strlen(Csrf::token()));
    }

    public function test_logout_ends_session(): void
    {
        [, $body] = $this->call('POST', '/auth/login', ['password' => self::PW]);
        [$status] = $this->call('POST', '/auth/logout', null, ['X-CSRF-Token' => $body['csrf_token']]);
        $this->assertSame(200, $status);
        [, $me] = $this->call('GET', '/auth/me');
        $this->assertFalse($me['authenticated']);
    }

    public function test_login_route_wrong_password_401(): void
    {
        [$status, $body] = $this->call('POST', '/auth/login', ['password' => 'wrong wrong wrong']);
        $this->assertSame(401, $status);
        $this->assertSame('invalid_credentials', $body['error']);
    }

    public function test_change_password(): void
    {
        [, $body] = $this->call('POST', '/auth/login', ['password' => self::PW]);
        $h = ['X-CSRF-Token' => $body['csrf_token']];
        [$status] = $this->call('POST', '/auth/password', ['current' => 'bad', 'new' => 'another long pass'], $h);
        $this->assertSame(403, $status);
        [$status] = $this->call('POST', '/auth/password', ['current' => self::PW, 'new' => 'short'], $h);
        $this->assertSame(422, $status);
        [$status] = $this->call('POST', '/auth/password', ['current' => self::PW, 'new' => 'another long pass'], $h);
        $this->assertSame(200, $status);
        $this->endSession();
        session_id('ktsess' . bin2hex(random_bytes(8)));
        $this->assertTrue(Auth::login('another long pass', '9.9.9.9'));
    }

    public function test_set_password_min_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Auth::setPassword('123456789');
    }

    public function test_idle_timeout_expires_session(): void
    {
        $this->assertTrue(Auth::login(self::PW, '1.1.1.1'));
        $_SESSION['last_activity'] = time() - 13 * 3600;
        session_write_close();
        $this->expectException(HttpError::class);
        Auth::requireUser();
    }

    public function test_session_uses_private_save_path_with_12h_gc(): void
    {
        $this->endSession();
        $prev = Auth::config();
        $dir = sys_get_temp_dir() . '/kt-sess-' . bin2hex(random_bytes(4));
        try {
            Auth::configure(['session_dir' => $dir] + $prev);
            $this->assertSame($dir, Auth::sessionDir());
            Auth::startSession();
            $this->assertSame($dir, session_save_path());
            $this->assertDirectoryExists($dir);
            $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
            $this->assertSame((string) (12 * 3600), ini_get('session.gc_maxlifetime'));
            $_SESSION['x'] = 1;
            session_write_close();
            $this->assertNotEmpty(glob($dir . '/sess_*'));
        } finally {
            $this->endSession();
            Auth::configure($prev);
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function test_default_session_dir_is_under_app_root(): void
    {
        $prev = Auth::config();
        try {
            Auth::configure(array_diff_key($prev, ['session_dir' => true]));
            $this->assertSame(dirname(__DIR__, 2) . '/sessions', Auth::sessionDir());
        } finally {
            Auth::configure($prev);
        }
    }
}

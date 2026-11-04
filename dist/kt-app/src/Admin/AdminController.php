<?php
declare(strict_types=1);

namespace KT\Admin;

use KT\Db;
use KT\Http\HttpError;
use KT\Http\Request;
use KT\Http\Response;
use KT\Http\Router;

final class AdminController
{
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function register(Router $router): void
    {
        $router->add('GET', '/admin/backup', fn() => Response::download(
            Backup::filename(),
            Backup::dump(Db::pdo()),
            'application/sql; charset=utf-8'
        ));
        $router->add('GET', '/cron/backup', fn(Request $r) => (new self())->cron($r));
    }

    /** Public route (no session/CSRF); authenticated by a long shared token instead. */
    public function cron(Request $r): Response
    {
        $expected = self::$config['cron_token'] ?? null;
        $given = $r->query()['token'] ?? null;
        if (!is_string($expected) || strlen($expected) < 32 || !is_string($given) || !hash_equals($expected, $given)) {
            throw new HttpError(403, 'forbidden');
        }
        $dir = self::$config['backup_dir'] ?? null;
        if (!is_string($dir) || $dir === '') {
            throw new HttpError(500, 'backup_dir_not_configured');
        }
        $path = Backup::writeTo(Db::pdo(), $dir);
        $deleted = Backup::prune($dir, 30);
        return Response::json(['ok' => true, 'file' => basename($path), 'deleted' => $deleted]);
    }
}

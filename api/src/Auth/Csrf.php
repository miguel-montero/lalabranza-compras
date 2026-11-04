<?php
declare(strict_types=1);

namespace KT\Auth;

use KT\Http\HttpError;
use KT\Http\Request;

final class Csrf
{
    /** Per-session token (32 random bytes, hex). */
    public static function token(): string
    {
        Auth::startSession();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verify(Request $r): void
    {
        $sent = $r->header('X-CSRF-Token');
        if ($sent === null || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw new HttpError(403, 'csrf');
        }
    }
}

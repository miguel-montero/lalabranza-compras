<?php
declare(strict_types=1);

/** Usage: php bin/set-password.php   (prompts without echo) */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $tty = stripos(PHP_OS, 'WIN') !== 0 && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$pw = prompt('New password (min 10 chars): ');
if ($pw !== prompt('Repeat password: ')) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
try {
    KT\Auth\Auth::setPassword($pw);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Password updated.\n";

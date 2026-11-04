<?php
declare(strict_types=1);

/** Autoload, config, error handling. Returns the config array. */

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    // Production has no Composer: minimal PSR-4 autoloader for KT\ => src/
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'KT\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 3)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? (require $configFile) : [];
KT\Db::configure($config);
KT\Auth\Auth::configure($config);
KT\Admin\AdminController::configure($config);

ini_set('display_errors', '0');
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

return $config;

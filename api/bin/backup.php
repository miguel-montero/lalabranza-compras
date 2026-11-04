<?php
declare(strict_types=1);

// CLI / cron: php bin/backup.php  (writes to config['backup_dir'], prunes files older than 30 days)
$config = require dirname(__DIR__) . '/bootstrap.php';
$dir = $config['backup_dir'] ?? null;
if (!is_string($dir) || $dir === '') {
    fwrite(STDERR, "backup_dir is not configured\n");
    exit(1);
}
$path = KT\Admin\Backup::writeTo(KT\Db::pdo(), $dir);
$deleted = KT\Admin\Backup::prune($dir, 30);
echo "Wrote $path" . ($deleted ? " (deleted $deleted old)" : '') . PHP_EOL;

<?php
// Copy to config.php (gitignored) and edit.
return [
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=kt_dev',
    'db_user' => 'kt',
    'db_pass' => 'kt',
    'cookie_secure' => false,   // true in production (HTTPS)
    'backup_dir' => __DIR__ . '/../backups', // outside the web docroot in production
    'cron_token' => 'change-me',
];

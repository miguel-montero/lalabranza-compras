<?php
// Copy to config.php (gitignored) and edit.
return [
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=kt_dev',
    'db_user' => 'kt',
    'db_pass' => 'kt',
    'cookie_secure' => false,   // true in production (HTTPS)
    // MUST be outside the web docroot. Production example: '/home/USER/kt-backups'.
    // This dev default is the repo root, which is not served.
    'backup_dir' => __DIR__ . '/../kt-backups',
    // Set a random token of at least 32 characters (e.g. `openssl rand -hex 24`).
    // The placeholder is deliberately shorter, so /api/cron/backup answers 403 until you change it.
    'cron_token' => 'CHANGE-ME',
];

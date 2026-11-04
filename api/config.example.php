<?php
// Copy to config.php (gitignored) and edit.
return [
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=kt_dev',
    'db_user' => 'kt',
    'db_pass' => 'kt',
    'cookie_secure' => false,   // true in production (HTTPS)
    'backup_dir' => __DIR__ . '/../backups', // outside the web docroot in production
    // >= 32 characters, random (e.g. `openssl rand -hex 24`). Shorter tokens make /api/cron/backup always 403.
    'cron_token' => 'change-me-to-a-long-random-string-0123456789',
];

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$applied = (new KT\Migrator())->migrate(KT\Db::pdo());
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Nothing to migrate.' . PHP_EOL;

<?php
declare(strict_types=1);

namespace KT;

final class Audit
{
    public static function record(
        string $entity,
        int $id,
        string $action,
        ?array $before,
        ?array $after,
        ?string $reason
    ): void {
        $enc = static fn(?array $a): ?string => $a === null
            ? null
            : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $st = Db::pdo()->prepare(
            'INSERT INTO audit_log (entity, entity_id, action, before_json, after_json, reason) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$entity, $id, $action, $enc($before), $enc($after), $reason]);
    }
}

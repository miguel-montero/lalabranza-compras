<?php
declare(strict_types=1);

namespace KT\Catalog;

use KT\Http\HttpError;
use PDO;

/** Data access for suppliers, categories, products and aliases. Matching relies on utf8mb4_unicode_ci. */
final class CatalogRepo
{
    public const BASE_UNITS = ['kg', 'L', 'unidad'];

    public function __construct(private PDO $pdo)
    {
    }

    // ---- suppliers / categories ------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listSuppliers(?string $q, string $active): array
    {
        $where = [];
        $args = [];
        if ($q !== null && $q !== '') {
            $where[] = 'name LIKE ?';
            $args[] = '%' . self::escapeLike($q) . '%';
        }
        $this->activeFilter($where, $args, $active);
        $sql = 'SELECT id, name, notes, active FROM suppliers'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, id';
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return array_map([$this, 'supplierRow'], $st->fetchAll());
    }

    public function getSupplier(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, notes, active FROM suppliers WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->supplierRow($r) : null;
    }

    public function createSupplier(string $name, ?string $notes): array
    {
        $this->assertNameFree('suppliers', $name, null);
        $id = $this->guard(function () use ($name, $notes) {
            $this->pdo->prepare('INSERT INTO suppliers (name, notes) VALUES (?, ?)')->execute([$name, $notes]);
            return (int)$this->pdo->lastInsertId();
        }, 'duplicate_name');
        return $this->getSupplier($id);
    }

    /** @param array<string,mixed> $fields subset of name, notes, active */
    public function updateSupplier(int $id, array $fields): ?array
    {
        if ($this->getSupplier($id) === null) {
            return null;
        }
        if (isset($fields['name'])) {
            $this->assertNameFree('suppliers', $fields['name'], $id);
        }
        $this->update('suppliers', $id, $fields, 'duplicate_name');
        return $this->getSupplier($id);
    }

    public function listCategories(?string $q): array
    {
        $sql = 'SELECT id, name FROM categories';
        $args = [];
        if ($q !== null && $q !== '') {
            $sql .= ' WHERE name LIKE ?';
            $args[] = '%' . self::escapeLike($q) . '%';
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY name, id');
        $st->execute($args);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $st->fetchAll());
    }

    public function getCategory(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name FROM categories WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? ['id' => (int)$r['id'], 'name' => $r['name']] : null;
    }

    public function createCategory(string $name): array
    {
        $this->assertNameFree('categories', $name, null);
        $id = $this->guard(function () use ($name) {
            $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
            return (int)$this->pdo->lastInsertId();
        }, 'duplicate_name');
        return $this->getCategory($id);
    }

    public function updateCategory(int $id, string $name): ?array
    {
        if ($this->getCategory($id) === null) {
            return null;
        }
        $this->assertNameFree('categories', $name, $id);
        $this->update('categories', $id, ['name' => $name], 'duplicate_name');
        return $this->getCategory($id);
    }

    // ---- products --------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listProducts(?string $q, string $active, int $limit): array
    {
        $where = [];
        $args = [];
        $order = 'p.name, p.id';
        $orderArgs = [];
        $like = null;
        if ($q !== null && $q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(p.name LIKE ? OR EXISTS (SELECT 1 FROM product_aliases a WHERE a.product_id = p.id AND a.alias LIKE ?))';
            $args[] = $like;
            $args[] = $like;
            $order = '(p.name LIKE ?) DESC, p.name, p.id';
            $orderArgs[] = self::escapeLike($q) . '%';
        }
        $this->activeFilter($where, $args, $active, 'p.');
        $sql = $this->productSelect()
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY $order LIMIT " . max(1, $limit);
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($args, $orderArgs));
        $rows = $st->fetchAll();
        $out = $this->attachAliases($rows);
        if ($like !== null) {
            $needle = $q;
            foreach ($out as &$p) {
                $nameHit = $this->ciContains($p['name'], $needle);
                if (!$nameHit) {
                    $m = $this->matchedAlias($p['id'], $like);
                    if ($m !== null) {
                        $p['matched_alias'] = $m;
                    }
                }
            }
            unset($p);
        }
        return $out;
    }

    public function getProduct(int $id): ?array
    {
        $st = $this->pdo->prepare($this->productSelect() . ' WHERE p.id = ?');
        $st->execute([$id]);
        $rows = $st->fetchAll();
        return $rows ? $this->attachAliases($rows)[0] : null;
    }

    public function createProduct(string $name, string $baseUnit, ?int $categoryId, ?string $notes): array
    {
        $this->assertProductNameFree($name, null);
        $id = $this->guard(function () use ($name, $baseUnit, $categoryId, $notes) {
            $this->pdo->prepare('INSERT INTO products (name, category_id, base_unit, notes) VALUES (?, ?, ?, ?)')
                ->execute([$name, $categoryId, $baseUnit, $notes]);
            return (int)$this->pdo->lastInsertId();
        }, 'duplicate_name');
        return $this->getProduct($id);
    }

    /** @param array<string,mixed> $fields subset of name, base_unit, category_id, notes, active */
    public function updateProduct(int $id, array $fields): ?array
    {
        $cur = $this->getProduct($id);
        if ($cur === null) {
            return null;
        }
        if (isset($fields['name'])) {
            $this->assertProductNameFree($fields['name'], $id);
        }
        if (isset($fields['base_unit']) && $fields['base_unit'] !== $cur['base_unit']) {
            $st = $this->pdo->prepare('SELECT 1 FROM receipt_items WHERE product_id = ? LIMIT 1');
            $st->execute([$id]);
            if ($st->fetchColumn() !== false) {
                throw new HttpError(409, 'base_unit_locked');
            }
        }
        $this->update('products', $id, $fields, 'duplicate_name');
        return $this->getProduct($id);
    }

    /** Exact match (case/accent-insensitive) on product name first, then on alias. */
    public function findProductByNameOrAlias(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
        $st->execute([$text]);
        $id = $st->fetchColumn();
        if ($id === false) {
            $st = $this->pdo->prepare('SELECT product_id FROM product_aliases WHERE alias = ? LIMIT 1');
            $st->execute([$text]);
            $id = $st->fetchColumn();
        }
        return $id === false ? null : $this->getProduct((int)$id);
    }

    /** Exact match (case/accent-insensitive) on supplier name. */
    public function findSupplierByName(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id FROM suppliers WHERE name = ? ORDER BY active DESC, id LIMIT 1');
        $st->execute([$text]);
        $id = $st->fetchColumn();
        return $id === false ? null : $this->getSupplier((int) $id);
    }

    // ---- aliases ---------------------------------------------------------------

    public function addAlias(int $productId, string $alias): ?array
    {
        if ($this->getProduct($productId) === null) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM products WHERE name = ?) + (SELECT COUNT(*) FROM product_aliases WHERE alias = ?)'
        );
        $st->execute([$alias, $alias]);
        if ((int)$st->fetchColumn() > 0) {
            throw new HttpError(409, 'duplicate_alias');
        }
        $id = $this->guard(function () use ($productId, $alias) {
            $this->pdo->prepare('INSERT INTO product_aliases (product_id, alias) VALUES (?, ?)')
                ->execute([$productId, $alias]);
            return (int)$this->pdo->lastInsertId();
        }, 'duplicate_alias');
        return ['id' => $id, 'product_id' => $productId, 'alias' => $alias];
    }

    public function deleteAlias(int $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM product_aliases WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    // ---- internals -------------------------------------------------------------

    private function productSelect(): string
    {
        return 'SELECT p.id, p.name, p.base_unit, p.category_id, c.name AS category_name, p.active, p.notes
                FROM products p LEFT JOIN categories c ON c.id = p.category_id';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function attachAliases(array $rows): array
    {
        $out = [];
        $byId = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'base_unit' => $r['base_unit'],
                'category_id' => $r['category_id'] === null ? null : (int)$r['category_id'],
                'category_name' => $r['category_name'],
                'active' => (bool)$r['active'],
                'notes' => $r['notes'],
                'aliases' => [],
            ];
        }
        if (!$out) {
            return $out;
        }
        foreach ($out as $i => $p) {
            $byId[$p['id']] = $i;
        }
        $ids = array_keys($byId);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT id, product_id, alias FROM product_aliases WHERE product_id IN ($in) ORDER BY alias, id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $a) {
            $out[$byId[(int)$a['product_id']]]['aliases'][] = ['id' => (int)$a['id'], 'alias' => $a['alias']];
        }
        return $out;
    }

    private function matchedAlias(int $productId, string $like): ?string
    {
        $st = $this->pdo->prepare('SELECT alias FROM product_aliases WHERE product_id = ? AND alias LIKE ? ORDER BY alias LIMIT 1');
        $st->execute([$productId, $like]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Whether the product name matches the search, decided by the DB collation (not PHP). */
    private function ciContains(string $name, string $q): bool
    {
        $st = $this->pdo->prepare('SELECT ? LIKE ?');
        $st->execute([$name, '%' . self::escapeLike($q) . '%']);
        return (bool)$st->fetchColumn();
    }

    private function supplierRow(array $r): array
    {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'notes' => $r['notes'], 'active' => (bool)$r['active']];
    }

    private function activeFilter(array &$where, array &$args, string $active, string $prefix = ''): void
    {
        if ($active === 'all') {
            return;
        }
        $where[] = $prefix . 'active = ?';
        $args[] = $active === '0' || $active === 'false' ? 0 : 1;
    }

    private function assertNameFree(string $table, string $name, ?int $exceptId): void
    {
        $st = $this->pdo->prepare("SELECT id FROM $table WHERE name = ? AND id <> ? LIMIT 1");
        $st->execute([$name, $exceptId ?? 0]);
        if ($st->fetchColumn() !== false) {
            throw new HttpError(409, 'duplicate_name');
        }
    }

    /** A product name must be unique among products and must not equal any alias. */
    private function assertProductNameFree(string $name, ?int $exceptId): void
    {
        $this->assertNameFree('products', $name, $exceptId);
        $st = $this->pdo->prepare('SELECT 1 FROM product_aliases WHERE alias = ? LIMIT 1');
        $st->execute([$name]);
        if ($st->fetchColumn() !== false) {
            throw new HttpError(409, 'duplicate_name');
        }
    }

    private function update(string $table, int $id, array $fields, string $dupCode): void
    {
        $allowed = ['name', 'notes', 'active', 'base_unit', 'category_id'];
        $sets = [];
        $args = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "`$k` = ?";
            $args[] = is_bool($v) ? (int)$v : $v;
        }
        if (!$sets) {
            return;
        }
        $args[] = $id;
        $this->guard(function () use ($table, $sets, $args) {
            $this->pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
            return null;
        }, $dupCode);
    }

    /** Runs a write and maps a unique-key violation (race after the pre-check) to a 409. */
    private function guard(callable $fn, string $dupCode): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError(409, $dupCode);
            }
            throw $e;
        }
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}

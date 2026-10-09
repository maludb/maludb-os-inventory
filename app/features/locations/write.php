<?php
declare(strict_types=1);

/** Locations' writes: INSERT / UPDATE and archive / restore. A duplicate name (23505) is the handler's sentence. */

function save_location(PDO $pdo, ?int $id, array $f): int
{
    $args = ['name' => $f['name'], 'kind' => $f['kind'], 'addr' => $f['address'], 'dept' => $f['department_id'], 'sell' => $f['is_sellable'] ? 1 : 0, 'neg' => $f['allow_negative'] ? 1 : 0];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO locations (name, kind, address, department_id, is_sellable, allow_negative) VALUES (:name, :kind, :addr, :dept, CAST(:sell AS boolean), CAST(:neg AS boolean)) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE locations SET name = :name, kind = :kind, address = :addr, department_id = :dept, is_sellable = CAST(:sell AS boolean), allow_negative = CAST(:neg AS boolean), active = CAST(:active AS boolean) WHERE id = :id')
        ->execute($args + ['active' => $f['active'] ? 1 : 0, 'id' => $id]);
    return $id;
}

function archive_location(PDO $pdo, int $id, bool $active): void
{
    $pdo->prepare('UPDATE locations SET active = CAST(:a AS boolean) WHERE id = :id')->execute(['a' => $active ? 1 : 0, 'id' => $id]);
}

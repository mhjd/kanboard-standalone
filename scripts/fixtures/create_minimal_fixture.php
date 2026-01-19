<?php

declare(strict_types=1);

use PicoDb\Database;

$root = dirname(__DIR__, 2);
$fixturePath = $root . '/tests/fixtures/kanboard-minimal.db';
$fixturesDir = dirname($fixturePath);

if (!is_dir($fixturesDir)) {
    if (!mkdir($fixturesDir, 0775, true) && !is_dir($fixturesDir)) {
        fwrite(STDERR, "Failed to create fixtures directory: {$fixturesDir}\n");
        exit(1);
    }
}

if (file_exists($fixturePath) && !unlink($fixturePath)) {
    fwrite(STDERR, "Failed to remove existing fixture: {$fixturePath}\n");
    exit(1);
}

if (!defined('DB_DRIVER')) {
    define('DB_DRIVER', 'sqlite');
}

require $root . '/vendor/autoload.php';
require $root . '/app/Schema/Sqlite.php';

$db = new Database([
    'driver' => 'sqlite',
    'filename' => $fixturePath,
    'wal_mode' => false,
]);

if (!$db->schema()->check(\Schema\VERSION)) {
    fwrite(STDERR, "Failed to run schema migrations for fixture database.\n");
    exit(1);
}

$pdo = $db->getConnection();
$pdo->exec('PRAGMA foreign_keys = ON');

$fixtureTimestamp = 1704067200; // 2024-01-01T00:00:00Z

function tableInfo(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("PRAGMA table_info('" . $table . "')");
    if ($stmt === false) {
        return [];
    }

    $info = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $info[$row['name']] = $row;
    }

    return $info;
}

function defaultForType(?string $type)
{
    if ($type === null) {
        return '';
    }

    $type = strtolower($type);

    if (strpos($type, 'int') !== false || strpos($type, 'numeric') !== false || strpos($type, 'real') !== false || strpos($type, 'float') !== false) {
        return 0;
    }

    return '';
}

function normalizeRow(PDO $pdo, string $table, array $values): array
{
    $info = tableInfo($pdo, $table);
    $filtered = [];

    foreach ($values as $column => $value) {
        if (isset($info[$column])) {
            $filtered[$column] = $value;
        }
    }

    foreach ($info as $column => $meta) {
        if ((int) $meta['pk'] === 1) {
            continue;
        }

        if ((int) $meta['notnull'] === 1 && !array_key_exists($column, $filtered)) {
            if ($meta['dflt_value'] !== null) {
                continue;
            }

            $filtered[$column] = defaultForType($meta['type']);
        }
    }

    return $filtered;
}

function insertRow(PDO $pdo, string $table, array $values): int
{
    $row = normalizeRow($pdo, $table, $values);
    $columns = array_keys($row);
    $placeholders = array_fill(0, count($columns), '?');

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', $placeholders)
    );

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException("Failed to prepare insert for {$table}.");
    }

    if (!$stmt->execute(array_values($row))) {
        throw new RuntimeException("Failed to insert into {$table}.");
    }

    return (int) $pdo->lastInsertId();
}

$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    fwrite(STDERR, "No users found after migrations; fixture cannot be created.\n");
    exit(1);
}

$projectId = insertRow($pdo, 'projects', [
    'name' => 'Fixture Project',
    'description' => 'Minimal Kanboard fixture project for tests.',
    'identifier' => 'FIXTURE',
    'is_active' => 1,
    'is_private' => 0,
    'is_public' => 0,
    'owner_id' => $userId,
    'last_modified' => $fixtureTimestamp,
]);

$projectHasUsersInfo = tableInfo($pdo, 'project_has_users');
if ($projectHasUsersInfo !== []) {
    $projectUserRow = [
        'project_id' => $projectId,
        'user_id' => $userId,
    ];

    if (isset($projectHasUsersInfo['role'])) {
        $projectUserRow['role'] = 'project-manager';
    }

    if (isset($projectHasUsersInfo['is_owner'])) {
        $projectUserRow['is_owner'] = 1;
    }

    insertRow($pdo, 'project_has_users', $projectUserRow);
}

$swimlaneId = insertRow($pdo, 'swimlanes', [
    'name' => 'Default swimlane',
    'position' => 1,
    'is_active' => 1,
    'project_id' => $projectId,
]);

$categoryId = 0;
$categoryTableInfo = tableInfo($pdo, 'project_has_categories');
if ($categoryTableInfo !== []) {
    $categoryRow = [
        'name' => 'Fixture Category',
        'project_id' => $projectId,
    ];

    if (isset($categoryTableInfo['color_id'])) {
        $categoryRow['color_id'] = 'green';
    }

    if (isset($categoryTableInfo['description'])) {
        $categoryRow['description'] = 'Fixture category for tests.';
    }

    $categoryId = insertRow($pdo, 'project_has_categories', $categoryRow);
}

$columns = [
    'Backlog',
    'In Progress',
    'Done',
];

$columnIds = [];
foreach ($columns as $index => $title) {
    $columnIds[$title] = insertRow($pdo, 'columns', [
        'title' => $title,
        'position' => $index + 1,
        'project_id' => $projectId,
        'task_limit' => 0,
        'hide_in_dashboard' => 0,
    ]);
}

$taskTableInfo = tableInfo($pdo, 'tasks');

$taskAId = insertRow($pdo, 'tasks', [
    'title' => 'Fixture Task A',
    'description' => 'First fixture task.',
    'reference' => '',
    'date_creation' => $fixtureTimestamp,
    'date_modification' => $fixtureTimestamp,
    'date_moved' => $fixtureTimestamp,
    'date_due' => $fixtureTimestamp + 86400,
    'color_id' => 'yellow',
    'priority' => 2,
    'project_id' => $projectId,
    'column_id' => $columnIds['Backlog'],
    'swimlane_id' => $swimlaneId,
    'position' => 1,
    'creator_id' => $userId,
    'owner_id' => $userId,
    'is_active' => 1,
    'category_id' => isset($taskTableInfo['category_id']) ? ($categoryId > 0 ? $categoryId : 0) : null,
]);

$taskBId = insertRow($pdo, 'tasks', [
    'title' => 'Fixture Task B',
    'description' => 'Second fixture task.',
    'reference' => '',
    'date_creation' => $fixtureTimestamp,
    'date_modification' => $fixtureTimestamp,
    'date_moved' => $fixtureTimestamp,
    'date_due' => $fixtureTimestamp + 172800,
    'color_id' => 'blue',
    'priority' => 1,
    'project_id' => $projectId,
    'column_id' => $columnIds['In Progress'],
    'swimlane_id' => $swimlaneId,
    'position' => 1,
    'creator_id' => $userId,
    'owner_id' => $userId,
    'is_active' => 1,
    'category_id' => isset($taskTableInfo['category_id']) ? 0 : null,
]);

insertRow($pdo, 'comments', [
    'task_id' => $taskAId,
    'user_id' => $userId,
    'date_creation' => $fixtureTimestamp,
    'date_modification' => $fixtureTimestamp,
    'comment' => 'First fixture comment.',
    'reference' => '',
]);

insertRow($pdo, 'comments', [
    'task_id' => $taskBId,
    'user_id' => $userId,
    'date_creation' => $fixtureTimestamp,
    'date_modification' => $fixtureTimestamp,
    'comment' => 'Second fixture comment.',
    'reference' => '',
]);

insertRow($pdo, 'subtasks', [
    'title' => 'Draft fixture checklist',
    'status' => 0,
    'task_id' => $taskAId,
    'position' => 1,
]);

insertRow($pdo, 'subtasks', [
    'title' => 'Verify fixture contents',
    'status' => 1,
    'task_id' => $taskAId,
    'position' => 2,
]);

echo "Fixture database created at {$fixturePath}\n";

<?php

declare(strict_types=1);

use Kanboard\Model\ConfigModel;
use Pimple\Container;

$root = dirname(__DIR__, 2);
$fixturePath = $root . '/tests/fixtures/kanboard-minimal.db';

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if (!file_exists($fixturePath)) {
    fail("Fixture database not found: {$fixturePath}");
}

$workDir = sys_get_temp_dir() . '/kanboard-roundtrip-' . bin2hex(random_bytes(4));
if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
    fail("Failed to create temp directory: {$workDir}");
}

$sourceDb = $workDir . '/db.sqlite';
if (!copy($fixturePath, $sourceDb)) {
    fail("Failed to copy fixture database to {$sourceDb}");
}

if (!defined('DB_FILENAME')) {
    define('DB_FILENAME', $sourceDb);
}

require $root . '/vendor/autoload.php';

class StubDb
{
    public function closeConnection(): void
    {
    }
}

$container = new Container();
$container['db'] = new StubDb();

$configModel = new ConfigModel($container);
$gzData = $configModel->downloadDatabase();

if ($gzData === false || $gzData === '') {
    fail('Export failed: gz data was empty.');
}

$gzPath = $workDir . '/db.sqlite.gz';
if (file_put_contents($gzPath, $gzData) === false) {
    fail("Failed to write export archive: {$gzPath}");
}

if (! $configModel->uploadDatabase($gzPath)) {
    fail('Import failed: unable to write decoded database.');
}

$pdo = new PDO('sqlite:' . $sourceDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$checks = [
    'projects' => 1,
    'columns' => 3,
    'tasks' => 2,
    'comments' => 2,
    'subtasks' => 2,
    'swimlanes' => 1,
];

foreach ($checks as $table => $expected) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    if ($count !== $expected) {
        fail("Expected {$expected} rows in {$table}, found {$count}.");
    }
}

$colors = $pdo->query("SELECT color_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if ($colors !== ['yellow', 'blue']) {
    fail("Unexpected task colors: " . json_encode($colors));
}

$columnPositions = $pdo->query("SELECT title, position FROM columns ORDER BY position ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$expectedColumns = [
    'Backlog' => 1,
    'In Progress' => 2,
    'Done' => 3,
];

if ($columnPositions !== $expectedColumns) {
    fail("Unexpected columns ordering: " . json_encode($columnPositions));
}

$taskColumns = $pdo->query(
    "SELECT tasks.title AS task_title, columns.title AS column_title
     FROM tasks
     JOIN columns ON columns.id = tasks.column_id
     ORDER BY tasks.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$expectedTaskColumns = [
    ['task_title' => 'Fixture Task A', 'column_title' => 'Backlog'],
    ['task_title' => 'Fixture Task B', 'column_title' => 'In Progress'],
];
if ($taskColumns !== $expectedTaskColumns) {
    fail("Unexpected task column mapping: " . json_encode($taskColumns));
}

$taskPositions = $pdo->query(
    "SELECT tasks.title AS task_title, columns.title AS column_title, tasks.position AS position
     FROM tasks
     JOIN columns ON columns.id = tasks.column_id
     ORDER BY tasks.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$taskPositions = array_map(static function (array $row): array {
    $row['position'] = (int) $row['position'];
    return $row;
}, $taskPositions);
$expectedTaskPositions = [
    ['task_title' => 'Fixture Task A', 'column_title' => 'Backlog', 'position' => 1],
    ['task_title' => 'Fixture Task B', 'column_title' => 'In Progress', 'position' => 1],
];
if ($taskPositions !== $expectedTaskPositions) {
    fail("Unexpected task positions: " . json_encode($taskPositions));
}

$commentTasks = $pdo->query("SELECT task_id FROM comments ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$uniqueCommentTasks = array_values(array_unique($commentTasks));
if (count($uniqueCommentTasks) !== 2) {
    fail("Expected comments for two tasks, found: " . json_encode($uniqueCommentTasks));
}

$commentRows = $pdo->query(
    "SELECT tasks.title AS task_title, comments.comment AS comment
     FROM comments
     JOIN tasks ON tasks.id = comments.task_id
     ORDER BY comments.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$expectedComments = [
    ['task_title' => 'Fixture Task A', 'comment' => 'First fixture comment.'],
    ['task_title' => 'Fixture Task B', 'comment' => 'Second fixture comment.'],
];
if ($commentRows !== $expectedComments) {
    fail("Unexpected comment contents: " . json_encode($commentRows));
}

$subtaskTasks = $pdo->query("SELECT task_id FROM subtasks ORDER BY position ASC")->fetchAll(PDO::FETCH_COLUMN);
if (count(array_unique($subtaskTasks)) !== 1) {
    fail("Expected subtasks on a single task, found: " . json_encode($subtaskTasks));
}

$subtaskRows = $pdo->query(
    "SELECT tasks.title AS task_title, subtasks.title AS title, subtasks.status AS status
     FROM subtasks
     JOIN tasks ON tasks.id = subtasks.task_id
     ORDER BY subtasks.position ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$subtaskRows = array_map(static function (array $row): array {
    $row['status'] = (int) $row['status'];
    return $row;
}, $subtaskRows);
$expectedSubtasks = [
    ['task_title' => 'Fixture Task A', 'title' => 'Draft fixture checklist', 'status' => 0],
    ['task_title' => 'Fixture Task A', 'title' => 'Verify fixture contents', 'status' => 1],
];
if ($subtaskRows !== $expectedSubtasks) {
    fail("Unexpected subtask contents: " . json_encode($subtaskRows));
}

echo "Round-trip verification passed.\n";

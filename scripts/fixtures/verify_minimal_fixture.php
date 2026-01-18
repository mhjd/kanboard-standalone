<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fixturePath = $root . '/tests/fixtures/kanboard-minimal.db';

if (!file_exists($fixturePath)) {
    fwrite(STDERR, "Fixture database not found: {$fixturePath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $fixturePath, null, null, [
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
        fwrite(STDERR, "Expected {$expected} rows in {$table}, found {$count}.\n");
        exit(1);
    }
}

$colors = $pdo->query("SELECT color_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if ($colors !== ['yellow', 'blue']) {
    fwrite(STDERR, "Unexpected task colors: " . json_encode($colors) . "\n");
    exit(1);
}

$columnPositions = $pdo->query("SELECT title, position FROM columns ORDER BY position ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$expectedColumns = [
    'Backlog' => 1,
    'In Progress' => 2,
    'Done' => 3,
];

if ($columnPositions !== $expectedColumns) {
    fwrite(STDERR, "Unexpected columns ordering: " . json_encode($columnPositions) . "\n");
    exit(1);
}

$commentTasks = $pdo->query("SELECT task_id FROM comments ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$uniqueCommentTasks = array_values(array_unique($commentTasks));
if (count($uniqueCommentTasks) !== 2) {
    fwrite(STDERR, "Expected comments for two tasks, found: " . json_encode($uniqueCommentTasks) . "\n");
    exit(1);
}

$subtaskTasks = $pdo->query("SELECT task_id FROM subtasks ORDER BY position ASC")->fetchAll(PDO::FETCH_COLUMN);
if (count(array_unique($subtaskTasks)) !== 1) {
    fwrite(STDERR, "Expected subtasks on a single task, found: " . json_encode($subtaskTasks) . "\n");
    exit(1);
}

echo "Fixture verification passed.\n";

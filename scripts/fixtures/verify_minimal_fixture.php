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

$fixtureTimestamp = 1704067200; // 2024-01-01T00:00:00Z

$integrityRows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
if ($integrityRows !== ['ok']) {
    fwrite(STDERR, "SQLite integrity_check failed: " . json_encode($integrityRows) . "\n");
    exit(1);
}

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

$projectRows = $pdo->query(
    "SELECT name, description, identifier, is_active, is_private, is_public
     FROM projects
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$projectRows = array_map(static function (array $row): array {
    $row['is_active'] = (int) $row['is_active'];
    $row['is_private'] = (int) $row['is_private'];
    $row['is_public'] = (int) $row['is_public'];
    return $row;
}, $projectRows);
$expectedProjects = [
    [
        'name' => 'Fixture Project',
        'description' => 'Minimal Kanboard fixture project for tests.',
        'identifier' => 'FIXTURE',
        'is_active' => 1,
        'is_private' => 0,
        'is_public' => 0,
    ],
];
if ($projectRows !== $expectedProjects) {
    fwrite(STDERR, "Unexpected project metadata: " . json_encode($projectRows) . "\n");
    exit(1);
}

$projectId = (int) $pdo->query("SELECT id FROM projects ORDER BY id ASC")->fetchColumn();
if ($projectId <= 0) {
    fwrite(STDERR, "Unexpected project id: {$projectId}\n");
    exit(1);
}

$projectMappings = [
    'columns' => $pdo->query("SELECT DISTINCT project_id FROM columns ORDER BY project_id ASC")->fetchAll(PDO::FETCH_COLUMN),
    'tasks' => $pdo->query("SELECT DISTINCT project_id FROM tasks ORDER BY project_id ASC")->fetchAll(PDO::FETCH_COLUMN),
    'swimlanes' => $pdo->query("SELECT DISTINCT project_id FROM swimlanes ORDER BY project_id ASC")->fetchAll(PDO::FETCH_COLUMN),
];

foreach ($projectMappings as $table => $projectIds) {
    $projectIds = array_map(static function ($value): int {
        return (int) $value;
    }, $projectIds);

    if ($projectIds !== [$projectId]) {
        fwrite(STDERR, "Unexpected {$table} project mapping: " . json_encode($projectIds) . "\n");
        exit(1);
    }
}

$swimlaneRows = $pdo->query("SELECT name, position, is_active FROM swimlanes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$expectedSwimlanes = [
    ['name' => 'Default swimlane', 'position' => 1, 'is_active' => 1],
];
$swimlaneRows = array_map(static function (array $row): array {
    $row['position'] = (int) $row['position'];
    $row['is_active'] = (int) $row['is_active'];
    return $row;
}, $swimlaneRows);
if ($swimlaneRows !== $expectedSwimlanes) {
    fwrite(STDERR, "Unexpected swimlane rows: " . json_encode($swimlaneRows) . "\n");
    exit(1);
}

$taskSwimlanes = $pdo->query(
    "SELECT tasks.title AS task_title, swimlanes.name AS swimlane_name
     FROM tasks
     JOIN swimlanes ON swimlanes.id = tasks.swimlane_id
     ORDER BY tasks.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$expectedTaskSwimlanes = [
    ['task_title' => 'Fixture Task A', 'swimlane_name' => 'Default swimlane'],
    ['task_title' => 'Fixture Task B', 'swimlane_name' => 'Default swimlane'],
];
if ($taskSwimlanes !== $expectedTaskSwimlanes) {
    fwrite(STDERR, "Unexpected task swimlane mapping: " . json_encode($taskSwimlanes) . "\n");
    exit(1);
}

$colors = $pdo->query("SELECT color_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if ($colors !== ['yellow', 'blue']) {
    fwrite(STDERR, "Unexpected task colors: " . json_encode($colors) . "\n");
    exit(1);
}

$taskDescriptions = $pdo->query("SELECT title, description FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$expectedTaskDescriptions = [
    ['title' => 'Fixture Task A', 'description' => 'First fixture task.'],
    ['title' => 'Fixture Task B', 'description' => 'Second fixture task.'],
];
if ($taskDescriptions !== $expectedTaskDescriptions) {
    fwrite(STDERR, "Unexpected task descriptions: " . json_encode($taskDescriptions) . "\n");
    exit(1);
}

$taskTimestamps = $pdo->query(
    "SELECT title, date_creation, date_modification, date_moved
     FROM tasks
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$taskTimestamps = array_map(static function (array $row): array {
    $row['date_creation'] = (int) $row['date_creation'];
    $row['date_modification'] = (int) $row['date_modification'];
    $row['date_moved'] = (int) $row['date_moved'];
    return $row;
}, $taskTimestamps);
$expectedTaskTimestamps = [
    [
        'title' => 'Fixture Task A',
        'date_creation' => $fixtureTimestamp,
        'date_modification' => $fixtureTimestamp,
        'date_moved' => $fixtureTimestamp,
    ],
    [
        'title' => 'Fixture Task B',
        'date_creation' => $fixtureTimestamp,
        'date_modification' => $fixtureTimestamp,
        'date_moved' => $fixtureTimestamp,
    ],
];
if ($taskTimestamps !== $expectedTaskTimestamps) {
    fwrite(STDERR, "Unexpected task timestamps: " . json_encode($taskTimestamps) . "\n");
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
    fwrite(STDERR, "Unexpected task column mapping: " . json_encode($taskColumns) . "\n");
    exit(1);
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
    fwrite(STDERR, "Unexpected task positions: " . json_encode($taskPositions) . "\n");
    exit(1);
}

$commentTasks = $pdo->query("SELECT task_id FROM comments ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$uniqueCommentTasks = array_values(array_unique($commentTasks));
if (count($uniqueCommentTasks) !== 2) {
    fwrite(STDERR, "Expected comments for two tasks, found: " . json_encode($uniqueCommentTasks) . "\n");
    exit(1);
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
    fwrite(STDERR, "Unexpected comment contents: " . json_encode($commentRows) . "\n");
    exit(1);
}

$commentTimestamps = $pdo->query(
    "SELECT tasks.title AS task_title, comments.date_creation AS date_creation, comments.date_modification AS date_modification
     FROM comments
     JOIN tasks ON tasks.id = comments.task_id
     ORDER BY comments.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$commentTimestamps = array_map(static function (array $row): array {
    $row['date_creation'] = (int) $row['date_creation'];
    $row['date_modification'] = (int) $row['date_modification'];
    return $row;
}, $commentTimestamps);
$expectedCommentTimestamps = [
    ['task_title' => 'Fixture Task A', 'date_creation' => $fixtureTimestamp, 'date_modification' => $fixtureTimestamp],
    ['task_title' => 'Fixture Task B', 'date_creation' => $fixtureTimestamp, 'date_modification' => $fixtureTimestamp],
];
if ($commentTimestamps !== $expectedCommentTimestamps) {
    fwrite(STDERR, "Unexpected comment timestamps: " . json_encode($commentTimestamps) . "\n");
    exit(1);
}

$subtaskTasks = $pdo->query("SELECT task_id FROM subtasks ORDER BY position ASC")->fetchAll(PDO::FETCH_COLUMN);
if (count(array_unique($subtaskTasks)) !== 1) {
    fwrite(STDERR, "Expected subtasks on a single task, found: " . json_encode($subtaskTasks) . "\n");
    exit(1);
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
    fwrite(STDERR, "Unexpected subtask contents: " . json_encode($subtaskRows) . "\n");
    exit(1);
}

echo "Fixture verification passed.\n";

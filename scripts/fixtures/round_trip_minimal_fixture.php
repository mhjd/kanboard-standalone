<?php

declare(strict_types=1);

use Kanboard\Model\ConfigModel;
use Pimple\Container;

$root = dirname(__DIR__, 2);
$fixturePath = $root . '/tests/fixtures/kanboard-minimal.db';
$fixtureTimestamp = 1704067200; // 2024-01-01T00:00:00Z

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("PRAGMA table_info('" . $table . "')");
    if ($stmt === false) {
        return [];
    }

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['name']] = true;
    }

    return $columns;
}

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
    fail("Unexpected project metadata: " . json_encode($projectRows));
}

$projectId = (int) $pdo->query("SELECT id FROM projects ORDER BY id ASC")->fetchColumn();
if ($projectId <= 0) {
    fail("Unexpected project id: {$projectId}");
}

$userId = (int) $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($userId <= 0) {
    fail("Unexpected user id: {$userId}");
}

$projectColumns = tableColumns($pdo, 'projects');
$projectMetaFields = [];
if (isset($projectColumns['owner_id'])) {
    $projectMetaFields[] = 'owner_id';
}
if (isset($projectColumns['last_modified'])) {
    $projectMetaFields[] = 'last_modified';
}
if ($projectMetaFields !== []) {
    $projectMeta = $pdo->query(
        'SELECT ' . implode(', ', $projectMetaFields) . ' FROM projects ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $projectMeta = array_map(static function (array $row) use ($projectMetaFields): array {
        foreach ($projectMetaFields as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }, $projectMeta);

    $expectedProjectMetaRow = [];
    foreach ($projectMetaFields as $field) {
        $expectedProjectMetaRow[$field] = $field === 'owner_id' ? $userId : $fixtureTimestamp;
    }
    $expectedProjectMeta = [$expectedProjectMetaRow];

    if ($projectMeta !== $expectedProjectMeta) {
        fail("Unexpected project owner/last_modified metadata: " . json_encode($projectMeta));
    }
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
        fail("Unexpected {$table} project mapping: " . json_encode($projectIds));
    }
}

$projectHasUsersColumns = tableColumns($pdo, 'project_has_users');
if ($projectHasUsersColumns !== []) {
    $selectFields = ['project_id', 'user_id'];
    if (isset($projectHasUsersColumns['role'])) {
        $selectFields[] = 'role';
    }
    if (isset($projectHasUsersColumns['is_owner'])) {
        $selectFields[] = 'is_owner';
    }

    $projectUsers = $pdo->query(
        'SELECT ' . implode(', ', $selectFields) . ' FROM project_has_users ORDER BY project_id ASC, user_id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $projectUsers = array_map(static function (array $row): array {
        $row['project_id'] = (int) $row['project_id'];
        $row['user_id'] = (int) $row['user_id'];
        if (array_key_exists('is_owner', $row)) {
            $row['is_owner'] = (int) $row['is_owner'];
        }
        return $row;
    }, $projectUsers);

    $expectedProjectUsers = [[
        'project_id' => $projectId,
        'user_id' => $userId,
    ]];
    if (isset($projectHasUsersColumns['role'])) {
        $expectedProjectUsers[0]['role'] = 'project-manager';
    }
    if (isset($projectHasUsersColumns['is_owner'])) {
        $expectedProjectUsers[0]['is_owner'] = 1;
    }

    if ($projectUsers !== $expectedProjectUsers) {
        fail("Unexpected project_has_users mapping: " . json_encode($projectUsers));
    }
}

$taskOwnership = $pdo->query("SELECT title, creator_id, owner_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$taskOwnership = array_map(static function (array $row): array {
    $row['creator_id'] = (int) $row['creator_id'];
    $row['owner_id'] = (int) $row['owner_id'];
    return $row;
}, $taskOwnership);
$expectedTaskOwnership = [
    ['title' => 'Fixture Task A', 'creator_id' => $userId, 'owner_id' => $userId],
    ['title' => 'Fixture Task B', 'creator_id' => $userId, 'owner_id' => $userId],
];
if ($taskOwnership !== $expectedTaskOwnership) {
    fail("Unexpected task ownership mapping: " . json_encode($taskOwnership));
}

$commentAuthors = $pdo->query(
    "SELECT tasks.title AS task_title, comments.user_id AS user_id
     FROM comments
     JOIN tasks ON tasks.id = comments.task_id
     ORDER BY comments.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$commentAuthors = array_map(static function (array $row): array {
    $row['user_id'] = (int) $row['user_id'];
    return $row;
}, $commentAuthors);
$expectedCommentAuthors = [
    ['task_title' => 'Fixture Task A', 'user_id' => $userId],
    ['task_title' => 'Fixture Task B', 'user_id' => $userId],
];
if ($commentAuthors !== $expectedCommentAuthors) {
    fail("Unexpected comment author mapping: " . json_encode($commentAuthors));
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
    fail("Unexpected swimlane rows: " . json_encode($swimlaneRows));
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
    fail("Unexpected task swimlane mapping: " . json_encode($taskSwimlanes));
}

$colors = $pdo->query("SELECT color_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if ($colors !== ['yellow', 'blue']) {
    fail("Unexpected task colors: " . json_encode($colors));
}

$taskDescriptions = $pdo->query("SELECT title, description FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$expectedTaskDescriptions = [
    ['title' => 'Fixture Task A', 'description' => 'First fixture task.'],
    ['title' => 'Fixture Task B', 'description' => 'Second fixture task.'],
];
if ($taskDescriptions !== $expectedTaskDescriptions) {
    fail("Unexpected task descriptions: " . json_encode($taskDescriptions));
}

$taskTableColumns = tableColumns($pdo, 'tasks');
if (isset($taskTableColumns['reference'])) {
    $taskReferences = $pdo->query("SELECT title, reference FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $expectedTaskReferences = [
        ['title' => 'Fixture Task A', 'reference' => ''],
        ['title' => 'Fixture Task B', 'reference' => ''],
    ];
    if ($taskReferences !== $expectedTaskReferences) {
        fail("Unexpected task references: " . json_encode($taskReferences));
    }
}

$taskActiveFlags = $pdo->query("SELECT title, is_active FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$taskActiveFlags = array_map(static function (array $row): array {
    $row['is_active'] = (int) $row['is_active'];
    return $row;
}, $taskActiveFlags);
$expectedTaskActiveFlags = [
    ['title' => 'Fixture Task A', 'is_active' => 1],
    ['title' => 'Fixture Task B', 'is_active' => 1],
];
if ($taskActiveFlags !== $expectedTaskActiveFlags) {
    fail("Unexpected task active flags: " . json_encode($taskActiveFlags));
}

if (isset($taskTableColumns['priority'])) {
    $taskPriorities = $pdo->query("SELECT title, priority FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskPriorities = array_map(static function (array $row): array {
        $row['priority'] = (int) $row['priority'];
        return $row;
    }, $taskPriorities);
    $expectedTaskPriorities = [
        ['title' => 'Fixture Task A', 'priority' => 2],
        ['title' => 'Fixture Task B', 'priority' => 1],
    ];
    if ($taskPriorities !== $expectedTaskPriorities) {
        fail("Unexpected task priorities: " . json_encode($taskPriorities));
    }
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
    fail("Unexpected task timestamps: " . json_encode($taskTimestamps));
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

$columnMetadata = $pdo->query(
    "SELECT title, task_limit, hide_in_dashboard
     FROM columns
     ORDER BY position ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$columnMetadata = array_map(static function (array $row): array {
    $row['task_limit'] = (int) $row['task_limit'];
    $row['hide_in_dashboard'] = (int) $row['hide_in_dashboard'];
    return $row;
}, $columnMetadata);
$expectedColumnMetadata = [
    ['title' => 'Backlog', 'task_limit' => 0, 'hide_in_dashboard' => 0],
    ['title' => 'In Progress', 'task_limit' => 0, 'hide_in_dashboard' => 0],
    ['title' => 'Done', 'task_limit' => 0, 'hide_in_dashboard' => 0],
];
if ($columnMetadata !== $expectedColumnMetadata) {
    fail("Unexpected column metadata: " . json_encode($columnMetadata));
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

$commentColumns = tableColumns($pdo, 'comments');
if (isset($commentColumns['reference'])) {
    $commentReferences = $pdo->query(
        "SELECT tasks.title AS task_title, comments.reference AS reference
         FROM comments
         JOIN tasks ON tasks.id = comments.task_id
         ORDER BY comments.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedCommentReferences = [
        ['task_title' => 'Fixture Task A', 'reference' => ''],
        ['task_title' => 'Fixture Task B', 'reference' => ''],
    ];
    if ($commentReferences !== $expectedCommentReferences) {
        fail("Unexpected comment references: " . json_encode($commentReferences));
    }
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
    fail("Unexpected comment timestamps: " . json_encode($commentTimestamps));
}

$subtaskTasks = $pdo->query("SELECT task_id FROM subtasks ORDER BY position ASC")->fetchAll(PDO::FETCH_COLUMN);
if (count(array_unique($subtaskTasks)) !== 1) {
    fail("Expected subtasks on a single task, found: " . json_encode($subtaskTasks));
}

$subtaskRows = $pdo->query(
    "SELECT tasks.title AS task_title, subtasks.title AS title, subtasks.status AS status, subtasks.position AS position
     FROM subtasks
     JOIN tasks ON tasks.id = subtasks.task_id
     ORDER BY subtasks.position ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$subtaskRows = array_map(static function (array $row): array {
    $row['status'] = (int) $row['status'];
    $row['position'] = (int) $row['position'];
    return $row;
}, $subtaskRows);
$expectedSubtasks = [
    ['task_title' => 'Fixture Task A', 'title' => 'Draft fixture checklist', 'status' => 0, 'position' => 1],
    ['task_title' => 'Fixture Task A', 'title' => 'Verify fixture contents', 'status' => 1, 'position' => 2],
];
if ($subtaskRows !== $expectedSubtasks) {
    fail("Unexpected subtask contents: " . json_encode($subtaskRows));
}

echo "Round-trip verification passed.\n";

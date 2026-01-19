<?php

declare(strict_types=1);

use Kanboard\Model\ConfigModel;
use Pimple\Container;

$root = dirname(__DIR__, 2);
$fixturePath = $root . '/tests/fixtures/kanboard-minimal.db';
$fixtureTimestamp = 1704067200; // 2024-01-01T00:00:00Z

require_once $root . '/app/Schema/Sqlite.php';

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

$schemaColumns = tableColumns($pdo, 'schema_version');
if ($schemaColumns !== []) {
    $schemaVersion = (int) $pdo->query('SELECT version FROM schema_version')->fetchColumn();
    $expectedSchemaVersion = (int) \Schema\VERSION;
    if ($schemaVersion !== $expectedSchemaVersion) {
        fail("Unexpected schema version: expected {$expectedSchemaVersion}, found {$schemaVersion}.");
    }
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
        fail("Expected {$expected} rows in {$table}, found {$count}.");
    }
}

$projectColumns = tableColumns($pdo, 'projects');
$projectFields = ['name', 'description', 'identifier', 'is_active', 'is_private', 'is_public'];
if (isset($projectColumns['token'])) {
    $projectFields[] = 'token';
}
$projectRows = $pdo->query(
    "SELECT " . implode(', ', $projectFields) . "
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
if (isset($projectColumns['token'])) {
    $expectedProjects[0]['token'] = 'fixture-project-token';
}
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

$userColumns = tableColumns($pdo, 'users');
$userFields = [];
foreach (['username', 'role', 'is_admin', 'is_project_admin', 'is_active'] as $field) {
    if (isset($userColumns[$field])) {
        $userFields[] = $field;
    }
}
if ($userFields !== []) {
    $users = $pdo->query(
        'SELECT ' . implode(', ', $userFields) . ' FROM users ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $users = array_map(static function (array $row): array {
        foreach (['is_admin', 'is_project_admin', 'is_active'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }, $users);

    $expectedUser = [];
    foreach ($userFields as $field) {
        if ($field === 'username') {
            $expectedUser[$field] = 'admin';
        } elseif ($field === 'role') {
            $expectedUser[$field] = 'app-admin';
        } elseif ($field === 'is_admin') {
            $expectedUser[$field] = 1;
        } elseif ($field === 'is_project_admin') {
            $expectedUser[$field] = 0;
        } elseif ($field === 'is_active') {
            $expectedUser[$field] = 1;
        }
    }

    if ($users !== [$expectedUser]) {
        fail("Unexpected user metadata: " . json_encode($users));
    }
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

$categoryId = null;
$categoryColumns = tableColumns($pdo, 'project_has_categories');
if ($categoryColumns !== []) {
    $categoryRows = $pdo->query(
        "SELECT name, project_id FROM project_has_categories ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedCategoryRows = [
        ['name' => 'Fixture Category', 'project_id' => $projectId],
    ];
    if ($categoryRows !== $expectedCategoryRows) {
        fail("Unexpected project categories: " . json_encode($categoryRows));
    }

    $categoryId = (int) $pdo->query("SELECT id FROM project_has_categories ORDER BY id ASC")->fetchColumn();
    if ($categoryId <= 0) {
        fail("Unexpected category id: {$categoryId}");
    }

    if (isset($categoryColumns['color_id'])) {
        $categoryColors = $pdo->query("SELECT color_id FROM project_has_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        if ($categoryColors !== ['green']) {
            fail("Unexpected category colors: " . json_encode($categoryColors));
        }
    }

    if (isset($categoryColumns['description'])) {
        $categoryDescriptions = $pdo->query(
            "SELECT description FROM project_has_categories ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($categoryDescriptions !== ['Fixture category for tests.']) {
            fail("Unexpected category descriptions: " . json_encode($categoryDescriptions));
        }
    }
}

$tagId = null;
$tagColumns = tableColumns($pdo, 'tags');
if ($tagColumns !== []) {
    $tagRows = $pdo->query(
        "SELECT name, project_id FROM tags ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedTagRows = [
        ['name' => 'Fixture Tag', 'project_id' => $projectId],
    ];
    if ($tagRows !== $expectedTagRows) {
        fail("Unexpected tags: " . json_encode($tagRows));
    }

    $tagId = (int) $pdo->query("SELECT id FROM tags ORDER BY id ASC")->fetchColumn();
    if ($tagId <= 0) {
        fail("Unexpected tag id: {$tagId}");
    }

    if (isset($tagColumns['color_id'])) {
        $tagColors = $pdo->query("SELECT color_id FROM tags ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        if ($tagColors !== ['red']) {
            fail("Unexpected tag colors: " . json_encode($tagColors));
        }
    }
}

$taskHasTagsColumns = tableColumns($pdo, 'task_has_tags');
if ($taskHasTagsColumns !== [] && $tagId !== null) {
    $taskTags = $pdo->query(
        "SELECT tasks.title AS task_title, tags.name AS tag_name
         FROM task_has_tags
         JOIN tasks ON tasks.id = task_has_tags.task_id
         JOIN tags ON tags.id = task_has_tags.tag_id
         ORDER BY task_has_tags.tag_id ASC, task_has_tags.task_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedTaskTags = [
        ['task_title' => 'Fixture Task A', 'tag_name' => 'Fixture Tag'],
    ];
    if ($taskTags !== $expectedTaskTags) {
        fail("Unexpected task tag mapping: " . json_encode($taskTags));
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

if ($categoryId !== null && isset($taskTableColumns['category_id'])) {
    $taskCategories = $pdo->query("SELECT title, category_id FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskCategories = array_map(static function (array $row): array {
        $row['category_id'] = (int) $row['category_id'];
        return $row;
    }, $taskCategories);
    $expectedTaskCategories = [
        ['title' => 'Fixture Task A', 'category_id' => $categoryId],
        ['title' => 'Fixture Task B', 'category_id' => 0],
    ];
    if ($taskCategories !== $expectedTaskCategories) {
        fail("Unexpected task category mapping: " . json_encode($taskCategories));
    }
}

$externalTaskFields = [];
if (isset($taskTableColumns['external_provider'])) {
    $externalTaskFields[] = 'external_provider';
}
if (isset($taskTableColumns['external_uri'])) {
    $externalTaskFields[] = 'external_uri';
}
if ($externalTaskFields !== []) {
    $selectFields = array_merge(['title'], $externalTaskFields);
    $taskExternal = $pdo->query(
        "SELECT " . implode(', ', $selectFields) . " FROM tasks ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $expectedTaskExternal = [];
    foreach (['Fixture Task A', 'Fixture Task B'] as $title) {
        $row = ['title' => $title];
        foreach ($externalTaskFields as $field) {
            $row[$field] = null;
        }
        $expectedTaskExternal[] = $row;
    }

    if ($taskExternal !== $expectedTaskExternal) {
        fail("Unexpected task external metadata: " . json_encode($taskExternal));
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

if (isset($taskTableColumns['is_milestone'])) {
    $taskMilestones = $pdo->query("SELECT title, is_milestone FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskMilestones = array_map(static function (array $row): array {
        $row['is_milestone'] = (int) $row['is_milestone'];
        return $row;
    }, $taskMilestones);
    $expectedTaskMilestones = [
        ['title' => 'Fixture Task A', 'is_milestone' => 1],
        ['title' => 'Fixture Task B', 'is_milestone' => 0],
    ];
    if ($taskMilestones !== $expectedTaskMilestones) {
        fail("Unexpected task milestone flags: " . json_encode($taskMilestones));
    }
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

if (isset($taskTableColumns['score'])) {
    $taskScores = $pdo->query("SELECT title, score FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskScores = array_map(static function (array $row): array {
        $row['score'] = (int) $row['score'];
        return $row;
    }, $taskScores);
    $expectedTaskScores = [
        ['title' => 'Fixture Task A', 'score' => 8],
        ['title' => 'Fixture Task B', 'score' => 3],
    ];
    if ($taskScores !== $expectedTaskScores) {
        fail("Unexpected task scores: " . json_encode($taskScores));
    }
}

$recurrenceFields = [];
foreach (['recurrence_status', 'recurrence_trigger', 'recurrence_factor', 'recurrence_timeframe', 'recurrence_basedate'] as $field) {
    if (isset($taskTableColumns[$field])) {
        $recurrenceFields[] = $field;
    }
}
$recurrenceNullableFields = [];
foreach (['recurrence_parent', 'recurrence_child'] as $field) {
    if (isset($taskTableColumns[$field])) {
        $recurrenceNullableFields[] = $field;
    }
}
if ($recurrenceFields !== [] || $recurrenceNullableFields !== []) {
    $selectFields = array_merge(['title'], $recurrenceFields, $recurrenceNullableFields);
    $taskRecurrence = $pdo->query(
        "SELECT " . implode(', ', $selectFields) . " FROM tasks ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $taskRecurrence = array_map(static function (array $row) use ($recurrenceFields, $recurrenceNullableFields): array {
        foreach ($recurrenceFields as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach ($recurrenceNullableFields as $field) {
            if ($row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }, $taskRecurrence);

    $expectedTaskRecurrence = [];
    foreach (['Fixture Task A', 'Fixture Task B'] as $title) {
        $row = ['title' => $title];
        foreach ($recurrenceFields as $field) {
            $row[$field] = 0;
        }
        foreach ($recurrenceNullableFields as $field) {
            $row[$field] = null;
        }
        $expectedTaskRecurrence[] = $row;
    }

    if ($taskRecurrence !== $expectedTaskRecurrence) {
        fail("Unexpected task recurrence metadata: " . json_encode($taskRecurrence));
    }
}

if (isset($taskTableColumns['date_due'])) {
    $taskDueDates = $pdo->query("SELECT title, date_due FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskDueDates = array_map(static function (array $row): array {
        $row['date_due'] = (int) $row['date_due'];
        return $row;
    }, $taskDueDates);
    $expectedTaskDueDates = [
        ['title' => 'Fixture Task A', 'date_due' => $fixtureTimestamp + 86400],
        ['title' => 'Fixture Task B', 'date_due' => $fixtureTimestamp + 172800],
    ];
    if ($taskDueDates !== $expectedTaskDueDates) {
        fail("Unexpected task due dates: " . json_encode($taskDueDates));
    }
}

if (isset($taskTableColumns['date_started'])) {
    $taskStartDates = $pdo->query("SELECT title, date_started FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskStartDates = array_map(static function (array $row): array {
        $row['date_started'] = (int) $row['date_started'];
        return $row;
    }, $taskStartDates);
    $expectedTaskStartDates = [
        ['title' => 'Fixture Task A', 'date_started' => $fixtureTimestamp + 3600],
        ['title' => 'Fixture Task B', 'date_started' => $fixtureTimestamp + 7200],
    ];
    if ($taskStartDates !== $expectedTaskStartDates) {
        fail("Unexpected task start dates: " . json_encode($taskStartDates));
    }
}

if (isset($taskTableColumns['date_completed'])) {
    $taskCompletedDates = $pdo->query("SELECT title, date_completed FROM tasks ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $taskCompletedDates = array_map(static function (array $row): array {
        $row['date_completed'] = (int) $row['date_completed'];
        return $row;
    }, $taskCompletedDates);
    $expectedTaskCompletedDates = [
        ['title' => 'Fixture Task A', 'date_completed' => 0],
        ['title' => 'Fixture Task B', 'date_completed' => 0],
    ];
    if ($taskCompletedDates !== $expectedTaskCompletedDates) {
        fail("Unexpected task completion dates: " . json_encode($taskCompletedDates));
    }
}

$taskTimeFields = [];
if (isset($taskTableColumns['time_spent'])) {
    $taskTimeFields[] = 'time_spent';
}
if (isset($taskTableColumns['time_estimated'])) {
    $taskTimeFields[] = 'time_estimated';
}
if ($taskTimeFields !== []) {
    $selectFields = array_merge(['title'], $taskTimeFields);
    $taskTimes = $pdo->query(
        "SELECT " . implode(', ', $selectFields) . " FROM tasks ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $taskTimes = array_map(static function (array $row) use ($taskTimeFields): array {
        foreach ($taskTimeFields as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }, $taskTimes);

    $expectedTaskTimes = [];
    $expectedSeed = [
        'Fixture Task A' => ['time_spent' => 90, 'time_estimated' => 240],
        'Fixture Task B' => ['time_spent' => 30, 'time_estimated' => 120],
    ];
    foreach (['Fixture Task A', 'Fixture Task B'] as $title) {
        $row = ['title' => $title];
        foreach ($taskTimeFields as $field) {
            $row[$field] = $expectedSeed[$title][$field];
        }
        $expectedTaskTimes[] = $row;
    }

    if ($taskTimes !== $expectedTaskTimes) {
        fail("Unexpected task time tracking values: " . json_encode($taskTimes));
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

if (isset($commentColumns['is_private'])) {
    $commentPrivacy = $pdo->query(
        "SELECT tasks.title AS task_title, comments.is_private AS is_private
         FROM comments
         JOIN tasks ON tasks.id = comments.task_id
         ORDER BY comments.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $commentPrivacy = array_map(static function (array $row): array {
        $row['is_private'] = (int) $row['is_private'];
        return $row;
    }, $commentPrivacy);
    $expectedCommentPrivacy = [
        ['task_title' => 'Fixture Task A', 'is_private' => 0],
        ['task_title' => 'Fixture Task B', 'is_private' => 0],
    ];
    if ($commentPrivacy !== $expectedCommentPrivacy) {
        fail("Unexpected comment privacy flags: " . json_encode($commentPrivacy));
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

$subtaskColumns = tableColumns($pdo, 'subtasks');
if (isset($subtaskColumns['user_id'])) {
    $subtaskUsers = $pdo->query(
        "SELECT tasks.title AS task_title, subtasks.title AS subtask_title, subtasks.user_id AS user_id
         FROM subtasks
         JOIN tasks ON tasks.id = subtasks.task_id
         ORDER BY subtasks.position ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $subtaskUsers = array_map(static function (array $row): array {
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }, $subtaskUsers);
    $expectedSubtaskUsers = [
        ['task_title' => 'Fixture Task A', 'subtask_title' => 'Draft fixture checklist', 'user_id' => $userId],
        ['task_title' => 'Fixture Task A', 'subtask_title' => 'Verify fixture contents', 'user_id' => $userId],
    ];
    if ($subtaskUsers !== $expectedSubtaskUsers) {
        fail("Unexpected subtask user mapping: " . json_encode($subtaskUsers));
    }
}
$subtaskTimeFields = [];
if (isset($subtaskColumns['time_spent'])) {
    $subtaskTimeFields[] = 'time_spent';
}
if (isset($subtaskColumns['time_estimated'])) {
    $subtaskTimeFields[] = 'time_estimated';
}
if ($subtaskTimeFields !== []) {
    $selectFields = [
        'tasks.title AS task_title',
        'subtasks.title AS subtask_title',
    ];
    foreach ($subtaskTimeFields as $field) {
        $selectFields[] = 'subtasks.' . $field . ' AS ' . $field;
    }

    $subtaskTimes = $pdo->query(
        "SELECT " . implode(', ', $selectFields) . "
         FROM subtasks
         JOIN tasks ON tasks.id = subtasks.task_id
         ORDER BY subtasks.position ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $subtaskTimes = array_map(static function (array $row) use ($subtaskTimeFields): array {
        foreach ($subtaskTimeFields as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }, $subtaskTimes);

    $expectedSeed = [
        [
            'task_title' => 'Fixture Task A',
            'subtask_title' => 'Draft fixture checklist',
            'time_spent' => 15,
            'time_estimated' => 45,
        ],
        [
            'task_title' => 'Fixture Task A',
            'subtask_title' => 'Verify fixture contents',
            'time_spent' => 30,
            'time_estimated' => 90,
        ],
    ];
    $expectedSubtaskTimes = [];
    foreach ($expectedSeed as $row) {
        $expectedRow = [
            'task_title' => $row['task_title'],
            'subtask_title' => $row['subtask_title'],
        ];
        foreach ($subtaskTimeFields as $field) {
            $expectedRow[$field] = $row[$field];
        }
        $expectedSubtaskTimes[] = $expectedRow;
    }

    if ($subtaskTimes !== $expectedSubtaskTimes) {
        fail("Unexpected subtask time tracking values: " . json_encode($subtaskTimes));
    }
}

echo "Round-trip verification passed.\n";

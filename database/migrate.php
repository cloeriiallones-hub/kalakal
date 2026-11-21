<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$migrationOrder = [
    '20261108_account_deletion.sql',
    '20261108_unique_collector_service_areas.sql',
    '20261108_balanced_collector_areas.sql',
    '20261109_pending_pickup_cancellation_notifications.sql',
    '20261113_owner_buyer_workflow.sql',
    '20261114_junkshop_comparison_pickup_charge.sql',
    '20261115_three_roles_buyer_messaging.sql',
    '20261114_authentication_lifecycle.sql',
    '20261116_collection_payments_and_transitions.sql',
    '20261117_messaging_privacy_pagination.sql',
    '20261118_email_notification_queue.sql',
    '20261119_missed_pickup_reports.sql',
    '20261120_all_city_buyer_requests.sql',
];

$confirmDatabase = null;
$baselineNames = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php database/migrate.php --confirm-database=NAME [--baseline=file1.sql,file2.sql]\n");
        exit(0);
    }
    if (strpos($argument, '--confirm-database=') === 0) {
        $confirmDatabase = substr($argument, strlen('--confirm-database='));
    } elseif (strpos($argument, '--baseline=') === 0) {
        $baselineNames = array_filter(explode(',', substr($argument, strlen('--baseline='))));
    } else {
        fwrite(STDERR, "Unknown option: $argument\n");
        exit(2);
    }
}

if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)
    || $confirmDatabase !== DB_NAME) {
    fwrite(STDERR, "Refusing to run. Pass --confirm-database=" . DB_NAME . " to confirm the configured target.\n");
    exit(2);
}

$pdo = db();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_name VARCHAR(255) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_by ENUM('migration','baseline') NOT NULL,
        PRIMARY KEY (migration_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($baselineNames !== null) {
    if (!$baselineNames) {
        fwrite(STDERR, "The baseline list cannot be empty.\n");
        exit(2);
    }
    $baselineNames = array_values(array_unique($baselineNames));
    if ($baselineNames !== array_slice($migrationOrder, 0, count($baselineNames))) {
        fwrite(STDERR, "Baseline migrations must be a contiguous prefix in the documented order.\n");
        exit(2);
    }
    foreach ($baselineNames as $name) {
        if (!in_array($name, $migrationOrder, true)) {
            fwrite(STDERR, "Unknown migration in baseline list: $name\n");
            exit(2);
        }
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $name;
        $alreadyRecorded = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_name = ?');
        $alreadyRecorded->execute([$name]);
        if ($alreadyRecorded->fetchColumn()) {
            fwrite(STDERR, "Migration is already tracked: $name\n");
            exit(2);
        }
    }
    $insertBaseline = $pdo->prepare(
        "INSERT INTO schema_migrations (migration_name, checksum, applied_by)
         VALUES (?, ?, 'baseline')"
    );
    foreach ($baselineNames as $name) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $name;
        $insertBaseline->execute([$name, hash_file('sha256', $path)]);
    }
    fwrite(STDOUT, "Recorded " . count($baselineNames) . " explicitly confirmed baseline migration(s). No SQL migrations were run.\n");
    exit(0);
}

$trackedCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($trackedCount === 0) {
    fwrite(STDERR, "No migration baseline is recorded. Confirm the database's actual schema history, then run --baseline with only migrations already applied.\n");
    exit(2);
}
$recordedNames = $pdo->query(
    'SELECT migration_name FROM schema_migrations'
)->fetchAll(PDO::FETCH_COLUMN);
$trackedNames = array_values(array_intersect($migrationOrder, $recordedNames));
if (count($trackedNames) !== count($recordedNames)
    || $trackedNames !== array_slice($migrationOrder, 0, count($trackedNames))) {
    fwrite(STDERR, "Migration ledger has a gap or an unknown entry. Reconcile it manually before applying updates.\n");
    exit(2);
}

$mysqlPath = getenv('KALAKAL_MYSQL_BIN');
if (!$mysqlPath) {
    $mysqlPath = realpath(
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe'
    ) ?: '';
}
if ($mysqlPath === '' || !is_file($mysqlPath)) {
    fwrite(STDERR, "MySQL client not found. Set KALAKAL_MYSQL_BIN to the XAMPP mysql.exe path.\n");
    exit(1);
}

$selectMigration = $pdo->prepare(
    'SELECT checksum FROM schema_migrations WHERE migration_name = ?'
);
$recordMigration = $pdo->prepare(
    "INSERT INTO schema_migrations (migration_name, checksum, applied_by)
     VALUES (?, ?, 'migration')"
);
$existingMysqlPassword = getenv('MYSQL_PWD');
if (DB_PASS !== '') {
    putenv('MYSQL_PWD=' . DB_PASS);
}

foreach ($migrationOrder as $name) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "Migration file is missing: $name\n");
        if (DB_PASS !== '') {
            $existingMysqlPassword === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $existingMysqlPassword);
        }
        exit(1);
    }
    $checksum = hash_file('sha256', $path);
    $selectMigration->execute([$name]);
    $appliedChecksum = $selectMigration->fetchColumn();
    if ($appliedChecksum !== false) {
        if (!hash_equals((string) $appliedChecksum, $checksum)) {
            fwrite(STDERR, "Applied migration checksum changed: $name. Reconcile this manually; the migration was not rerun.\n");
            if (DB_PASS !== '') {
                $existingMysqlPassword === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $existingMysqlPassword);
            }
            exit(1);
        }
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Could not read migration: $name\n");
        exit(1);
    }
    $sql = preg_replace('/^\s*USE\s+kalakal\s*;/mi', 'USE ' . DB_NAME . ';', $sql);
    $command = escapeshellarg($mysqlPath)
        . ' --host=' . escapeshellarg(DB_HOST)
        . ' --user=' . escapeshellarg(DB_USER)
        . ' --database=' . escapeshellarg(DB_NAME)
        . ' --default-character-set=utf8mb4 --batch --skip-column-names';
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Could not start MySQL for migration: $name\n");
        exit(1);
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Migration failed: $name\n" . (string) $error . (string) $output);
        if (DB_PASS !== '') {
            $existingMysqlPassword === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $existingMysqlPassword);
        }
        exit($exitCode);
    }
    $recordMigration->execute([$name, $checksum]);
    fwrite(STDOUT, "Applied $name\n");
}

if (DB_PASS !== '') {
    $existingMysqlPassword === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $existingMysqlPassword);
}
fwrite(STDOUT, "All untracked migrations completed.\n");

<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v28 activa modo WAL y synchronous NORMAL en SQLite', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $tmpDb = tempnam(sys_get_temp_dir(), 'ep_test_v28_');
    try {
        $pdo = new PDO('sqlite:' . $tmpDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        migration_v28($pdo);

        $journalMode = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $synchronous = (int) $pdo->query('PRAGMA synchronous')->fetchColumn();

        assert_eq('wal', $journalMode, 'Se esperaba journal_mode = wal');
        assert_eq(1, $synchronous, 'Se esperaba synchronous = 1 (NORMAL)');
    } finally {
        unset($pdo);
        if (file_exists($tmpDb)) {
            @unlink($tmpDb);
        }
        if (file_exists($tmpDb . '-wal')) {
            @unlink($tmpDb . '-wal');
        }
        if (file_exists($tmpDb . '-shm')) {
            @unlink($tmpDb . '-shm');
        }
    }
});


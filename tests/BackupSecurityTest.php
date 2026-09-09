<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/backup_handler.php';

test('allowedMediaImportMimes rechaza extensiones ejecutables', function () {
    assert_eq([], allowedMediaImportMimes('images/payload.php'));
    assert_eq([], allowedMediaImportMimes('audios/config.phtml'));
    assert_eq(['image/jpeg'], allowedMediaImportMimes('images/cover.jpg'));
});

test('validateMediaZipEntry rechaza PHP dentro de images', function () {
    $error = validateMediaZipEntry('images/payload.php', 100, 100, 0);
    assert_contains('tipo de fichero no permitido', $error);
});

test('validateMediaZipEntry rechaza traversal y ficheros ocultos', function () {
    assert_true(validateMediaZipEntry('images/../payload.jpg', 100, 100, 0) !== '');
    assert_true(validateMediaZipEntry('images/.htaccess', 100, 100, 0) !== '');
});

test('validateMediaZipEntry limita tamaño individual y total', function () {
    assert_true(validateMediaZipEntry(
        'audios/large.mp3',
        MEDIA_IMPORT_MAX_FILE_BYTES + 1,
        MEDIA_IMPORT_MAX_FILE_BYTES + 1,
        0
    ) !== '');
    assert_true(validateMediaZipEntry(
        'audios/ok.mp3',
        2,
        2,
        MEDIA_IMPORT_MAX_TOTAL_BYTES - 1
    ) !== '');
});

test('validateMediaZipEntry detecta ratios de compresión peligrosos', function () {
    $error = validateMediaZipEntry('images/bomb.png', 1000000, 100, 0);
    assert_contains('compresión potencialmente peligrosa', $error);
});

test('createDatabaseSnapshot crea una copia integra incluso con transacciones en WAL', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $sourceDb = tempnam(sys_get_temp_dir(), 'ep_snap_src_');
    $snapshotDb = tempnam(sys_get_temp_dir(), 'ep_snap_dst_');

    try {
        $pdo = openPodcastDatabase($sourceDb);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'item1')");
        $reader = openPodcastDatabase($sourceDb);
        $reader->beginTransaction();
        $reader->query('SELECT * FROM items')->fetchAll();
        $pdo->exec("INSERT INTO items VALUES (2, 'item2')");

        $ok = createDatabaseSnapshot($sourceDb, $snapshotDb);
        assert_true($ok, 'createDatabaseSnapshot devolvió false');
        assert_true(file_exists($snapshotDb));

        $checkPdo = new PDO('sqlite:' . $snapshotDb);
        $checkPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $count = (int) $checkPdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        assert_eq(2, $count, 'La instantánea no contiene todas las filas comprometidas');
        assert_eq('ok', $checkPdo->query('PRAGMA integrity_check')->fetchColumn());
    } finally {
        unset($reader, $pdo, $checkPdo);
        foreach ([$sourceDb, $snapshotDb] as $file) {
            if (file_exists($file)) { @unlink($file); }
            if (file_exists($file . '-wal')) { @unlink($file . '-wal'); }
            if (file_exists($file . '-shm')) { @unlink($file . '-shm'); }
        }
    }
});

test('createDatabaseSnapshot limpia destino cuando el origen no existe', function () {
    $nonExistent = sys_get_temp_dir() . '/ep_non_existent_' . bin2hex(random_bytes(6)) . '.sqlite';
    $targetDb = tempnam(sys_get_temp_dir(), 'ep_snap_err_');

    $ok = createDatabaseSnapshot($nonExistent, $targetDb);
    assert_true(!$ok, 'createDatabaseSnapshot debió retornar false con archivo inexistente');
    assert_true(!file_exists($targetDb), 'El destino residual no fue eliminado');
});

test('createDatabaseSnapshot rechaza el origen como destino sin modificarlo', function () {
    $source = tempnam(sys_get_temp_dir(), 'ep_snap_same_');
    try {
        file_put_contents($source, 'contenido que debe conservarse');
        assert_true(!createDatabaseSnapshot($source, $source));
        assert_eq('contenido que debe conservarse', file_get_contents($source));
    } finally {
        unlink($source);
    }
});

test('createDatabaseSnapshot falla sin dejar copia de una base corrupta', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { return; }
    $source = tempnam(sys_get_temp_dir(), 'ep_snap_invalid_');
    $target = tempnam(sys_get_temp_dir(), 'ep_snap_partial_');
    try {
        file_put_contents($source, str_repeat('not a sqlite database', 300));
        assert_true(!createDatabaseSnapshot($source, $target));
        assert_true(!file_exists($target));
    } finally {
        foreach ([$source, $target] as $file) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                if (is_file($file . $suffix)) { unlink($file . $suffix); }
            }
        }
    }
});

foreach ([false, true] as $pdoOnly) {
    test($pdoOnly ? 'VACUUM INTO recupera un destino parcial e incluye el WAL' : 'export_db descarga una instantánea WAL y elimina temporales', function () use ($pdoOnly) {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { return; }
        $root = sys_get_temp_dir() . '/ep_export_' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $source = $root . '/source.db';
        $output = $root . '/download.db';
        try {
            $pdo = openPodcastDatabase($source);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
            $pdo->exec('INSERT INTO items VALUES (1)');
            $reader = openPodcastDatabase($source);
            $reader->beginTransaction();
            $reader->query('SELECT * FROM items')->fetchAll();
            $pdo->exec('INSERT INTO items VALUES (2)');

            if ($pdoOnly) {
                file_put_contents($output, 'restos de un backup nativo fallido');
                assert_true(createDatabaseSnapshotWithPdo($source, $output));
            } else {
                $command = [PHP_BINARY, '-d', 'sys_temp_dir=' . $root, __DIR__ . '/fixtures/backup_export.php', $source];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $root . '/stderr', 'w']], $pipes);
                assert_true(is_resource($process));
                fclose($pipes[0]);
                assert_eq(0, proc_close($process), (string) file_get_contents($root . '/stderr'));
            }
            assert_eq([], glob($root . '/ep_bak_*'));
            $check = openPodcastDatabase($output);
            assert_eq('ok', $check->query('PRAGMA integrity_check')->fetchColumn());
            assert_eq(2, (int) $check->query('SELECT COUNT(*) FROM items')->fetchColumn());
        } finally {
            unset($check, $reader, $pdo);
            foreach (glob($root . '/*') ?: [] as $file) { unlink($file); }
            rmdir($root);
        }
    });
}

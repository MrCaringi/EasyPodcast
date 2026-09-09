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
        $pdo->exec("INSERT INTO items VALUES (2, 'item2')");

        $ok = createDatabaseSnapshot($sourceDb, $snapshotDb);
        assert_true($ok, 'createDatabaseSnapshot devolvió false');
        assert_true(file_exists($snapshotDb));

        $checkPdo = new PDO('sqlite:' . $snapshotDb);
        $checkPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $count = (int) $checkPdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
        assert_eq(2, $count, 'La instantánea no contiene todas las filas comprometidas');
    } finally {
        unset($pdo, $checkPdo);
        foreach ([$sourceDb, $snapshotDb] as $file) {
            if (file_exists($file)) { @unlink($file); }
            if (file_exists($file . '-wal')) { @unlink($file . '-wal'); }
            if (file_exists($file . '-shm')) { @unlink($file . '-shm'); }
        }
    }
});

test('createDatabaseSnapshot no utiliza copy inseguro y limpia destino ante error', function () {
    $nonExistent = sys_get_temp_dir() . '/ep_non_existent_' . bin2hex(random_bytes(6)) . '.sqlite';
    $targetDb = tempnam(sys_get_temp_dir(), 'ep_snap_err_');

    $ok = createDatabaseSnapshot($nonExistent, $targetDb);
    assert_true(!$ok, 'createDatabaseSnapshot debió retornar false con archivo inexistente');
    assert_true(!file_exists($targetDb), 'El destino residual no fue eliminado');
});




<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v29 añade las palabras clave de directorios sin duplicar la columna', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec("INSERT INTO podcast (id, title) VALUES (1, 'Prueba')");

    migration_v29($pdo);
    migration_v29($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(), 'name');
    assert_eq(1, count(array_filter($columns, static fn (string $column): bool => $column === 'oc_itunes_keywords')));
    assert_eq('', $pdo->query('SELECT oc_itunes_keywords FROM podcast WHERE id = 1')->fetchColumn());
});

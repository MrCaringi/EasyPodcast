<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/version.php';

$newerVersion = ((int) explode('.', APP_VERSION)[0] + 1) . '.0.0';
foreach (['nueva versión' => $newerVersion, 'versión actual' => APP_VERSION, 'sin resultado' => ''] as $scenario => $latestVersion) {
    test('el panel Multipodcast muestra el aviso adecuado: ' . $scenario, function () use ($latestVersion, $newerVersion) {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { return; }

        $root = sys_get_temp_dir() . '/ep_update_notice_' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $dbPath = $root . '/test.db';
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
            $pdo->exec("INSERT INTO podcast (id, title, description, link) VALUES (1, 'Test', 'Test', 'https://example.test')");
            $pdo->exec('UPDATE app_settings SET multipodcast_enabled = 1');
            // Usar la caché diaria evita depender de GitHub y sus publicaciones.
            $save = $pdo->prepare('UPDATE podcast SET last_update_check_date = :day, latest_version_checked = :version WHERE id = 1');
            $save->execute([':day' => date('Y-m-d'), ':version' => $latestVersion]);
            unset($save, $pdo);

            $process = proc_open(
                [PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(), '-d', 'session.save_path=' . $root,
                    __DIR__ . '/fixtures/multipodcast_dashboard.php', $dbPath],
                [0 => ['pipe', 'r'], 1 => ['file', $root . '/page.html', 'w'], 2 => ['file', $root . '/stderr', 'w']],
                $pipes
            );
            assert_true(is_resource($process));
            fclose($pipes[0]);
            assert_eq(0, proc_close($process), (string) file_get_contents($root . '/stderr'));

            $html = (string) file_get_contents($root . '/page.html');
            assert_contains('Panel de administración del Multipodcast', $html, 'El test debe cargar el panel real, no una redirección al login.');
            $hasNotice = preg_match('/<div class="update-status-warning admin-update-notice">(.*?)<\/div>/s', $html, $notice) === 1;
            assert_eq($latestVersion === $newerVersion, $hasNotice, 'El aviso debe mostrarse solo cuando hay una versión más nueva.');
            if ($hasNotice) {
                assert_contains('<strong>v' . $latestVersion . '</strong>', $notice[1]);
                assert_contains('href="update.php"', $notice[1]);
                assert_contains('Actualizar ahora', $notice[1]);
            }
        } finally {
            unset($save, $pdo);
            foreach (glob($root . '/*') ?: [] as $file) { unlink($file); }
            rmdir($root);
        }
    });
}

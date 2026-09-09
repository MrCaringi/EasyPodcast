<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/session.php';

// Sesión aislada de administrador global; el panel se renderiza sin mocks.
startSecureSession();
$_SESSION['admin_user'] = 'regression-test';
$_SESSION['admin_is_global'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
putenv('PODCAST_DB_PATH=' . $argv[1]);

try {
    require __DIR__ . '/../../multipodcast.php';
} finally {
    session_destroy();
}

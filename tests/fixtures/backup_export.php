<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/backup_handler.php';

// Ejecutar el handler real en un proceso separado porque termina con exit.
$_GET = ['action' => 'export_db'];
$_SERVER['REQUEST_METHOD'] = 'GET';
loadBackupsData($argv[1], dirname($argv[1]));
fwrite(STDERR, "La exportación no terminó con una descarga.\n");
exit(1);

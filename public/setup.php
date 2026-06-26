<?php
declare(strict_types=1);

/**
 * Platform installer — standalone entry (no router).
 * Steps: language → store → database → admin → done.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

\App\Install\Installer::run();

<?php
declare(strict_types=1);

/**
 * Bootstrap when document root points at project root (not public/).
 * Routes all traffic through public/index.php → setup.php when not yet installed.
 */
require __DIR__ . '/public/index.php';

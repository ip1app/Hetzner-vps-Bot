<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Request;
use App\Services\Installed;
use App\Services\NotFound;

if (!Installed::isInstalled()) {
    Installed::respondUnavailable(Request::capture());
}

if (Installed::isInstalled()) {
    Database::init();
}

NotFound::store(Request::capture());

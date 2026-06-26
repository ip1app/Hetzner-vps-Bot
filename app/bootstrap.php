<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');

require ROOT . '/vendor/autoload.php';

App\Services\Config::load(ROOT . '/.env');
App\Helpers\Url::init();

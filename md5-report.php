<?php

require_once 'vendor/autoload.php';

use Pimple\Container;

$config = new Container();
$config['manx'] = Manx\Manx::getInstance();
$config['vars'] = ($_SERVER['REQUEST_METHOD'] == 'POST') ? $_POST : $_GET;
$page = new Manx\MD5ReportPage($config);
$page->renderPage();

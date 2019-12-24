<?php

require_once 'vendor/autoload.php';

use Pimple\Container;

$config = new Container();
$config['manx'] = Manx\Manx::getInstance();
$config['vars'] = ($_SERVER['REQUEST_METHOD'] == 'POST') ? $_POST : $_GET;
$config['fileSystem'] = new Manx\FileSystem();
$config['whatsNewPageFactory'] = new Manx\WhatsNewPageFactory();
$page = new Manx\BitSaversPage($config);
$page->renderPage();

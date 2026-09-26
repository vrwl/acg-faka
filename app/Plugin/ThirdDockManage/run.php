<?php
declare(strict_types=1);

error_reporting(E_ERROR);
const BASE_PATH = __DIR__ . "/../../../";
require(BASE_PATH . '/vendor/autoload.php');
require(BASE_PATH.'/kernel/Helper.php');
$_SERVER['third_dock_mode'] = true;
//define
define("BASE_APP_SERVER", match ((int)config("store")['server']) {
    0 => App\Service\App::MAIN_SERVER,
    1 => App\Service\App::STANDBY_SERVER1,
    2 => App\Service\App::STANDBY_SERVER2,
    3 => App\Service\App::GENERAL_SERVER
});

\Kernel\Util\Context::set(\Kernel\Consts\Base::ROUTE, "/");
\Kernel\Util\Context::set(\Kernel\Consts\Base::LOCK, (string)file_get_contents(BASE_PATH . "/kernel/Install/Lock"));
\Kernel\Util\Context::set(\Kernel\Consts\Base::IS_INSTALL, file_exists(BASE_PATH . '/kernel/Install/Lock'));
\Kernel\Util\Context::set(\Kernel\Consts\Base::OPCACHE, extension_loaded("Zend OPcache") || extension_loaded("opcache"));
\Kernel\Util\Context::set(\Kernel\Consts\Base::STORE_STATUS, file_exists(BASE_PATH . "/kernel/Plugin.php"));

//初始化数据库
$capsule = new \Illuminate\Database\Capsule\Manager();
$db_config = config('database');
$db_config['options'][PDO::ATTR_PERSISTENT] = true;
$capsule->addConnection($db_config);
$capsule->setAsGlobal();
$capsule->bootEloquent();

//插件库
if (\Kernel\Util\Context::get(\Kernel\Consts\Base::STORE_STATUS) && \Kernel\Util\Context::get(\Kernel\Consts\Base::IS_INSTALL)) {
    require(BASE_PATH."/kernel/Plugin.php");
    //插件初始化
    \Kernel\Plugin\Hook::inst()->load();
    hook(\App\Consts\Hook::KERNEL_INIT);
}

try {
    $app = new \Symfony\Component\Console\Application();

    $app->add(new \App\Plugin\ThirdDockManage\Command\SyncOrder());
    $app->add(new \App\Plugin\ThirdDockManage\Command\SyncGood());

    $app->run();
} catch (\Exception $e) {
    \App\Util\Plugin::log('ThirdDockManage', $e->getMessage());
}

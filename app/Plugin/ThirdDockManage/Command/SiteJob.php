<?php
namespace App\Plugin\ThirdDockManage\Command;

use Amp\Parallel\Worker\Environment;
use Amp\Parallel\Worker\Task;

require __DIR__ . '/../../../../vendor/autoload.php';
require __DIR__ . '/../vendor/autoload.php';

class SiteJob implements Task {
    /**
     * @var callable
     */
    private $function;
    /**
     * @var array
     */
    private array $args;

    public function __construct($function, ...$args) {
        $this->function = $function;
        $this->args = $args;
    }

    /**
     * {@inheritdoc}
     */
    public function run(Environment $environment)
    {
        $function = $this->function;

        return $this->$function($this->args);
    }

    public function syncGood($site) {
        $site = head($site);
        if (!defined("BASE_PATH")) {
            define("BASE_PATH", __DIR__.'/../../../../');
        }
        require_once(BASE_PATH . '/vendor/autoload.php');
        require_once(BASE_PATH.'/kernel/Helper.php');
        $_SERVER['third_dock_mode'] = true;
        //初始化数据库
        $capsule = new \Illuminate\Database\Capsule\Manager();
        // 创建链接
        $capsule->addConnection(config('database'));
        // 设置全局静态可访问
        $capsule->setAsGlobal();
        // 启动Eloquent
        $capsule->bootEloquent();


        $sync_good = new SyncGood();
        return $sync_good->aloneSiteData($site);
    }
}

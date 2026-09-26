<?php
declare (strict_types=1);

namespace Kernel\Plugin;

use App\Util\Client;
use Kernel\Component\Singleton;
use Kernel\Consts\Base;
use Kernel\Util\Context;
use Kernel\Util\File;
use Kernel\Util\Plugin;

class Hook
{

    use Singleton;

    public const CACHE_FILE = BASE_PATH . "/runtime/plugin/hook";


    /**
     * @return void
     * @throws \SmartyException
     */
    public function load(): void
    {
        if (!defined('_APP_STORE_LOAD_STATE') || \_APP_STORE_LOAD_STATE !== true) {
            return;
        }

        $path = BASE_PATH . "/runtime/plugin/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        //自愈：注册表缺失或损坏（含旧加密格式）时，按各插件 STATUS=1 自动重建
        $raw = is_file(Hook::CACHE_FILE) ? (string)file_get_contents(Hook::CACHE_FILE) : '';
        if (!is_array(json_decode($raw, true))) {
            _plugin_hook_rebuild();
        }

        if (!is_writable(Hook::CACHE_FILE)) {
            return;
        }

        $hooks = File::read(Hook::CACHE_FILE, function (string $contents) {
            return json_decode($contents, true) ?: [];
        }) ?: [];

        foreach ($hooks as $points) {
            foreach ($points as $a => $point) {
                foreach ($point as $plugin) {
                    Plugin::$container['hook'][$a][] = ["namespace" => $plugin['namespace'], "method" => $plugin['method'], "pluginName" => $plugin['pluginName']];
                }
            }
        }

        $route = explode("/", trim(Context::get(Base::ROUTE), "/"));
        if (strtolower($route[0]) == "plugin") {
            $pluginName = ucfirst($route[1]);
            $pluginCfg = Plugin::getPlugin($pluginName);
            if ($pluginCfg['PLUGIN_CONFIG']['STATUS'] != 1) {
                Client::redirect("/", "当前插件未启用");
            }
        }
    }

    /**
     * @param string $name
     * @return void
     */
    public function del(string $name): void
    {
        _plugin_hook_del($name);
    }


    /**
     * @param string $name
     * @return void
     */
    public function add(string $name): void
    {
        _plugin_hook_add($name);
    }


    /**
     * @param string $name
     * @param int $point
     * @param string $namespace
     * @param string $method
     * @return bool
     */
    public function exist(string $name, int $point, string $namespace, string $method): bool
    {
        return _plugin_hook_exist($name, $point, $namespace, $method);
    }
}
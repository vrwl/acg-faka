<?php
declare (strict_types=1);

namespace Kernel\Plugin;

use App\Util\Client;
use Kernel\Component\Singleton;
use Kernel\Consts\Base;
use Kernel\Util\Context;
use Kernel\Util\Plugin;

class Hook
{

    use Singleton;


    /**
     * @return void
     * @throws \SmartyException
     */
    public function load(): void
    {
        //离线插件运行时（kernel/Plugin/Local.php）加载完成后再工作
        if (!defined('_PLUGIN_RUNTIME_LOADED') || \_PLUGIN_RUNTIME_LOADED !== true) {
            return;
        }

        $path = BASE_PATH . "/runtime/plugin/";
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        //自愈：注册表缺失（首次运行/由商店加密注册表迁移）时，
        //按各插件 Config 的 STATUS 重建，已启用插件无需手动重装
        if (!is_file(_plugin_registry_file())) {
            foreach (Plugin::getPlugins(false) as $plugin) {
                if ((int)($plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] ?? 0) === 1) {
                    _plugin_hook_add((string)$plugin[\App\Consts\Plugin::PLUGIN_NAME]);
                }
            }
        }

        foreach (_plugin_registry_read() as $points) {
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
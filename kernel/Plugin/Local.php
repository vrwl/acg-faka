<?php
declare(strict_types=1);

/**
 * charity 分支：本地明文插件运行时
 *
 * 替代已删除的官方加密运行时 kernel/Plugin.php，提供相同的 _plugin_* 函数签名，
 * 但不做任何应用商店授权校验：插件的启动/停止/钩子注册完全在本地完成，
 * 插件只需放入 app/Plugin/{Key} 目录即可在后台启用，与商店解耦。
 *
 * 钩子注册表为明文 JSON：runtime/plugin/hook，结构 {"hook": {point: [entry]}}，
 * entry = {"namespace": 钩子类全限定名, "method": 方法名, "pluginName": 插件标识}。
 *
 * 本文件为全新实现，未包含官方加密运行时的任何代码。
 */

use App\Consts\Plugin as PluginConst;
use Kernel\Annotation\Plugin as PluginAnnotation;
use Kernel\Util\File;
use Kernel\Util\Plugin;

if (!defined('_APP_STORE_LOAD_STATE')) {
    //插件运行时已装载标记：Hook::load 与后台界面据此判断插件系统可用，本地运行时恒为 true
    define('_APP_STORE_LOAD_STATE', true);
}

if (!function_exists('_plugin_get_hwid')) {
    /**
     * 本机标识：由安装锁文件 + 数据库连接信息 + 运行环境派生的稳定指纹
     * @return string
     */
    function _plugin_get_hwid(): string
    {
        $lockFile = BASE_PATH . '/kernel/Install/Lock';
        $lock = is_file($lockFile) ? (string)file_get_contents($lockFile) : '';
        $db = (array)config('database');
        return strtoupper(md5($lock . '|' . ($db['host'] ?? '') . '|' . ($db['database'] ?? '') . '|' . php_uname()));
    }
}

if (!function_exists('_plugin_registry_path')) {
    /**
     * 钩子注册表文件路径（与 \Kernel\Plugin\Hook::CACHE_FILE 一致）
     * @return string
     */
    function _plugin_registry_path(): string
    {
        return BASE_PATH . '/runtime/plugin/hook';
    }
}

if (!function_exists('_plugin_registry_read')) {
    /**
     * 读取明文 JSON 注册表，损坏/缺失时返回空结构
     * @return array
     */
    function _plugin_registry_read(): array
    {
        $file = _plugin_registry_path();
        if (!is_file($file)) {
            return ['hook' => []];
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !isset($data['hook']) || !is_array($data['hook'])) {
            return ['hook' => []];
        }
        return $data;
    }
}

if (!function_exists('_plugin_registry_write')) {
    /**
     * 写入明文 JSON 注册表
     * @param array $data
     * @return void
     */
    function _plugin_registry_write(array $data): void
    {
        $file = _plugin_registry_path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (!function_exists('_plugin_hook_exist')) {
    /**
     * 判断某插件的某个钩子方法是否已注册
     * @param string $name 插件标识
     * @param int $point 钩子点
     * @param string $namespace 钩子类全限定名
     * @param string $method 方法名
     * @return bool
     */
    function _plugin_hook_exist(string $name, int $point, string $namespace, string $method): bool
    {
        $registry = _plugin_registry_read();
        foreach ((array)($registry['hook'][$point] ?? []) as $item) {
            if ($item['pluginName'] === $name && $item['namespace'] === $namespace && $item['method'] === $method) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('_plugin_hook_del')) {
    /**
     * 从注册表移除某插件的全部钩子
     * @param string $name 插件标识
     * @return void
     */
    function _plugin_hook_del(string $name): void
    {
        $registry = _plugin_registry_read();
        $changed = false;
        foreach ($registry['hook'] as $point => $items) {
            $keep = array_values(array_filter((array)$items, static fn($item) => $item['pluginName'] !== $name));
            if (count($keep) !== count((array)$items)) {
                $changed = true;
            }
            if ($keep === []) {
                unset($registry['hook'][$point]);
            } else {
                $registry['hook'][$point] = $keep;
            }
        }
        if ($changed) {
            _plugin_registry_write($registry);
        }
    }
}

if (!function_exists('_plugin_hook_add')) {
    /**
     * 扫描插件 Hook 目录中标注 #[Hook(point)] 的公开方法并注册（幂等：先清后加）
     * @param string $name 插件标识
     * @return void
     */
    function _plugin_hook_add(string $name): void
    {
        _plugin_hook_del($name);

        $dir = BASE_PATH . "/app/Plugin/{$name}/Hook/";
        if (!is_dir($dir)) {
            return;
        }

        $registry = _plugin_registry_read();
        foreach (File::scan($dir, true) as $file) {
            $className = trim((string)explode('.', (string)$file)[0]);
            if ($className === '') {
                continue;
            }
            $namespace = "\\App\\Plugin\\{$name}\\Hook\\{$className}";
            if (!class_exists($namespace)) {
                continue;
            }
            $reflectionClass = new \ReflectionClass($namespace);
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes(\Kernel\Annotation\Hook::class) as $attribute) {
                    $arguments = $attribute->getArguments();
                    $point = (int)($arguments['point'] ?? $arguments[0] ?? 0);
                    if ($point <= 0) {
                        continue;
                    }
                    $registry['hook'][$point][] = [
                        'namespace' => $namespace,
                        'method' => $reflectionMethod->getName(),
                        'pluginName' => $name,
                    ];
                }
            }
        }
        _plugin_registry_write($registry);
    }
}

if (!function_exists('_plugin_hook_rebuild')) {
    /**
     * 自愈：注册表缺失/损坏时，按各插件 STATUS=1 自动重建
     * @return void
     */
    function _plugin_hook_rebuild(): void
    {
        _plugin_registry_write(['hook' => []]);
        $dir = BASE_PATH . '/app/Plugin/';
        if (!is_dir($dir)) {
            return;
        }
        foreach (File::scan($dir) as $name) {
            $plugin = Plugin::getPlugin($name, false);
            if (!$plugin) {
                continue;
            }
            if ((int)($plugin[PluginConst::PLUGIN_CONFIG]['STATUS'] ?? 0) !== 1) {
                continue;
            }
            _plugin_hook_add($name);
        }
    }
}

if (!function_exists('_plugin_start')) {
    /**
     * 启动插件：注册钩子 + STATUS=1 + 触发插件 START 生命周期。不校验商店授权。
     * @param string $name 插件标识
     * @param bool $force 兼容官方签名（官方用于主题等免授权场景），本地运行时无授权可跳过，仅占位
     * @return void
     */
    function _plugin_start(string $name, bool $force = false): void
    {
        $plugin = Plugin::getPlugin($name, false);
        if (!$plugin) {
            //非插件目录（如 _plugin_start($userTheme, true) 传入的主题名）直接忽略
            return;
        }

        $wasRunning = (int)($plugin[PluginConst::PLUGIN_CONFIG]['STATUS'] ?? 0) === 1;

        _plugin_hook_add($name);
        setConfig(['STATUS' => 1], BASE_PATH . '/app/Plugin/' . $name . '/Config/Config.php');

        if (!$wasRunning) {
            Plugin::runHookState($name, PluginAnnotation::START);
        }
    }
}

if (!function_exists('_plugin_stop')) {
    /**
     * 停止插件：移除全部钩子 + STATUS=0 + 触发插件 STOP 生命周期
     * @param string $name 插件标识
     * @return void
     */
    function _plugin_stop(string $name): void
    {
        $plugin = Plugin::getPlugin($name, false);
        if (!$plugin) {
            return;
        }

        $wasRunning = (int)($plugin[PluginConst::PLUGIN_CONFIG]['STATUS'] ?? 0) === 1;

        _plugin_hook_del($name);
        setConfig(['STATUS' => 0], BASE_PATH . '/app/Plugin/' . $name . '/Config/Config.php');

        if ($wasRunning) {
            Plugin::runHookState($name, PluginAnnotation::STOP);
        }
    }
}

if (!function_exists('_plugin_download')) {
    /**
     * 在线下载插件：纯本地版不提供该能力，保留函数签名仅为兼容内核调用方。
     * 插件请通过后台「通用插件 → 本地上传插件」安装，或手动解压到对应目录
     * @param int $pluginId
     * @param string $type
     * @return false
     */
    function _plugin_download(int $pluginId, string $type = ''): bool
    {
        $GLOBALS['__acg_err'] = '当前为纯本地版，请通过后台「本地上传插件」安装，或手动解压到对应目录';
        return false;
    }
}

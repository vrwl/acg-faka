<?php
declare(strict_types=1);

/**
 * 离线插件运行时（本地 fork 专用，MIT）。
 *
 * 本文件替代已被整体移除的 kernel/Plugin.php（应用商店授权组件），
 * 为全部调用方提供同签名的全局函数，使插件完全脱离应用商店授权即可运行：
 *
 *   _plugin_get_hwid()                            安装指纹（原商店鉴权用，现仅作稳定标识）
 *   _plugin_hook_add($name)                       注册插件钩子到注册表
 *   _plugin_hook_del($name)                       从注册表移除插件钩子
 *   _plugin_hook_exist($name,$point,$ns,$method)  查询钩子是否已注册
 *   _plugin_start($id, $theme=false)              启用插件（无商店授权校验）
 *   _plugin_stop($id)                             停用插件
 *   _plugin_download($pluginId, $mode)            商店下载（离线模式恒返回 false）
 *
 * 钩子注册表存储于 runtime/plugin/hook.json（明文 JSON），结构与原加密
 * 注册表解包后一致：{ 插件名: { 钩子点位: [ {namespace, method, pluginName} ] } }
 *
 * 本文件不包含、也不引用任何原加密组件的代码，全部为全新实现。
 */

use Kernel\Annotation\Hook as HookAnnotation;
use Kernel\Util\File;

if (!defined('_PLUGIN_RUNTIME_LOADED')) {
    define('_PLUGIN_RUNTIME_LOADED', true);
}

//让后台「通用插件」菜单（Header.html #{if $_app_store_load_state}）直接显示。
//商店菜单本身由 $_store_initialize（kernel/Plugin.php 是否存在）控制，已随本
//方案整体移除，后台顶栏会显示「离线版」标识而非应用商店入口，不会误导。
if (!defined('_APP_STORE_LOAD_STATE')) {
    define('_APP_STORE_LOAD_STATE', true);
}

if (!function_exists('_plugin_registry_file')) {
    /**
     * 钩子注册表文件路径
     */
    function _plugin_registry_file(): string
    {
        return BASE_PATH . "/runtime/plugin/hook.json";
    }
}

if (!function_exists('_plugin_registry_read')) {
    /**
     * 读取钩子注册表，文件缺失或损坏时返回空数组
     */
    function _plugin_registry_read(): array
    {
        $file = _plugin_registry_file();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('_plugin_registry_write')) {
    /**
     * 写入钩子注册表（失败静默，运行时容器仍可用，下次请求重试）
     */
    function _plugin_registry_write(array $registry): void
    {
        $dir = dirname(_plugin_registry_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(_plugin_registry_file(), json_encode($registry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }
}

if (!function_exists('_plugin_get_hwid')) {
    /**
     * 安装指纹：基于安装锁文件的稳定标识。
     * 原版用于商店鉴权与注册表加密密钥；离线模式下仅保留稳定标识语义。
     */
    function _plugin_get_hwid(): string
    {
        static $hwid = null;
        if ($hwid !== null) {
            return $hwid;
        }
        $lockFile = BASE_PATH . '/kernel/Install/Lock';
        $seed = is_file($lockFile) ? (string)file_get_contents($lockFile) : __DIR__;
        $hwid = strtoupper(substr(md5($seed), 0, 16));
        return $hwid;
    }
}

if (!function_exists('_plugin_hook_add')) {
    /**
     * 注册插件的全部 #[Hook] 注解方法到注册表（幂等：先移除旧条目再注册）
     *
     * @throws ReflectionException
     */
    function _plugin_hook_add(string $name): void
    {
        $registry = _plugin_registry_read();
        unset($registry[$name]);

        $hookDir = BASE_PATH . "/app/Plugin/{$name}/Hook/";
        if (is_dir($hookDir)) {
            $points = [];
            foreach (File::scan($hookDir, true) as $classFile) {
                $_class = explode(".", $classFile);
                $_className = trim((string)$_class[0]);
                $namespace = "\\App\\Plugin\\{$name}\\Hook\\{$_className}";
                if (!class_exists($namespace)) {
                    continue;
                }
                $reflectionClass = new ReflectionClass($namespace);
                foreach ($reflectionClass->getMethods() as $method) {
                    foreach ($method->getAttributes() as $attribute) {
                        //is_a 对同名类也返回 true，同时兼容 #[Hook] 的子类注解
                        if (!is_a($attribute->getName(), HookAnnotation::class, true)) {
                            continue;
                        }
                        $arguments = $attribute->getArguments();
                        $point = $arguments['point'] ?? $arguments[0] ?? null;
                        if ($point === null) {
                            continue;
                        }
                        $points[(string)$point][] = [
                            "namespace" => $namespace,
                            "method" => $method->getName(),
                            "pluginName" => $name,
                        ];
                    }
                }
            }
            if ($points !== []) {
                $registry[$name] = $points;
            }
        }

        _plugin_registry_write($registry);
    }
}

if (!function_exists('_plugin_hook_del')) {
    /**
     * 从注册表移除插件的全部钩子
     */
    function _plugin_hook_del(string $name): void
    {
        $registry = _plugin_registry_read();
        if (!isset($registry[$name])) {
            return;
        }
        unset($registry[$name]);
        _plugin_registry_write($registry);
    }
}

if (!function_exists('_plugin_hook_exist')) {
    /**
     * 查询插件在指定点位的钩子是否已注册
     */
    function _plugin_hook_exist(string $name, int $point, string $namespace, string $method): bool
    {
        $registry = _plugin_registry_read();
        foreach ($registry[$name][(string)$point] ?? [] as $entry) {
            if ($entry['namespace'] === $namespace && $entry['method'] === $method) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('_plugin_start')) {
    /**
     * 启用插件：注册钩子 → STATUS=1 → 触发 START 生命周期。
     * 与原版差异：不再向应用商店校验授权，本地插件无条件启用。
     *
     * @throws ReflectionException
     */
    function _plugin_start(string $id, bool $theme = false): void
    {
        //主题无钩子与 STATUS 概念（激活即生效），离线运行时无需处理
        if ($theme) {
            return;
        }
        if (!is_dir(BASE_PATH . "/app/Plugin/{$id}")) {
            return;
        }

        _plugin_hook_add($id);

        $configFile = BASE_PATH . "/app/Plugin/{$id}/Config/Config.php";
        setConfig(['STATUS' => 1], $configFile);

        \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::START);
    }
}

if (!function_exists('_plugin_stop')) {
    /**
     * 停用插件：触发 STOP 生命周期 → 移除钩子 → STATUS=0
     *
     * @throws ReflectionException
     */
    function _plugin_stop(string $id): void
    {
        if (!is_dir(BASE_PATH . "/app/Plugin/{$id}")) {
            return;
        }

        //STOP 钩子尽力而为：单个插件的生命周期异常不应导致插件无法停用
        try {
            \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::STOP);
        } catch (Throwable $e) {
            debug("插件({$id}) STOP 钩子异常：" . $e->getMessage());
        }

        _plugin_hook_del($id);

        $configFile = BASE_PATH . "/app/Plugin/{$id}/Config/Config.php";
        setConfig(['STATUS' => 0], $configFile);
    }
}

if (!function_exists('_plugin_download')) {
    /**
     * 从应用商店下载插件包：离线模式恒定失败，错误信息经 $GLOBALS['__acg_err']
     * 传递给调用方（App\Service\Bind\App 已实现该错误通道）
     */
    function _plugin_download(int $pluginId, string $mode = "install"): bool
    {
        $GLOBALS['__acg_err'] = "离线运行时不支持从应用商店下载插件（#{$pluginId}）。"
            . "请将插件目录放置到 app/Plugin/（支付插件 app/Pay/、模板 app/View/User/Theme/）后手动启用";
        return false;
    }
}

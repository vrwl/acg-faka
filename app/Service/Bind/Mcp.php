<?php
declare(strict_types=1);

namespace App\Service\Bind;

use Kernel\Exception\JSONException;

/**
 * 本机通用插件的 MCP 运维工具实现（纯本地，不经应用商店）。
 *
 * 直接操作本机 app/Plugin 下的通用插件，与后台「功能插件」页同一套内核流程
 * （_plugin_start/_plugin_stop、SAVE_CONFIG 钩子链、runtime.log 约定）。
 * 安全边界：plugin_key 白名单校验 + realpath 圈禁；配置读取默认脱敏（防提示注入
 * 拖走支付密钥），reveal_sensitive=true 才给真实值且会被审计；STATUS 不允许经
 * config_set 绕过启停校验。
 *
 * @package App\Service\Bind
 */
class Mcp implements \App\Service\Mcp
{
    /**
     * @return array
     */
    public function tools(): array
    {
        return [
            [
                "name" => "local_plugins",
                "description" => "列出本机已安装的通用插件（app/Plugin 目录）及运行状态。本地运维类工具（启停/配置/日志）用这里的 plugin_key 定位插件。status：1=运行中，0=已停止。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => new \stdClass(),
                ],
            ],
            [
                "name" => "plugin_start",
                "description" => "启动本机的一个通用插件（等同后台「功能插件」页的启动按钮）。已在运行的插件直接返回，不重复启动。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_stop",
                "description" => "停止本机的一个通用插件（等同后台「功能插件」页的停止按钮）。已停止的插件直接返回。注意：停止会即刻移除该插件的所有钩子，站点相关功能随之下线。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_config_get",
                "description" => "读取本机通用插件的配置（Config/Config.php）。默认对密钥/令牌类字段脱敏为 ***；确需真实值时传 reveal_sensitive=true（该行为会被审计记录）。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "reveal_sensitive" => ["type" => "boolean", "default" => false, "description" => "true=返回敏感字段真实值（默认脱敏）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_config_set",
                "description" => "修改本机通用插件的配置：传入要变更的键值对，未提及的键保持不变（合并写入，与后台保存配置行为一致，同样会触发插件的 SAVE_CONFIG 钩子）。STATUS（运行状态）不允许在这里改，请用 plugin_start / plugin_stop。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "config" => ["type" => "object", "description" => "要变更的配置键值对，例如 {\"mode\":\"1\",\"api_url\":\"https://...\"}"],
                    ],
                    "required" => ["plugin_key", "config"],
                ],
            ],
            [
                "name" => "plugin_log_read",
                "description" => "读取本机通用插件的运行日志（插件目录下的 runtime.log，与后台「日志」按钮同源）。返回末尾 N 行。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                        "lines" => ["type" => "integer", "minimum" => 1, "maximum" => 2000, "default" => 200, "description" => "返回末尾多少行，默认 200"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
            [
                "name" => "plugin_log_clear",
                "description" => "清空本机通用插件的运行日志（删除插件目录下的 runtime.log，插件下次写日志时会自动重建）。清空不可恢复。",
                "inputSchema" => [
                    "type" => "object",
                    "properties" => [
                        "plugin_key" => ["type" => "string", "description" => "插件标识（来自 local_plugins）"],
                    ],
                    "required" => ["plugin_key"],
                ],
            ],
        ];
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return array
     * @throws JSONException
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            "local_plugins" => $this->localPlugins(),
            "plugin_start" => $this->pluginStart($arguments),
            "plugin_stop" => $this->pluginStop($arguments),
            "plugin_config_get" => $this->pluginConfigGet($arguments),
            "plugin_config_set" => $this->pluginConfigSet($arguments),
            "plugin_log_read" => $this->pluginLogRead($arguments),
            "plugin_log_clear" => $this->pluginLogClear($arguments),
            default => throw new JSONException("未知的工具：{$name}"),
        };
    }

    /* ==================== 本地插件运维（不经商店） ==================== */

    /**
     * @return array
     */
    private function localPlugins(): array
    {
        $plugins = (array)\Kernel\Util\Plugin::getPlugins(false);
        $rows = [];
        foreach ($plugins as $plugin) {
            $key = (string)($plugin['PLUGIN_NAME'] ?? "");
            if ($key === "") {
                continue;
            }
            $log = BASE_PATH . "/app/Plugin/{$key}/runtime.log";
            $rows[] = [
                "plugin_key" => $key,
                "name" => (string)($plugin[\App\Consts\Plugin::NAME] ?? $key),
                "version" => (string)($plugin[\App\Consts\Plugin::VERSION] ?? ""),
                "status" => (int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0),
                "log_bytes" => is_file($log) ? (int)filesize($log) : 0,
            ];
        }
        return ["total" => count($rows), "plugins" => $rows];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginStart(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        if ((int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0) === 1) {
            return ["message" => "插件「{$key}」已在运行，无需启动"];
        }

        //内核启动流程：挂钩子 → STATUS=1 → 触发 START 生命周期。
        //启动失败时它不报错、也不改状态，所以完成后必须回读真实状态如实汇报
        \_plugin_start($key);

        $fresh = \Kernel\Util\Plugin::getPlugin($key, false);
        $status = (int)($fresh['PLUGIN_CONFIG']['STATUS'] ?? 0);
        if ($status !== 1) {
            throw new JSONException("插件「{$key}」未能启动：请检查插件目录与 Config/Config.php 是否完整");
        }
        return ["message" => "插件「{$key}」已启动", "status" => 1];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginStop(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        if ((int)($plugin['PLUGIN_CONFIG']['STATUS'] ?? 0) !== 1) {
            return ["message" => "插件「{$key}」本来就处于停止状态"];
        }

        \_plugin_stop($key);
        return ["message" => "插件「{$key}」已停止，其钩子已全部移除", "status" => 0];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginConfigGet(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);
        $config = (array)($plugin['PLUGIN_CONFIG'] ?? []);
        $reveal = (bool)($args['reveal_sensitive'] ?? false);

        return [
            "plugin_key" => $key,
            "status" => (int)($config['STATUS'] ?? 0),
            "sensitive_masked" => !$reveal,
            "config" => $reveal ? $config : maskSensitive($config),
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginConfigSet(array $args): array
    {
        [$key, $plugin] = $this->resolveLocalPlugin($args);

        $map = $args['config'] ?? null;
        if (!is_array($map) || $map === []) {
            throw new JSONException("config 必须是非空对象");
        }

        //运行状态必须走 plugin_start/plugin_stop（那里有授权校验和钩子增删），不许在这儿绕过
        foreach (["STATUS"] as $forbidden) {
            if (array_key_exists($forbidden, $map)) {
                throw new JSONException("{$forbidden} 不允许通过配置修改，请使用 plugin_start / plugin_stop");
            }
        }

        $config = (array)($plugin['PLUGIN_CONFIG'] ?? []);
        $changed = [];
        foreach ($map as $k => $v) {
            if (!is_string($k) || $k === "") {
                throw new JSONException("配置键必须是字符串");
            }
            //与后台一致：标量一律落成字符串（配置文件里的既有值全是 '1' 这种形式），数组原样保留
            if (is_scalar($v) || $v === null) {
                $config[$k] = is_bool($v) ? ($v ? "1" : "0") : (string)$v;
            } else {
                $config[$k] = $v;
            }
            $changed[] = $k;
        }

        //与后台保存插件配置同一条钩子链，插件对配置变更的自定义处理不会被绕过
        hook(\App\Consts\Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $key, $map);
        \Kernel\Util\Plugin::runHookState($key, \Kernel\Annotation\Plugin::SAVE_CONFIG, $key, $map);

        setConfig($config, BASE_PATH . "/app/Plugin/{$key}/Config/Config.php");

        return [
            "message" => "插件「{$key}」配置已保存",
            "changed_keys" => $changed,
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginLogRead(array $args): array
    {
        [$key] = $this->resolveLocalPlugin($args);
        $lines = (int)($args['lines'] ?? 200);
        $lines = min(2000, max(1, $lines));

        $log = $this->pluginLogPath($key);
        if (!is_file($log)) {
            return ["plugin_key" => $key, "exists" => false, "message" => "该插件暂无日志"];
        }

        //只读末尾一段，防止超大日志把整条响应撑爆
        $size = (int)filesize($log);
        $window = 512 * 1024;
        $fp = fopen($log, "r");
        if ($fp === false) {
            throw new JSONException("日志读取失败（检查文件权限）");
        }
        if ($size > $window) {
            fseek($fp, $size - $window);
            fgets($fp); //丢掉可能被截断的半行
        }
        $tail = (string)stream_get_contents($fp);
        fclose($fp);

        $all = preg_split('/\r\n|\r|\n/', rtrim($tail, "\r\n"));
        $all = $all === false ? [] : $all;
        $slice = array_slice($all, -$lines);

        return [
            "plugin_key" => $key,
            "exists" => true,
            "total_bytes" => $size,
            "returned_lines" => count($slice),
            "truncated" => $size > $window || count($all) > count($slice),
            "log" => implode("\n", $slice),
        ];
    }

    /**
     * @param array $args
     * @return array
     * @throws JSONException
     */
    private function pluginLogClear(array $args): array
    {
        [$key] = $this->resolveLocalPlugin($args);
        $log = $this->pluginLogPath($key);

        if (!is_file($log)) {
            return ["plugin_key" => $key, "message" => "该插件本来就没有日志文件"];
        }

        $bytes = (int)filesize($log);
        if (!unlink($log)) {
            throw new JSONException("日志清空失败（检查文件权限）");
        }
        return ["plugin_key" => $key, "message" => "已清空日志（原 " . round($bytes / 1024, 1) . " KB），插件下次写日志时会自动重建"];
    }

    /**
     * 校验 plugin_key 并装载本地插件（含最新 STATUS）。
     * 返回 [插件标识, 插件数据]。
     *
     * @param array $args
     * @return array{0: string, 1: array}
     * @throws JSONException
     */
    private function resolveLocalPlugin(array $args): array
    {
        $key = trim((string)($args['plugin_key'] ?? ""));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key)) {
            throw new JSONException("plugin_key 不合法");
        }

        $dir = realpath(BASE_PATH . "/app/Plugin/{$key}");
        $root = realpath(BASE_PATH . "/app/Plugin");
        if ($dir === false || $root === false || !is_dir($dir)
            || !str_starts_with($dir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new JSONException("本机没有安装插件「{$key}」");
        }

        $plugin = \Kernel\Util\Plugin::getPlugin($key, false);
        if ($plugin === null) {
            throw new JSONException("插件「{$key}」缺少 Config/Info.php，不是有效的通用插件");
        }

        return [$key, $plugin];
    }

    /**
     * 插件日志固定路径（app/Plugin/<key>/runtime.log），拒绝软链，防穿越。
     *
     * @param string $key
     * @return string
     * @throws JSONException
     */
    private function pluginLogPath(string $key): string
    {
        $dir = realpath(BASE_PATH . "/app/Plugin/{$key}");
        if ($dir === false) {
            throw new JSONException("插件目录不存在");
        }
        $log = $dir . DIRECTORY_SEPARATOR . "runtime.log";
        if (is_link($log)) {
            throw new JSONException("插件日志路径不安全（软链接）");
        }
        if (file_exists($log)) {
            $real = realpath($log);
            if ($real === false || dirname($real) !== $dir) {
                throw new JSONException("插件日志路径不安全");
            }
        }
        return $log;
    }

}

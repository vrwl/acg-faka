<?php
declare(strict_types=1);

namespace App\Util;

use App\Consts\Plugin as PluginConst;
use Kernel\Annotation\Plugin as PluginAnnotation;
use Kernel\Exception\JSONException;
use Kernel\Util\Plugin;
use Kernel\Util\SQL;

/**
 * 本地扩展管理器：通用插件 / 支付插件 / 网站模板的安装、更新与卸载。
 *
 * 纯本地实现 —— 安装包来自后台上传的 zip，既不再向应用商店下载，也不再做授权校验。
 * 三种扩展的目录与标识文件约定：
 *   type=0 通用插件  app/Plugin/{key}/Config/Info.php
 *   type=1 支付插件  app/Pay/{key}/Config/Info.php
 *   type=2 网站模板  app/View/User/Theme/{key}/Config.php
 *
 * 包内允许两种结构：单层「{扩展标识}/...」目录（标识取目录名），或把文件直接放在
 * 压缩包根目录 —— 后者无法推断标识，需要调用方显式传入 plugin_key。
 */
class Extension
{
    public const TYPE_PLUGIN = 0;
    public const TYPE_PAY = 1;
    public const TYPE_THEME = 2;

    public const TYPES = [self::TYPE_PLUGIN, self::TYPE_PAY, self::TYPE_THEME];

    /** 上传包体积上限 */
    public const MAX_PACKAGE_BYTES = 32 * 1024 * 1024;

    /**
     * @param int $type
     * @return bool
     */
    public static function isValidType(int $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * 扩展标识：目录名 + PHP 命名空间片段，限制在安全字符集内
     * @param string $key
     * @return bool
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $key) === 1;
    }

    /**
     * @param string $key
     * @param int $type
     * @return string
     */
    public static function targetDir(string $key, int $type): string
    {
        return match ($type) {
            self::TYPE_PAY => BASE_PATH . "/app/Pay/{$key}",
            self::TYPE_THEME => BASE_PATH . "/app/View/User/Theme/{$key}",
            default => BASE_PATH . "/app/Plugin/{$key}",
        };
    }

    /**
     * 标识文件：用来确认包确实是对应类型的扩展，也用来判断扩展是否已安装
     * @param int $type
     * @return string
     */
    public static function marker(int $type): string
    {
        return $type === self::TYPE_THEME ? 'Config.php' : 'Config/Info.php';
    }

    /**
     * 从上传的 zip 安装或更新一个扩展。
     *
     * @param array $file $_FILES 里的单文件结构
     * @param int $type
     * @param string $givenKey 调用方提供的扩展标识，包内自带单层目录时可留空
     * @return array{key: string, action: string}
     * @throws JSONException
     */
    public static function installFromUpload(array $file, int $type, string $givenKey = ''): array
    {
        if (!self::isValidType($type)) {
            throw new JSONException("不支持的扩展类型");
        }

        $tmp = self::receiveUpload($file);
        $stage = BASE_PATH . '/runtime/tmp/ext_' . bin2hex(random_bytes(8));

        try {
            [$key, $prefix] = self::resolvePackage($tmp, $type, $givenKey);

            if (!is_dir($stage) && !mkdir($stage, 0777, true) && !is_dir($stage)) {
                throw new JSONException("无法创建临时目录，请检查 runtime/tmp 写入权限");
            }

            if (!Zip::unzip($tmp, $stage)) {
                throw new JSONException("解压缩失败，请检查程序是否有写入权限");
            }

            $source = $stage . ($prefix === '' ? '' : '/' . $prefix);
            $marker = self::marker($type);
            if (!is_file($source . '/' . $marker)) {
                throw new JSONException("插件包结构不正确：未找到 {$marker}");
            }

            $target = self::targetDir($key, $type);
            $action = is_file($target . '/' . $marker) ? 'update' : 'install';

            return self::deploy($source, $target, $key, $type, $action);
        } finally {
            if (is_dir($stage)) {
                File::delDirectory($stage);
            }
            @unlink($tmp);
        }
    }

    /**
     * 卸载：删除扩展目录，并清掉它带来的词条与编译缓存
     * @param string $key
     * @param int $type
     * @throws JSONException
     */
    public static function uninstall(string $key, int $type): void
    {
        if (!self::isValidType($type)) {
            throw new JSONException("不支持的扩展类型");
        }
        if (!self::isValidKey($key)) {
            throw new JSONException("扩展标识不合法");
        }

        if ($type === self::TYPE_PLUGIN) {
            $plugin = Plugin::getPlugin($key, false);
            if ($plugin && (int)($plugin[PluginConst::PLUGIN_CONFIG]['STATUS'] ?? 0) === 1) {
                \_plugin_stop($key);
            }
        }

        $target = self::targetDir($key, $type);
        if (is_dir($target)) {
            File::delDirectory($target);
        }

        //连同该扩展带来的词条一起清掉，避免卸载后残留在词库里
        \Kernel\Util\Lang::forgetExtension($key);

        self::clearCaches($key, $type);
    }

    /**
     * 解析压缩包，得出扩展标识与包内前缀目录。
     *
     * @param string $zipPath
     * @param int $type
     * @param string $givenKey
     * @return array{0: string, 1: string}
     * @throws JSONException
     */
    private static function resolvePackage(string $zipPath, int $type, string $givenKey): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new JSONException("插件包不是有效的 zip 文件");
        }

        $tops = [];
        $entries = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $normalized = ltrim(str_replace('\\', '/', $name), '/');
                if ($normalized === '') {
                    continue;
                }
                //拒绝绝对路径与目录穿越，避免解压写到目标目录之外
                if (str_contains($normalized, '..') || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
                    throw new JSONException("插件包内包含非法路径：{$name}");
                }
                $entries[] = $normalized;

                $slash = strpos($normalized, '/');
                $top = $slash === false ? $normalized : substr($normalized, 0, $slash);
                if ($slash === false) {
                    //根目录下的普通文件
                    $tops[$top]['file'] = true;
                } else {
                    $tops[$top]['dir'] = true;
                }
            }
        } finally {
            $zip->close();
        }

        if ($entries === []) {
            throw new JSONException("插件包是空的");
        }

        //包内自带且仅有一个顶层目录：标识取目录名，内容取其下
        $prefix = '';
        $key = trim($givenKey);
        $topNames = array_keys($tops);
        if (count($topNames) === 1 && !isset($tops[$topNames[0]]['file'])) {
            $prefix = $topNames[0];
            $key = $topNames[0];
        }

        if (!self::isValidKey($key)) {
            throw new JSONException("无法确定插件标识：请把插件包打成「插件标识/...」的单层目录结构，或手动填写插件标识");
        }

        $marker = self::marker($type);
        $expected = ($prefix === '' ? '' : $prefix . '/') . $marker;
        if (!in_array($expected, $entries, true)) {
            throw new JSONException("插件包结构不正确：未找到 {$marker}");
        }

        return [$key, $prefix];
    }

    /**
     * 把暂存目录的内容落到目标目录，并跑完安装/更新的收尾流程。
     *
     * @param string $source
     * @param string $target
     * @param string $key
     * @param int $type
     * @param string $action install|update
     * @return array{key: string, action: string}
     * @throws JSONException
     */
    private static function deploy(string $source, string $target, string $key, int $type, string $action): array
    {
        $wasRunning = false;
        if ($type === self::TYPE_PLUGIN) {
            $plugin = Plugin::getPlugin($key, false);
            $wasRunning = $plugin !== null && (int)($plugin[PluginConst::PLUGIN_CONFIG]['STATUS'] ?? 0) === 1;
            if ($wasRunning) {
                \_plugin_stop($key);
            }
        }

        try {
            //合并式覆盖拷贝：包内同名文件会被替换，包外文件（如站长自建的配置）保留下来
            File::copyDirectory($source, $target);

            $sqlFile = $target . ($action === 'update' ? '/update.sql' : '/install.sql');
            if (is_file($sqlFile)) {
                $database = config("database");
                SQL::import(
                    $sqlFile,
                    (string)$database['host'],
                    (string)$database['database'],
                    (string)$database['username'],
                    (string)$database['password'],
                    (string)$database['prefix'],
                    isset($database['port']) ? (int)$database['port'] : null
                );
            }

            if ($type === self::TYPE_PLUGIN) {
                Plugin::runHookState(
                    $key,
                    $action === 'update' ? PluginAnnotation::UPGRADE : PluginAnnotation::INSTALL
                );
            } elseif ($type === self::TYPE_THEME && $action === 'update') {
                //清空模板编译缓存，避免旧产物还在
                $viewDir = realpath(BASE_PATH . "/runtime/view/");
                if ($viewDir !== false) {
                    File::delDirectory($viewDir);
                }
            }

            self::clearCaches($key, $type);
        } catch (\Throwable $e) {
            //中途失败：尽力把插件恢复到停用之前的状态，但绝不吞掉原始错误
            if ($wasRunning) {
                try {
                    \_plugin_start($key);
                } catch (\Throwable $ignored) {
                }
            }
            throw $e;
        }

        if ($wasRunning) {
            try {
                \_plugin_start($key);
            } catch (\Throwable $e) {
                throw new JSONException("扩展已更新，但重新启动失败：" . $e->getMessage() . "。请到插件列表手动启动。");
            }
        }

        return ['key' => $key, 'action' => $action];
    }

    /**
     * 清理扩展相关的编译缓存，并重新导入扩展自带的词包。
     * @param string $key
     * @param int $type
     */
    private static function clearCaches(string $key, int $type): void
    {
        if ($type === self::TYPE_THEME) {
            Opcache::invalidate(BASE_PATH . "/app/View/User/Theme/{$key}/Config.php");
        } elseif ($type === self::TYPE_PAY) {
            Opcache::invalidate(
                BASE_PATH . "/app/Pay/{$key}/Config/Info.php",
                BASE_PATH . "/app/Pay/{$key}/Config/Config.php"
            );
        } else {
            Opcache::invalidate(
                BASE_PATH . "/app/Plugin/{$key}/Config/Info.php",
                BASE_PATH . "/app/Plugin/{$key}/Config/Config.php"
            );
        }

        $pluginCache = BASE_PATH . '/runtime/plugin/plugin.cache';
        if (is_file($pluginCache)) {
            @unlink($pluginCache);
        }

        //扩展自带词包：{扩展目录}/Lang/{语言}.json，装完/更新完立即入库
        \Kernel\Util\Lang::scanExtensionPacks();
    }

    /**
     * 校验并接收上传的 zip，返回其临时路径。
     * @param array $file
     * @return string
     * @throws JSONException
     */
    private static function receiveUpload(array $file): string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new JSONException(match ($error) {
                UPLOAD_ERR_NO_FILE => "请选择要上传的插件包",
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "插件包超过服务器允许的上传大小",
                UPLOAD_ERR_PARTIAL => "插件包只上传了一部分，请重试",
                default => "插件包上传失败（错误码 {$error}）",
            });
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new JSONException("插件包上传失败，请重试");
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new JSONException("插件包是空文件");
        }
        if ($size > self::MAX_PACKAGE_BYTES) {
            throw new JSONException("插件包体积超过上限（" . (int)(self::MAX_PACKAGE_BYTES / 1024 / 1024) . "MB）");
        }

        //扩展名只是提示，真正的判定以 zip 结构为准
        $name = (string)($file['name'] ?? '');
        if ($name !== '' && strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new JSONException("插件包必须是 zip 文件");
        }

        return $tmp;
    }
}

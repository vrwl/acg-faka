<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Class Theme
 * @package App\Util
 */
class Theme
{

    /**
     * @param string $name
     * @return array|null
     */
    public static function getConfig(string $name): ?array
    {
        try {
            $data = Context::get("theme_" . $name);
            if ($data) {
                return $data;
            }

            $interface = "\\App\\View\\User\\Theme\\{$name}\\Config";
            $submitJsPath = BASE_PATH . "/app/View/User/Theme/{$name}/Submit.js";

            if (!interface_exists($interface)) {
                return null;
            }

            $info = $interface::INFO;
            $info['KEY'] = $name;


            $ref = new \ReflectionClass($interface);
            $submit = $ref->getConstant("SUBMIT");

            if (!$submit) {
                $submit = [];
            }

            //获取配置
            $setting = [];
            $settingPath = BASE_PATH . "/app/View/User/Theme/{$name}/Setting.php";
            Opcache::invalidate($settingPath);

            if (file_exists($settingPath)) {
                $setting = (array)require($settingPath);
                foreach ($submit as $index => $item) {
                    if (isset($setting[$item['name']])) {
                        $submit[$index]['default'] = $setting[$item['name']];
                    }
                }
            }

            if (is_file($submitJsPath)) {
                $submit = file_get_contents($submitJsPath) ?: "";
            }

            $data = ["info" => $info, "theme" => $interface::THEME, "submit" => $submit, "setting" => $setting];
            Context::set("theme_" . $name, $data);
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array
     */
    public static function getThemes(): array
    {
        $path = BASE_PATH . '/app/View/User/Theme/';
        $list = scandir($path);
        $dir = [];
        foreach ($list as $item) {
            if ($item != '.' && $item != '..' && is_dir($path . $item)) {
                $dir[] = $item;
            }
        }
        $plug = [];
        foreach ($dir as $value) {
            $platformInfo = self::getConfig($value);
            if (!empty($platformInfo)) {
                $plug[] = $platformInfo;
            }
        }
        return $plug;
    }

    /**
     * 取模板图标：优先 Config.php 里的 INFO['ICON']，其次模板目录下的
     * icon.png / icon.svg。都没有就返回空串，由前端用首字母色块占位。
     *
     * @param array $theme Theme::getConfig() 的返回值
     * @return string 站内绝对路径或 HTTP(S) 地址；无图标时为空串
     */
    public static function getIcon(array $theme): string
    {
        $info = (array)($theme['info'] ?? []);
        $key = (string)($info['KEY'] ?? '');
        $candidates = [];
        //键名两种写法都认：INFO 里其余键都是大写，但小写 icon 更贴近直觉
        $declared = trim((string)($info['ICON'] ?? $info['icon'] ?? ''));
        if ($declared !== '') {
            $candidates[] = $declared;
        }
        if ($key !== '') {
            $candidates[] = "/app/View/User/Theme/{$key}/icon.png";
            $candidates[] = "/app/View/User/Theme/{$key}/icon.svg";
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            //必须能安全地拼进 <img src="">：站内绝对路径或 HTTP(S) 地址
            if (
                $candidate === ''
                || strlen($candidate) > 255
                || str_starts_with($candidate, '//')
                || preg_match('/[\x00-\x20\x7F<>"\']/u', $candidate)
                || !preg_match('~^(?:/|https?://)~i', $candidate)
            ) {
                continue;
            }
            //站内路径再确认文件真的在，外链不检查（为一张图发 HEAD 请求不值得）
            if ($candidate[0] === '/' && !is_file(BASE_PATH . ltrim($candidate, '/'))) {
                continue;
            }
            return $candidate;
        }

        return '';
    }
}

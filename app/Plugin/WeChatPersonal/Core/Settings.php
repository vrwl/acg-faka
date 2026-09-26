<?php
declare(strict_types=1);

namespace App\Plugin\WeChatPersonal\Core;

use App\Model\PayConfig as PayConfigModel;
use App\Util\Plugin as PluginUtil;
use App\Util\QrCode;

/**
 * 配置访问层。
 *
 * 2.0 起收款信息（Token / 收款码 / 手机号）都在「支付插件 → 微信支付-接口(个人挂机版) → 配置」
 * 的配置档里，一套配置 = 一个微信收款账号 = 一台挂机手机。通用插件只留全局引擎参数。
 *
 * 1.x 升级上来的站点，老配置仍留在通用插件里，这里全部做回落读取，站长不动配置也能继续收款。
 */
final class Settings
{
    public const PLUGIN = 'WeChatPersonal';
    public const HANDLE = 'WeChatPersonalPay';

    /**
     * 通用插件配置（全局回落用）
     */
    public static function global(): array
    {
        try {
            $config = PluginUtil::getConfig(self::PLUGIN);
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function globalValue(string $key, string $default = ''): string
    {
        $v = trim((string)(self::global()[$key] ?? ''));
        return $v === '' ? $default : $v;
    }

    /**
     * 订单有效期（秒）。挂机端要在这个窗口内把到账报回来，太短会错过慢通知，太长会让金额位堆积。
     */
    public static function expireSeconds(): int
    {
        $v = self::globalValue('expire_minutes', '5');
        $m = is_numeric($v) ? (int)$v : 5;
        return max(2, min(30, $m)) * 60;
    }

    /**
     * 全部收款配置档：[配置档id => 配置数组]
     */
    public static function profiles(): array
    {
        $out = [];
        try {
            foreach (PayConfigModel::query()->where('handle', self::HANDLE)->orderBy('sort')->orderBy('id')->get(['id', 'config']) as $row) {
                $cfg = json_decode((string)$row->config, true);
                $out[(int)$row->id] = is_array($cfg) ? $cfg : [];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * 单个配置档；取不到就给空数组（调用方自己回落到全局）
     */
    public static function profile(int $configId): array
    {
        if ($configId <= 0) {
            return [];
        }
        try {
            $row = PayConfigModel::query()->where('id', $configId)->where('handle', self::HANDLE)->first(['config']);
            if (!$row) {
                return [];
            }
            $cfg = json_decode((string)$row->config, true);
            return is_array($cfg) ? $cfg : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 某套配置的挂机端 Token：配置档优先，空则回落全局。
     * 这个值要原样填进手机 App，所以必须对站长可见可改。
     */
    public static function token(array $profile): string
    {
        //只认支付接口(pay_config)为该挂机设备配置的通信密钥；密钥为空即视为「未配置」，交由调用方拒绝。
        //绝不再回落到通用插件 Config.php 里那枚随源码公开分发的出厂默认值——它人人可得，
        //等于没有密钥，任何人都能用它对回调报文算出合法签名伪造「已支付」→白嫖发货。
        return trim((string)($profile['app_key'] ?? ''));
    }

    /**
     * 收款相关字段：配置档优先，空则回落到通用插件的老配置
     */
    public static function field(array $profile, string $key): string
    {
        $v = trim((string)($profile[$key] ?? ''));
        return $v !== '' ? $v : self::globalValue($key);
    }

    /**
     * 收款码图片解析出的支付链接。
     *
     * 支付插件那侧保存配置没有服务端钩子（不像通用插件有 SAVE_CONFIG），
     * 所以改成用到时才解析，解析结果回写进同一套配置档，之后直接命中缓存。
     *
     * @param int $configId 0 表示只读全局配置，不回写
     * @param string $imageKey 图片字段名（qrcode / reward_url）
     * @param string $urlKey 解析结果字段名（wx_url / reward_parsed_url）
     */
    public static function qrUrl(int $configId, array $profile, string $imageKey = 'qrcode', string $urlKey = 'wx_url'): string
    {
        //站长手填的链接优先级最高：收款码图片识别不了时，这是唯一的出路
        $manual = trim((string)($profile['manual_url'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }

        //配置档里已解析过
        $cached = trim((string)($profile[$urlKey] ?? ''));
        if ($cached !== '') {
            return $cached;
        }

        $image = trim((string)($profile[$imageKey] ?? ''));

        //配置档没配图：整套回落到通用插件的老配置（含它早就解析好的 wx_url）
        if ($image === '') {
            $legacy = trim((string)(self::global()[$urlKey] ?? ''));
            if ($legacy !== '') {
                return $legacy;
            }
            $legacyImage = self::globalValue($imageKey);
            if ($legacyImage === '') {
                return '';
            }
            return self::parse($legacyImage);
        }

        $url = self::parse($image);
        if ($url !== '' && $configId > 0) {
            self::remember($configId, $urlKey, $url);
        }
        return $url;
    }

    /**
     * 解析二维码图片，失败返回空串（调用方决定怎么提示）
     */
    public static function parse(string $relativePath): string
    {
        try {
            $path = BASE_PATH . $relativePath;
            if (!is_file($path)) {
                return '';
            }
            return self::decode($path);
        } catch (\Throwable $e) {
            Log::warn('二维码解析失败', ['path' => $relativePath, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * 多尺度解码。
     *
     * 把原图直接丢给解码器有两个毛病：手机截屏那种 1080x1620 的收款码分享图会让它一次
     * 分配上百 MB（实测直接打爆 128M 上限），而且识别率反而更差——同一张图原图读不出来，
     * 缩到 640 一次就中。解码器对采样尺寸敏感且不是越大越准，所以按几个经验宽度依次试，
     * 先中先返回；都不中再做一轮灰度增强（对付蓝底白卡片这类花哨背景）。
     */
    private static function decode(string $path): string
    {
        $info = @getimagesize($path);
        if (!$info) {
            return '';
        }
        [$w, $h] = $info;

        $widths = [];
        if ($w > 0 && $w <= 900) {
            $widths[] = 0; //0 = 用原图
        }
        foreach ([640, 400, 900, 500, 300] as $tw) {
            if ($tw < $w) {
                $widths[] = $tw;
            }
        }
        if ($widths === []) {
            $widths[] = 0;
        }

        foreach ($widths as $tw) {
            $text = self::readOnce($path, $w, $h, $tw, false);
            if ($text !== '') {
                return $text;
            }
        }

        foreach (array_slice($widths, 0, 3) as $tw) {
            $text = self::readOnce($path, $w, $h, $tw, true);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * 按指定宽度缩放后读一次；$enhance 为真时先灰度+提对比度
     */
    private static function readOnce(string $path, int $w, int $h, int $targetWidth, bool $enhance): string
    {
        if (!function_exists('imagecreatefromstring')) {
            //没有 GD 就退回原始读法，至少小图还能认
            try {
                return trim(QrCode::parse($path));
            } catch (\Throwable $e) {
                return '';
            }
        }

        $src = null;
        $work = null;
        try {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return '';
            }
            $src = @imagecreatefromstring($raw);
            unset($raw);
            if (!$src) {
                return '';
            }

            if ($targetWidth > 0 && $targetWidth < $w) {
                $th = max(1, (int)round($h * $targetWidth / $w));
                $work = imagecreatetruecolor($targetWidth, $th);
                imagecopyresampled($work, $src, 0, 0, 0, 0, $targetWidth, $th, $w, $h);
            } else {
                $work = $src;
                $src = null;
            }

            if ($enhance) {
                @imagefilter($work, IMG_FILTER_GRAYSCALE);
                @imagefilter($work, IMG_FILTER_CONTRAST, -25);
            }

            $reader = new \Zxing\QrReader($work, \Zxing\QrReader::SOURCE_TYPE_RESOURCE);
            return trim((string)$reader->text());
        } catch (\Throwable $e) {
            return '';
        } finally {
            if ($work !== null) {
                @imagedestroy($work);
            }
            if ($src !== null) {
                @imagedestroy($src);
            }
        }
    }

    /**
     * 把解析结果写回配置档（只补这一个键，不动其它字段）
     */
    private static function remember(int $configId, string $key, string $value): void
    {
        try {
            $row = PayConfigModel::query()->where('id', $configId)->where('handle', self::HANDLE)->first();
            if (!$row) {
                return;
            }
            $cfg = json_decode((string)$row->config, true);
            $cfg = is_array($cfg) ? $cfg : [];
            $cfg[$key] = $value;
            $row->config = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row->save();
            \App\Util\PayProfile::flush(self::HANDLE, $configId);
        } catch (\Throwable $e) {
            Log::warn('二维码解析结果回写失败', ['config_id' => $configId, 'error' => $e->getMessage()]);
        }
    }
}

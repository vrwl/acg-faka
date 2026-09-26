<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use Kernel\Exception\JSONException;

/**
 * 配置读取唯一入口：Config/Config.php 全是字符串值，这里做默认值合并与类型规范化。
 * 任何写入口（后台设置页 / 通用插件弹窗 / MCP plugin_config_set）保存前都会经过
 * Lifecycle::saveConfig → self::validate()，保证脏值进不来。
 */
final class Settings
{
    public const PLUGIN = 'Blog';

    public const STYLES = ['glass', 'paper', 'mag', 'neon'];
    public const DARK_MODES = ['auto', 'light', 'dark'];

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            //基础
            'nav_label' => '',              //入口名称，空 = 「博客」；站长可改成「知识库」等
            'blog_name' => '',              //空 = 回退「{站点名} {入口名称}」
            'blog_subtitle' => '',
            'blog_description' => '',
            'blog_keywords' => '',
            //阅读
            'posts_per_page' => '10',
            'summary_auto_len' => '200',    //无手工摘要时自动截取长度
            'guest_visible' => '1',         //0 = 博客整体需登录
            'lazyload_img' => '1',
            'view_dedupe_minutes' => '60',  //浏览量去重窗口(分钟)
            //评论
            'comment_enabled' => '1',
            'comment_audit' => '1',         //1 = 先审后发
            'comment_max_depth' => '3',     //前台嵌套显示层数
            'comment_interval' => '60',     //同用户提交间隔(秒)
            'comment_maxlen' => '1000',
            'like_enabled' => '1',
            //RSS 与短链
            'feed_enabled' => '1',
            'feed_items' => '20',
            'feed_fulltext' => '0',
            'pretty_links' => '1',          ///blog 优雅短链（HTTP_NOT_FOUND 桥接）
            //内容与安全
            'allow_iframe' => '0',          //白名单视频 iframe（youtube-nocookie/bilibili）
            'external_nofollow' => '1',
            //外观
            'default_style' => 'glass',     //glass|paper|mag|neon
            'dark_mode' => 'auto',          //auto|light|dark（访客默认值，可被访客本地覆写）
            //融合
            'nav_inject_uc' => '1',         //会员中心导航注入
            'nav_inject_shop' => '1',       //商城前台导航注入(0x88)
            'standalone_domain' => '',      //绑定的独立域名（可多个）：该域名下只有博客，商城完全不可达
            'item_inject' => '1',           //商品详情页展示相关文档卡片(0x51)
            'back_to_shop_url' => '/',
            'author_uids' => '',            //评论区显示「作者」徽标的 user.id，逗号分隔
        ];
    }

    public static function refresh(): void
    {
        self::$cache = null;
        //域名绑定是从这里读的，缓存要一起清，否则保存后本请求内两边判定会打架
        \App\Plugin\Blog\Support\Domain::refresh();
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $config = [];
            try {
                $config = \App\Util\Plugin::getConfig(self::PLUGIN, false);
            } catch (\Throwable $e) {
                //配置读不到时用纯默认值
            }
            if (!is_array($config)) {
                $config = [];
            }
            //空串视为未设置（回落默认值）；'0' 是合法值必须保留
            $config = array_filter($config, function ($v) {
                return $v !== '' && $v !== null;
            });
            self::$cache = array_merge(self::defaults(), $config);
        }
        return self::$cache;
    }

    public static function raw(string $key): string
    {
        $all = self::all();
        return (string)($all[$key] ?? '');
    }

    public static function bool(string $key): bool
    {
        return self::raw($key) === '1';
    }

    public static function int(string $key, int $min, int $max): int
    {
        $value = (int)self::raw($key);
        return max($min, min($max, $value));
    }

    public static function str(string $key): string
    {
        return trim(self::raw($key));
    }

    /**
     * 入口名称：商城顶栏、会员中心入口、以及博客标题的兜底都用它。
     * 站长把它改成「知识库」「教程中心」，全站入口跟着一起变，不用逐处改。
     * 留空则回落到「博客」（走词包，跟随站点语言）。
     */
    public static function navLabel(): string
    {
        $label = self::str('nav_label');
        return $label !== '' ? $label : lang('博客', 'tpl');
    }

    /** 后台侧栏菜单标题：站长改了入口名称就跟着改，没改则显示插件本名 */
    public static function adminLabel(): string
    {
        $label = self::str('nav_label');
        return $label !== '' ? $label : lang('次元博客', 'tpl');
    }

    /**
     * 逗号/换行分隔的列表。
     * ⚠ 不用 preg_split('/\R/')——它在部分环境会把多字节汉字劈开（项目已知坑）。
     */
    public static function list(string $key): array
    {
        $raw = str_replace(["\r\n", "\r", "，"], ["\n", "\n", ","], self::raw($key));
        $items = [];
        foreach (explode("\n", $raw) as $line) {
            foreach (explode(",", $line) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $items[] = $piece;
                }
            }
        }
        return $items;
    }

    /**
     * SAVE_CONFIG 前的全量校验：只校验本次提交里出现的键，非法值抛 JSONException 拒绝保存。
     * @throws JSONException
     */
    public static function validate(array $map): void
    {
        $intRules = [
            'posts_per_page' => [1, 50],
            'summary_auto_len' => [50, 500],
            'comment_max_depth' => [1, 6],
            'comment_interval' => [0, 3600],
            'comment_maxlen' => [50, 5000],
            'feed_items' => [1, 100],
            'view_dedupe_minutes' => [0, 1440],
        ];
        foreach ($intRules as $key => [$min, $max]) {
            if (!array_key_exists($key, $map) || $map[$key] === '') {
                continue;
            }
            if (!is_numeric((string)$map[$key]) || (int)$map[$key] < $min || (int)$map[$key] > $max) {
                throw new JSONException(lang(sprintf("配置项 %s 必须是 %d ~ %d 之间的整数", $key, $min, $max)));
            }
        }

        $boolKeys = [
            'guest_visible', 'lazyload_img', 'comment_enabled', 'comment_audit', 'like_enabled',
            'feed_enabled', 'feed_fulltext', 'pretty_links', 'allow_iframe', 'external_nofollow',
            'nav_inject_uc', 'nav_inject_shop', 'item_inject',
        ];
        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $map) && $map[$key] !== '' && !in_array((string)$map[$key], ['0', '1'], true)) {
                throw new JSONException(lang(sprintf("配置项 %s 只能是 0 或 1", $key)));
            }
        }

        if (!empty($map['default_style']) && !in_array((string)$map['default_style'], self::STYLES, true)) {
            throw new JSONException(lang("默认主题风格不合法"));
        }
        if (!empty($map['dark_mode']) && !in_array((string)$map['dark_mode'], self::DARK_MODES, true)) {
            throw new JSONException(lang("明暗模式默认值不合法"));
        }
        if (!empty($map['back_to_shop_url'])) {
            $url = (string)$map['back_to_shop_url'];
            if (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url)) {
                throw new JSONException(lang("返回商城地址必须是 / 开头的路径或完整 URL"));
            }
        }
        if (!empty($map['author_uids']) && !preg_match('/^[\d,，\s]*$/u', (string)$map['author_uids'])) {
            throw new JSONException(lang("作者 UID 列表只能包含数字与逗号"));
        }
        if (!empty($map['standalone_domain'])) {
            //逐条归一化校验：站长常把 https:// 和结尾斜杠一起粘进来，Domain::normalize 会剥掉，
            //剥完还不是合法主机名才算填错
            $raw = str_replace(["\r\n", "\r", "，"], ["\n", "\n", ","], (string)$map['standalone_domain']);
            foreach (explode("\n", $raw) as $line) {
                foreach (explode(',', $line) as $piece) {
                    $piece = trim($piece);
                    if ($piece === '') {
                        continue;
                    }
                    $host = \App\Plugin\Blog\Support\Domain::normalize($piece);
                    if ($host === '') {
                        throw new JSONException(lang("独立域名格式不正确：") . mb_substr($piece, 0, 60));
                    }
                    //防自锁：绑定后该域名下商城和后台都不可达。站长正是从当前域名进的后台，
                    //把它填进来就等于把自己关在门外，只能去改数据库才能救回来
                    $current = \App\Plugin\Blog\Support\Domain::currentHost();
                    if ($current !== '' && $host === $current) {
                        throw new JSONException(lang("不能绑定你当前正在使用的域名：绑定后该域名下将只剩博客，后台也会打不开"));
                    }
                }
            }
        }
    }
}

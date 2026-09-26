<?php
declare(strict_types=1);

namespace App\View\User\Theme\LosAngeles;

/**
 * 洛杉矶主题的服务端助手。
 *
 * 存在的理由：模板拿到的 $setting 已经被 ViewSafe::escape() 用
 * htmlspecialchars(ENT_QUOTES) 整体转义过，站长填的 JSON 结构里的引号全变成了
 * &quot;，直接 json_decode 必然失败，直接塞进 <script> 又会二次转义。
 * 所以 JSON 型配置一律走这里：先还原实体 → 解析 → 逐字段校验 → 再按用途
 * 输出（HTML 转义版给模板，JSON_HEX_* 版给脚本）。
 *
 * 所有方法对脏数据一律降级到默认值，绝不抛异常 —— 站长填错一个配置
 * 不该让整个前台 500。
 */
final class Support
{
    /** 首页楼层的出厂顺序 */
    private const FLOOR_TYPES = ['seckill', 'recommend', 'category', 'waterfall'];

    private const AUDIENCES = ['all', 'member', 'guest'];

    private const THEME_MODES = ['auto', 'light', 'dark'];

    private const ACCENTS = ['sunset', 'magenta', 'pacific', 'palm', 'noir'];

    private const DENSITIES = ['cozy', 'compact'];

    // ---------------------------------------------------------------- 基础读取

    /**
     * 读回一个标量配置的**原始值**（撤销 ViewSafe 的 HTML 转义）。
     */
    public static function raw(mixed $setting, string $key, string $default = ''): string
    {
        $value = is_array($setting) ? ($setting[$key] ?? null) : null;
        if ($value === null || !is_scalar($value)) {
            return $default;
        }
        $value = htmlspecialchars_decode((string)$value, ENT_QUOTES);
        return $value === '' ? $default : $value;
    }

    /**
     * 开关型配置。键不存在时用 $default；存在时 '0'/''/'false'/'off' 均为假。
     */
    public static function on(mixed $setting, string $key, bool $default = true): bool
    {
        if (!is_array($setting) || !array_key_exists($key, $setting)) {
            return $default;
        }
        $value = strtolower(trim((string)($setting[$key] ?? '')));
        return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
    }

    /**
     * 「秒杀专区」总开关，默认开。关掉后底部导航的秒杀格、首页秒杀楼层、手机首页的秒杀视图、
     * 指向秒杀视图的金刚区入口一并下线。商品自己设置的秒杀价是真实成交价，商品卡与详情页照常展示。
     */
    public static function seckillOn(mixed $setting): bool
    {
        return self::on($setting, 'seckill_on');
    }

    /** 链接是否指向手机首页的秒杀视图：/#seckill、#seckill、/?from=x#seckill 都算 */
    private static function isSeckillAnchor(string $url): bool
    {
        return preg_match('~#seckill$~i', $url) === 1;
    }

    /**
     * 枚举型配置，取值不在白名单内时回落默认值。
     */
    public static function pick(mixed $setting, string $key, array $allowed, string $default): string
    {
        $value = self::raw($setting, $key, $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function themeMode(mixed $setting): string
    {
        return self::pick($setting, 'theme_mode', self::THEME_MODES, 'auto');
    }

    public static function accent(mixed $setting): string
    {
        return self::pick($setting, 'accent', self::ACCENTS, 'sunset');
    }

    public static function density(mixed $setting): string
    {
        return self::pick($setting, 'density', self::DENSITIES, 'cozy');
    }

    // ---------------------------------------------------------------- JSON 解析

    /**
     * 解析一个 JSON 数组型配置。失败一律返回空数组。
     */
    public static function list(mixed $setting, string $key): array
    {
        $raw = trim(self::raw($setting, $key));
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 链接安全过滤：只放行站内相对路径与 http(s) 绝对地址。
     * 站内锚点（#seckill）用于手机端 APP 内切换视图，也放行。
     */
    public static function url(mixed $value, string $fallback = ''): string
    {
        $url = trim((string)($value ?? ''));
        if ($url === '') {
            return $fallback;
        }
        //去掉控制字符再判协议，防 "java\tscript:" 这类绕过
        $probe = strtolower((string)preg_replace('/[\x00-\x20]/', '', $url));
        if ($probe === '' || str_starts_with($probe, '//')) {
            return $fallback;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        if (preg_match('#^https?://#', $probe) === 1) {
            return $url;
        }
        return $fallback;
    }

    /**
     * 图片地址：在 url() 基础上额外放行 data:image（非 svg），与 ViewSafe 口径一致。
     */
    public static function image(mixed $value, string $fallback = ''): string
    {
        $url = trim((string)($value ?? ''));
        $probe = strtolower((string)preg_replace('/[\x00-\x20]/', '', $url));
        if (str_starts_with($probe, 'data:image/') && !str_starts_with($probe, 'data:image/svg')) {
            return $url;
        }
        return self::url($url, $fallback);
    }

    /**
     * 受众过滤：all=所有人，member=仅登录，guest=仅访客。
     */
    private static function audienceOk(string $audience, bool $logged): bool
    {
        return match ($audience) {
            'member' => $logged,
            'guest' => !$logged,
            default => true,
        };
    }

    /**
     * 运营位文案的统一出口：剥标签 → 截断 → 翻译。
     *
     * $scene 决定词条落在哪本字典里：
     *   dyn = 站长在后台填的内容（内容寻址，改一次就是一条新词条）
     *   tpl = 主题自带的出厂文案（随 Lang/*.json 一起发货）
     * 两者混用会让出厂文案被反复收进 dyn 表，所以调用方必须显式指定。
     */
    private static function text(mixed $value, int $max = 40, string $scene = 'dyn'): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '';
        }
        //站长填的是纯文案，标签一律剥掉，避免运营配置变成 XSS 入口
        $text = mb_substr(strip_tags($text), 0, $max);
        return $text === '' ? '' : lang($text, $scene);
    }

    private static function icon(mixed $value, string $fallback = 'sparkle'): string
    {
        $name = trim((string)($value ?? ''));
        return Icons::has($name) ? $name : $fallback;
    }

    // ---------------------------------------------------------------- 运营位

    /**
     * 轮播图。每项 {image, url, title, sub}，image 必填。
     */
    public static function banners(mixed $setting): array
    {
        $out = [];
        foreach (self::list($setting, 'banners') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $image = self::image($row['image'] ?? '');
            if ($image === '') {
                continue;
            }
            $out[] = [
                'image' => $image,
                'url' => self::url($row['url'] ?? '', ''),
                'title' => self::text($row['title'] ?? '', 30),
                'sub' => self::text($row['sub'] ?? '', 50),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /**
     * 金刚区快捷入口。每项 {name, icon, url, audience}。
     */
    public static function shortcuts(mixed $setting, mixed $user = null): array
    {
        //$user 直接收核心下发的会员模型（或 null）——模板里不该再包一层类型转换
        $logged = !empty($user);
        $out = [];
        foreach (self::list($setting, 'shortcuts') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::text($row['name'] ?? '', 12);
            $url = self::url($row['url'] ?? '');
            if ($name === '' || $url === '') {
                continue;
            }
            //秒杀专区关了，指向秒杀视图的入口点进去只会落回首页，不如不出
            if (self::isSeckillAnchor($url) && !self::seckillOn($setting)) {
                continue;
            }
            $audience = (string)($row['audience'] ?? 'all');
            if (!in_array($audience, self::AUDIENCES, true)) {
                $audience = 'all';
            }
            if (!self::audienceOk($audience, $logged)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'icon' => self::icon($row['icon'] ?? ''),
                'url' => $url,
                'blank' => preg_match('#^https?://#i', $url) === 1,
            ];
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * 手机端底部 TabBar。站长没配就用出厂的五格。
     * url 以 # 开头表示 APP 内切换视图（首页里的分类 / 秒杀面板）。
     */
    public static function tabbar(mixed $setting, mixed $user = null): array
    {
        $logged = !empty($user);
        $seckillOn = self::seckillOn($setting);
        $rows = self::list($setting, 'tabbar');
        //出厂五格是主题自带文案，与站长自定义的走不同词典场景
        $scene = $rows ? 'dyn' : 'tpl';
        if (!$rows) {
            $rows = [
                ['name' => '首页', 'icon' => 'home', 'url' => '/', 'match' => '/user/index/index'],
                ['name' => '分类', 'icon' => 'grid', 'url' => '/#category'],
                ['name' => '秒杀', 'icon' => 'flash', 'url' => '/#seckill'],
                //登录了就直接去自己的购买记录，没登录才是游客查单页 —— 底部导航是 APP 的主入口，
                //已经登录还把人送到「输入订单号查询」，等于把会员当游客
                $logged
                    ? ['name' => '订单', 'icon' => 'receipt', 'url' => '/user/personal/purchaseRecord', 'match' => '/user/personal/purchaseRecord']
                    : ['name' => '订单', 'icon' => 'receipt', 'url' => '/user/index/query', 'match' => '/user/index/query'],
                ['name' => '我的', 'icon' => 'user', 'url' => '/user/dashboard/index', 'match' => '/user'],
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::text($row['name'] ?? '', 6, $scene);
            $url = self::url($row['url'] ?? '');
            if ($name === '' || $url === '') {
                continue;
            }
            //秒杀专区关了：出厂的「秒杀」格和站长自己加的 #seckill 格一起拿掉，剩下几格均分底栏
            if (self::isSeckillAnchor($url) && !$seckillOn) {
                continue;
            }
            $audience = (string)($row['audience'] ?? 'all');
            if (in_array($audience, self::AUDIENCES, true) && !self::audienceOk($audience, $logged)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'icon' => self::icon($row['icon'] ?? '', 'home'),
                'url' => $url,
                //match 为空表示不参与高亮；模板必须先判空再调 active()，
                //active('') 会命中任何路由。
                //match 是路由前缀，不是文案：只做长度与字符收敛，绝不能进翻译管线
                'match' => preg_replace('#[^A-Za-z0-9/_\\-]#', '', mb_substr(trim((string)($row['match'] ?? '')), 0, 64)) ?: '',
                'view' => str_starts_with($url, '/#') ? substr($url, 2) : (str_starts_with($url, '#') ? substr($url, 1) : ''),
                'is_on' => false,
            ];
            if (count($out) >= 5) {
                break;
            }
        }

        //高亮只能有一格。active() 是「前缀命中」，「我的」的 /user 会把 /user/index/query、
        ///user/personal/… 统统吃掉，底部就同时亮两格。改成最长前缀命中者独亮。
        $router = function_exists('getLocalRouter') ? (string)getLocalRouter() : '';
        $best = -1;
        $bestLen = 0;
        foreach ($out as $i => $row) {
            $prefix = (string)$row['match'];
            if ($prefix === '' || $router === '' || !str_starts_with($router, $prefix)) {
                continue;
            }
            if (strlen($prefix) > $bestLen) {
                $bestLen = strlen($prefix);
                $best = $i;
            }
        }
        if ($best >= 0) {
            $out[$best]['is_on'] = true;
        }
        return $out;
    }

    /**
     * 首页楼层顺序与开关。站长没配就是出厂顺序全开。
     */
    public static function floors(mixed $setting): array
    {
        $rows = self::list($setting, 'floors');
        $seckillOn = self::seckillOn($setting);
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $type = is_array($row) ? (string)($row['type'] ?? '') : (string)$row;
            if (!in_array($type, self::FLOOR_TYPES, true) || isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            //秒杀专区总开关压过楼层自己的开关（先记进 $seen：这是「配了但被关掉」，不能触发出厂回落）
            if ($type === 'seckill' && !$seckillOn) {
                continue;
            }
            $on = is_array($row) ? !in_array(strtolower(trim((string)($row['on'] ?? '1'))), ['', '0', 'false', 'off'], true) : true;
            if (!$on) {
                continue;
            }
            $out[] = ['type' => $type, 'title' => self::text($row['title'] ?? '', 20)];
        }

        //没配 / 全配错时回落出厂顺序。判 $seen 不判 $out：站长把楼层全关掉是明确意图
        //（面板上写着「关掉的楼层不会渲染」），不能又把出厂楼层全开回来
        if (!$seen) {
            foreach (self::FLOOR_TYPES as $type) {
                if ($type === 'seckill' && !$seckillOn) {
                    continue;
                }
                $out[] = ['type' => $type, 'title' => ''];
            }
        }
        return $out;
    }

    /**
     * 页脚栏目。每项 {title, links:[{name,url}]}。
     */
    public static function footerGroups(mixed $setting): array
    {
        $out = [];
        foreach (self::list($setting, 'footer_groups') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = self::text($row['title'] ?? '', 16);

            // links 有两种写法都要认：
            //   · 后台面板产出的紧凑串 "名称|地址; 名称|地址"
            //   · 手写 JSON 的对象数组 [{name,url}, ...]
            $rawLinks = $row['links'] ?? [];
            if (is_string($rawLinks)) {
                $parsed = [];
                foreach (preg_split('/[;；\n]+/u', $rawLinks) ?: [] as $pair) {
                    $bits = explode('|', $pair, 2);
                    if (count($bits) === 2) {
                        $parsed[] = ['name' => trim($bits[0]), 'url' => trim($bits[1])];
                    }
                }
                $rawLinks = $parsed;
            }

            $links = [];
            foreach ((array)$rawLinks as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $name = self::text($link['name'] ?? '', 20);
                $url = self::url($link['url'] ?? '');
                if ($name === '' || $url === '') {
                    continue;
                }
                $links[] = ['name' => $name, 'url' => $url, 'blank' => preg_match('#^https?://#i', $url) === 1];
                if (count($links) >= 10) {
                    break;
                }
            }
            if ($title === '' && !$links) {
                continue;
            }
            $out[] = ['title' => $title, 'links' => $links];
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- 输出

    /**
     * 安全地把数组塞进 <script>。
     * JSON_HEX_TAG/AMP/APOS/QUOT 保证内容里出现 </script> 或引号也无法越狱。
     */
    public static function json(mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return $json === false ? '{}' : $json;
    }

    /**
     * 前端引导数据。模板里 #{...Support::boot($setting,$user,$config)} 一次性下发，
     * 省掉一堆 data-* 属性。
     */
    public static function boot(mixed $setting, mixed $user, mixed $config = []): string
    {
        $logged = !empty($user);
        return self::json([
            'accent' => self::accent($setting),
            'density' => self::density($setting),
            'themeMode' => self::themeMode($setting),
            'logged' => $logged,
            'showSold' => self::on($setting, 'show_sold'),
            'showStock' => self::on($setting, 'show_stock'),
            'showOwner' => self::on($setting, 'show_owner'),
            'showMemberPrice' => self::on($setting, 'show_member_price', true),
            'currency' => (string)(is_array($config) ? ($config['currency_symbol'] ?? '') : ''),
            'floors' => array_column(self::floors($setting), 'type'),
            'banners' => self::banners($setting),
        ]);
    }





    /**
     * 充值赠送阶梯的清洗。
     *
     * 控制器是这样构造的：
     *   explode(PHP_EOL, Config::get("recharge_welfare_config"))
     *   → foreach → explode("-", $item) → ["recharge" => $ape[0], "amount" => $ape[1]]
     * 站长没配这项时，explode 的结果是 [""]，$ape[1] 根本不存在，
     * 于是 $welfareConfig 里永远会多出一条空记录。直接渲染就会得到
     * 一个"充 ¥ 送 ¥"的空标签。这里统一过滤掉不成对的档位。
     */
    public static function welfare(mixed $rows): array
    {
        $out = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $recharge = trim((string)($row['recharge'] ?? ''));
            $amount = trim((string)($row['amount'] ?? ''));
            if ($recharge === '' || $amount === '' || !is_numeric($recharge) || !is_numeric($amount)) {
                continue;
            }
            if ((float)$amount <= 0) {
                continue;
            }
            $out[] = ['recharge' => $recharge, 'amount' => $amount];
        }
        return $out;
    }

    /**
     * 静态资源的版本号（?v= 的值）。
     *
     * 不用 Helper::themeUrl()：它只解析 user_theme / user_mobile_theme，
     * 会员中心若选了别的主题，它算出来的路径会指向另一套主题并 404。
     * 这里直接读本主题自己的 INFO.VERSION —— 发版时 bump 一次，
     * 浏览器缓存自然失效，不需要模板里再手工维护一个版本常量。
     *
     * DEBUG 打开时追加时间戳：改完 CSS/JS 刷新即生效，不必为了看效果去改版本号。
     * （平台的 css()/js() 也是同样的做法，追加 &debug=随机串。）
     */
    public static function assetVersion(): string
    {
        $version = (string)(Config::INFO['VERSION'] ?? '1.0.0');
        return (defined('DEBUG') && DEBUG) ? $version . '.' . time() : $version;
    }

    /**
     * 当前请求是不是「明确的分类页」。
     *
     * 不能拿 $categoryId 判断：控制器在 Index::index() 里做了
     *   $_GET['cid'] = $_GET['cid'] ?: Config::get("default_category")
     * 所以只要站长设过 default_category，连首页 / 也会带上一个分类 ID。
     * 那个设置是给老式「单列商品流」主题预选分类用的，不该把本主题的
     * 营销首页整个换成分类列表。
     *
     * 另外 `$categoryId > 0` 这种写法本身也不能用：PHP 8 里非数字字符串
     * 与数字比较会退化成字符串比较，'recommend' > 0 结果是 true。
     *
     * 判据只能取未被改写过的原始请求：/cat/{id} 的路径，或 query string
     * 里真的出现了 cid。
     */
    public static function isCatalog(): bool
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('#/cat/(\d+|recommend)(?:[/?\#]|$)#', $uri) === 1) {
            return true;
        }
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        return preg_match('#(^|&)cid=[^&]+#', $query) === 1;
    }

    /**
     * 分类页标题：'recommend' 是虚拟分类，树里查不到，单独给个名字。
     */
    public static function catalogTitle(mixed $category, int|string|null $id): string
    {
        if ((string)$id === 'recommend') {
            return lang('店长推荐', 'tpl');
        }
        $node = self::current($category, is_numeric($id) ? (int)$id : 0);
        return (string)($node['name'] ?? '');
    }

    /**
     * 分类面包屑：从根走到 $id 的完整路径。找不到返回空数组。
     * $category 是核心下发的树（Tree::generate 结果，已被 ViewSafe 转义）。
     */
    public static function crumb(mixed $category, int|string|null $id): array
    {
        //'recommend' 是虚拟分类，树里没有它，直接返回空面包屑
        $id = is_numeric($id) ? (int)$id : 0;
        if ($id <= 0 || !is_array($category)) {
            return [];
        }

        $walk = static function (array $nodes, array $trail) use (&$walk, $id): ?array {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $here = array_merge($trail, [['id' => self::catId($node['id'] ?? 0), 'name' => (string)($node['name'] ?? '')]]);
                if ((int)($node['id'] ?? 0) === $id) {
                    return $here;
                }
                $kids = $node['children'] ?? null;
                if (is_array($kids) && $kids) {
                    $hit = $walk($kids, $here);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
            return null;
        };

        return $walk($category, []) ?? [];
    }

    /**
     * 面包屑末端（当前分类）。模板里 Smarty 取数组末元素不方便，单开一个出口。
     */
    public static function current(mixed $category, int|string|null $id): array
    {
        $trail = self::crumb($category, $id);
        return $trail ? (array)end($trail) : [];
    }


    /**
     * 某个分类的直接子分类（手机端分类页顶部的横滑筛选条要用）。
     */
    public static function children(mixed $category, int|string|null $id, int $limit = 0): array
    {
        $id = is_numeric($id) ? (int)$id : 0;
        if ($id <= 0 || !is_array($category)) {
            return [];
        }

        $walk = static function (array $nodes) use (&$walk, $id): array {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
                if ((int)($node['id'] ?? 0) === $id) {
                    return array_values(array_filter(array_map(static function ($kid) {
                        if (!is_array($kid) || (string)($kid['name'] ?? '') === '') {
                            return null;
                        }
                        return ['id' => self::catId($kid['id'] ?? 0), 'name' => (string)$kid['name']];
                    }, $kids)));
                }
                if ($kids) {
                    $hit = $walk($kids);
                    if ($hit) {
                        return $hit;
                    }
                }
            }
            return [];
        };

        $out = $walk($category);
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** 某个分类的直接子分类总数（决定要不要出「更多」） */
    public static function childCount(mixed $category, int|string|null $id): int
    {
        return count(self::children($category, $id));
    }


    /**
     * 「猜你喜欢」标签条要显示的分类。
     *
     * 站长在后台挑（setting 'feed_cats'，有序 id 数组），没挑就退回前 $fallback 个一级分类。
     * 和楼层不同的两点：
     *   · 允许任意层级 —— 热门的二级分类直接放到标签条上是很常见的运营需求；
     *   · 允许伪分类「推荐」（id 'recommend'）—— 它作为一个标签是合理的。
     * 返回 [['id' => int|'recommend', 'name' => string, 'curated' => bool], ...]
     */
    public static function feedCategoryList(mixed $setting, mixed $category, int $fallback = 12): array
    {
        $picked = [];
        foreach (self::list($setting, 'feed_cats') as $raw) {
            $id = self::catId(is_array($raw) ? ($raw['id'] ?? 0) : $raw);
            if ($id !== 0 && $id !== '') {
                $picked[] = $id;
            }
        }
        if (!$picked) {
            return self::topCategories($category, $fallback);
        }

        $index = self::indexTree($category);
        $out = [];
        $seen = [];
        foreach ($picked as $id) {
            $key = (string)$id;
            if (isset($index[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $index[$key];
            }
        }
        //站长挑的分类可能已经全被删了，别让标签条空着
        return $out ?: self::topCategories($category, $fallback);
    }

    /**
     * 整棵树拍平成 id => ['id','name']。任意层级都收，伪分类也收。
     * 只在需要"按 id 找任意节点"时用，首页每次请求最多走一遍，几千个节点也是毫秒级。
     */
    private static function indexTree(mixed $nodes, array &$out = []): array
    {
        if (!is_array($nodes)) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (!is_array($node) || (string)($node['name'] ?? '') === '') {
                continue;
            }
            $id = self::catId($node['id'] ?? 0);
            if ($id !== 0 && $id !== '') {
                $out[(string)$id] = ['id' => $id, 'name' => (string)$node['name']];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                self::indexTree($node['children'], $out);
            }
        }
        return $out;
    }

    /**
     * 分类 id 规范化。
     *
     * 核心在树顶塞了一个**伪分类**「推荐」，它的 id 是字符串 'recommend'
     * 而不是数字（$shop->getCategory() 里手动 unshift 的）。任何 (int) 强转
     * 都会把它变成 0 —— 链接指向 /cat/0、前端按 id 查树查不到、面板永远空白。
     * 所以凡是要往模板/JS 传的分类 id 一律走这里：数字保持 int，其余保持原样。
     */
    private static function catId(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return 0;
        }
        //只放行 [A-Za-z0-9_-]，非法 id 直接丢掉，别让它拼进 URL
        return ctype_digit($raw) ? (int)$raw : (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $raw) === 1 ? $raw : 0);
    }

    /**
     * 只取一级分类（不带子级）。
     *
     * 大站有几百个分类、上千个节点：整棵树渲染进 HTML 会把首页撑到几 MB
     * （实测 300 个一级分类 → 4.3MB / 37000 个标签）。服务端只出一级，
     * 子级由前端从 /user/api/index/data 取一次、用到哪支建哪支。
     *
     * @param int $limit 0 = 不限
     */
    public static function topCategories(mixed $category, int $limit = 0): array
    {
        if (!is_array($category)) {
            return [];
        }
        $out = [];
        foreach ($category as $node) {
            if (!is_array($node) || (string)($node['name'] ?? '') === '') {
                continue;
            }
            $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
            //二级只带前 8 个，够楼层标签用；一级下面挂上百个二级的站不在少数，
            //全带上等于把树又搬回 HTML 里。
            $subs = [];
            foreach ($kids as $kid) {
                if (!is_array($kid) || (string)($kid['name'] ?? '') === '') {
                    continue;
                }
                $subs[] = ['id' => self::catId($kid['id'] ?? 0), 'name' => (string)$kid['name']];
                if (count($subs) >= 8) {
                    break;
                }
            }
            $out[] = [
                'id' => self::catId($node['id'] ?? 0),
                'name' => (string)$node['name'],
                'icon' => self::image($node['icon'] ?? ''),
                'kids' => count($kids),
                'subs' => $subs,
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * 当前分类所在的那一支：返回 ['root' => 一级分类 id, 'nodes' => 它的子树]。
     *
     * 列表页侧栏只预渲染这一支，其余一级分类是折叠的、点开才由 JS 建。
     * 不这么做的话，一个有 300 个一级分类的站，光侧栏就是几 MB HTML。
     */
    public static function branch(mixed $category, mixed $activeId): array
    {
        $id = (int)$activeId;
        $none = ['root' => 0, 'nodes' => []];
        if (!is_array($category) || $id <= 0) {
            return $none;
        }

        foreach ($category as $node) {
            if (!is_array($node)) {
                continue;
            }
            $rootId = (int)($node['id'] ?? 0);
            $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
            if ($rootId === $id) {
                return ['root' => $rootId, 'nodes' => $kids];
            }
            if ($kids && self::contains($kids, $id)) {
                return ['root' => $rootId, 'nodes' => $kids];
            }
        }
        return $none;
    }

    /** 某个节点的子树里是否含 activeId（模板用来决定这一层展不展开） */
    public static function onPath(mixed $node, mixed $activeId): bool
    {
        $id = (int)$activeId;
        if ($id <= 0 || !is_array($node)) {
            return false;
        }
        $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
        return $kids !== [] && self::contains($kids, $id);
    }

    /** 子树里是否含某个 id */
    private static function contains(array $nodes, int $id): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((int)($node['id'] ?? 0) === $id) {
                return true;
            }
            $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
            if ($kids && self::contains($kids, $id)) {
                return true;
            }
        }
        return false;
    }

    /** 一级分类总数（用于「查看全部分类」这类文案） */
    public static function topCount(mixed $category): int
    {
        return is_array($category) ? count(array_filter($category, 'is_array')) : 0;
    }

    /**
     * 首页要做楼层的分类。
     * 站长在后台勾选了就按勾选顺序，没勾就退回按 sort 取前 N 个。
     */
    public static function floorCategoryList(mixed $setting, mixed $category, int $fallback = 6): array
    {
        $picked = [];
        foreach (self::list($setting, 'floor_cats') as $id) {
            $id = (int)(is_array($id) ? ($id['id'] ?? 0) : $id);
            if ($id > 0) {
                $picked[] = $id;
            }
        }

        //伪分类「推荐」（id 是字符串 'recommend'）不能当楼层：它已经有专门的"推荐楼层"，
        //再按分类开一层就是两个内容一模一样的"推荐"。只有真实分类（int id）才进候选。
        $tops = array_values(array_filter(self::topCategories($category), static function (array $t): bool {
            return is_int($t['id']);
        }));
        if (!$picked) {
            return array_slice($tops, 0, $fallback);
        }

        $byId = [];
        foreach ($tops as $t) {
            $byId[$t['id']] = $t;
        }
        $out = [];
        foreach ($picked as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out ?: array_slice($tops, 0, $fallback);
    }

}

<?php
declare(strict_types=1);

namespace App\View\User\Theme\Seattle;

use InvalidArgumentException;
use JsonException;

final class QuickMenu
{
    private const MAX_ITEMS = 12;

    private const INTERNAL_ROUTES = [
        'store' => '/',
        'query' => '/user/index/query',
        'dashboard' => '/user/dashboard/index',
        'purchase' => '/user/personal/purchaseRecord',
        'recharge' => '/user/recharge/index',
        'bill' => '/user/bill/index',
        'business' => '/user/business/index',
        'promote' => '/user/agent/promote',
        'member' => '/user/agent/member',
        'login' => '/user/authentication/login',
        'register' => '/user/authentication/register',
    ];

    private const AUDIENCES = ['all', 'member', 'guest'];
    private const LINK_TYPES = ['internal', 'external'];
    //显示站点：all=主站+分站，master=仅主站，branch=仅分站（issue #788：代理站不该被迫展示主站入口）
    private const SITES = ['all', 'master', 'branch'];

    /**
     * @param mixed $value
     */
    public static function normalizeForStorage($value): string
    {
        $items = self::normalizeItems(self::decodeValue($value));

        try {
            return (string)json_encode(
                $items,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('快捷菜单包含无法保存的字符。', 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $setting
     * @param mixed                $user
     * @param mixed                $isBranchSite 当前是否分站（代理站）域名
     */
    public static function renderPanel(array $setting, $user = null, $isBranchSite = false): string
    {
        if (array_key_exists('quick_menu', $setting)) {
            try {
                $items = self::normalizeRenderableItems(self::decodeValue($setting['quick_menu']));
            } catch (InvalidArgumentException $exception) {
                return '';
            }
        } else {
            $items = self::legacyItems($setting);
        }

        $loggedIn = (bool)$user;
        $isBranchSite = (bool)$isBranchSite;
        $links = [];

        foreach ($items as $item) {
            if ($item['site'] === 'master' && $isBranchSite) {
                continue;
            }

            if ($item['site'] === 'branch' && !$isBranchSite) {
                continue;
            }

            if ($item['audience'] === 'member' && !$loggedIn) {
                continue;
            }

            if ($item['audience'] === 'guest' && $loggedIn) {
                continue;
            }

            if ($item['link_type'] === 'external') {
                $href = $item['url'];
                $attributes = ' target="_blank" rel="noopener noreferrer nofollow"';
            } else {
                $href = self::INTERNAL_ROUTES[$item['target']];
                $attributes = '';
            }

            $links[] = '<a href="' . self::escape($href) . '"' . $attributes . '>'
                . '<span class="material-icons-outlined" aria-hidden="true">'
                . self::escape($item['icon'])
                . '</span><span>'
                . self::escape(lang($item['name']))
                . '</span></a>';
        }

        if ($links === []) {
            return '';
        }

        //标题与菜单项名都是后台可配的动态文案：默认值随主题词包翻译，
        //站长自定义的内容走 LANG_MISS 交给翻译插件补
        $title = lang(self::plainSetting($setting, 'quick_title', '快捷入口'));
        $subtitle = lang(self::plainSetting($setting, 'quick_subtitle', '常用服务'));

        return '<section class="st-panel">'
            . '<div class="st-panel__header"><div>'
            . '<span class="st-panel__icon material-icons-outlined" aria-hidden="true">bolt</span>'
            . '<div><h2>' . self::escape($title) . '</h2><p>' . self::escape($subtitle) . '</p></div>'
            . '</div></div>'
            . '<div class="st-panel__body st-action-grid">' . implode('', $links) . '</div>'
            . '</section>';
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    private static function decodeValue($value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return [];
            }

            if (preg_match('/^%(?:5B|7B)/i', $value) === 1) {
                $value = rawurldecode($value);
            }

            $decoded = json_decode($value, true);

            if (!is_array($decoded)) {
                //渲染时 $setting 已被核心的 ViewSafe 整体转义过一遍，JSON 里的双引号变成了
                //&quot;，直接解析必然语法错误、整个面板就此消失。这里把那一层转义原样还原
                //再解析一次。原始 JSON 一次就能解析成功，走不到这里，所以不会误伤未转义的值。
                $decoded = json_decode(self::decodeEntities($value), true);
            }

            if (!is_array($decoded)) {
                throw new InvalidArgumentException('快捷菜单数据格式不正确。');
            }
        } else {
            throw new InvalidArgumentException('快捷菜单必须是列表。');
        }

        if (!is_array($decoded) || !self::isList($decoded)) {
            throw new InvalidArgumentException('快捷菜单必须是列表。');
        }

        return $decoded;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array{name: string, icon: string, audience: string, link_type: string, target: string, url: string}>
     */
    private static function normalizeItems(array $items): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('快捷菜单最多只能添加 12 项。');
        }

        $targetIds = self::optionIds(Config::QUICK_ENTRY_TARGETS);
        $normalized = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单格式不正确。');
            }

            $name = self::field($item, 'name');
            $icon = self::field($item, 'icon');
            $audience = strtolower(self::field($item, 'audience'));
            $linkType = strtolower(self::field($item, 'link_type'));
            $site = strtolower(self::field($item, 'site'));
            if ($site === '') {
                $site = 'all'; //旧数据无 site 字段，默认全部站点（行为不变）
            }

            if ($name === '') {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单缺少名称。');
            }

            if (self::length($name) > 32 || self::hasControlCharacters($name)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单名称不正确。');
            }

            if (!MaterialIcons::contains($icon)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单图标不正确。');
            }

            if (!in_array($audience, self::AUDIENCES, true)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单显示范围不正确。');
            }

            if (!in_array($linkType, self::LINK_TYPES, true)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单链接类型不正确。');
            }

            if (!in_array($site, self::SITES, true)) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单显示站点不正确。');
            }

            if ($linkType === 'internal') {
                $target = self::field($item, 'target');

                if (!in_array($target, $targetIds, true) || !isset(self::INTERNAL_ROUTES[$target])) {
                    throw new InvalidArgumentException('第 ' . ($index + 1) . ' 个快捷菜单站内目标不正确。');
                }

                $url = '';
            } else {
                $target = '';
                $url = self::validateExternalUrl(self::field($item, 'url'), $index);
            }

            $normalized[] = [
                'name' => $name,
                'icon' => $icon,
                'audience' => $audience,
                'site' => $site,
                'link_type' => $linkType,
                'target' => $target,
                'url' => $url,
            ];
        }

        return $normalized;
    }

    /**
     * A damaged entry must not hide every valid shortcut on the storefront.
     * Storage validation remains strict; rendering safely skips only the bad
     * entries and still enforces the same item limit and field allowlists.
     *
     * @param array<int, mixed> $items
     * @return array<int, array{name: string, icon: string, audience: string, link_type: string, target: string, url: string}>
     */
    private static function normalizeRenderableItems(array $items): array
    {
        $normalized = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            try {
                $entry = self::normalizeItems([$item]);
            } catch (InvalidArgumentException $exception) {
                continue;
            }

            if ($entry !== []) {
                $normalized[] = $entry[0];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function field(array $item, string $key): string
    {
        if (!array_key_exists($key, $item) || !is_string($item[$key])) {
            return '';
        }

        return trim($item[$key]);
    }

    private static function validateExternalUrl(string $url, int $index): string
    {
        $number = $index + 1;

        if (
            $url === ''
            || strlen($url) > 2048
            || self::hasControlCharacters($url)
            || preg_match('/\s/u', $url) === 1
            || strpos($url, '\\') !== false
            || strpos($url, '//') === 0
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('第 ' . $number . ' 个快捷菜单外链地址不正确。');
        }

        $parts = parse_url($url);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || trim((string)$parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new InvalidArgumentException('第 ' . $number . ' 个快捷菜单外链地址不正确。');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $setting
     * @return array<int, array{name: string, icon: string, audience: string, link_type: string, target: string, url: string}>
     */
    private static function legacyItems(array $setting): array
    {
        return [
            self::legacyItem($setting, 'quick_primary', '查询订单', 'receipt_long', 'query', 'all'),
            self::legacyItem($setting, 'quick_member', '会员中心', 'person', 'dashboard', 'member'),
            self::legacyItem($setting, 'quick_guest', '会员登录', 'login', 'login', 'guest'),
        ];
    }

    /**
     * @param array<string, mixed> $setting
     * @return array{name: string, icon: string, audience: string, link_type: string, target: string, url: string}
     */
    private static function legacyItem(
        array $setting,
        string $prefix,
        string $defaultName,
        string $defaultIcon,
        string $defaultTarget,
        string $audience
    ): array {
        $name = self::plainSetting($setting, $prefix . '_name', $defaultName);
        $icon = self::scalarSetting($setting, $prefix . '_icon');
        $target = self::scalarSetting($setting, $prefix . '_target');

        if (!MaterialIcons::contains($icon)) {
            $icon = $defaultIcon;
        }

        if (
            !in_array($target, self::optionIds(Config::QUICK_ENTRY_TARGETS), true)
            || !isset(self::INTERNAL_ROUTES[$target])
        ) {
            $target = $defaultTarget;
        }

        return [
            'name' => $name,
            'icon' => $icon,
            'audience' => $audience,
            'site' => 'all',
            'link_type' => 'internal',
            'target' => $target,
            'url' => '',
        ];
    }

    /**
     * @param array<int, array{id: string, name: string}> $options
     * @return array<int, string>
     */
    private static function optionIds(array $options): array
    {
        $ids = [];

        foreach ($options as $option) {
            if (isset($option['id']) && is_string($option['id'])) {
                $ids[] = $option['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $setting
     */
    private static function plainSetting(array $setting, string $key, string $default): string
    {
        //同样先还原 ViewSafe 那一层转义：本方法的结果最后会经 self::escape() 输出，
        //不还原的话标题里的 & < > 引号会被转义两次，前台显示成 &amp;amp; 这种。
        $value = self::decodeEntities(self::scalarSetting($setting, $key));
        $value = trim(strip_tags($value));
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param array<string, mixed> $setting
     */
    private static function scalarSetting(array $setting, string $key): string
    {
        if (!array_key_exists($key, $setting) || !is_scalar($setting[$key])) {
            return '';
        }

        return trim((string)$setting[$key]);
    }

    /**
     * 反转一次 htmlspecialchars(ENT_QUOTES)：核心渲染前会对模板数据整体转义，
     * 而本类自己负责输出转义，两边叠加会破坏 JSON、并让文案被转义两次。
     */
    private static function decodeEntities(string $value): string
    {
        return htmlspecialchars_decode($value, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

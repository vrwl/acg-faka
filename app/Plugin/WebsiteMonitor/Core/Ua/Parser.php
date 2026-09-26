<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Ua;

use App\Plugin\WebsiteMonitor\Consts\Kind;

/**
 * User-Agent 解析：浏览器、操作系统、设备。
 *
 * 只在守护进程侧跑（每个 UA 一次，结果写进 wm_ua 字典后就不用再解析了），
 * 所以这里可以放心用正则，不必像热路径那样抠每一微秒。
 *
 * 顺序很重要：判定要从最具体到最宽泛，否则所有基于 Chromium 的浏览器
 * 都会被认成 Chrome，所有套壳浏览器都会被认成 Safari。
 */
final class Parser
{
    /** [显示名, 正则]，从具体到宽泛 */
    private const BROWSERS = [
        ['微信', '#micromessenger#i'],
        ['QQ 浏览器', '#qqbrowser|mqqbrowser#i'],
        ['QQ', '#\bqq/#i'],
        ['UC 浏览器', '#ucbrowser|ucweb#i'],
        ['百度浏览器', '#baidubrowser|baiduboxapp#i'],
        ['夸克', '#quark#i'],
        ['360 浏览器', '#360se|360ee|qihoobrowser#i'],
        ['搜狗浏览器', '#se 2\.x|metasr|sogoumobilebrowser#i'],
        ['2345 浏览器', '#2345explorer|mb2345browser#i'],
        ['猎豹浏览器', '#lbbrowser#i'],
        ['Maxthon', '#maxthon#i'],
        ['支付宝', '#alipayclient#i'],
        ['钉钉', '#dingtalk#i'],
        ['飞书', '#lark#i'],
        ['Vivo 浏览器', '#vivobrowser#i'],
        ['OPPO 浏览器', '#heytapbrowser|oppobrowser#i'],
        ['小米浏览器', '#miuibrowser#i'],
        ['华为浏览器', '#huaweibrowser#i'],
        ['三星浏览器', '#samsungbrowser#i'],
        ['Yandex', '#yabrowser#i'],
        ['Brave', '#brave#i'],
        ['Vivaldi', '#vivaldi#i'],
        ['Opera', '#opr/|opera#i'],
        ['Edge', '#edg/|edge/|edga/|edgios/#i'],
        ['Firefox', '#firefox/|fxios/#i'],
        ['Chrome', '#chrome/|crios/|chromium#i'],
        ['Safari', '#safari/#i'],
        ['IE', '#msie |trident/#i'],
    ];

    /** [显示名, 正则] */
    private const SYSTEMS = [
        ['HarmonyOS', '#harmonyos|openharmony#i'],
        ['Android', '#android#i'],
        ['iPadOS', '#ipad#i'],
        ['iOS', '#iphone|ipod|cpu iphone os#i'],
        ['Windows 11', '#windows nt 10\.0.*(?:win64|wow64).*(?:edg/1[2-9]\d)#i'],
        ['Windows 10', '#windows nt 10\.0#i'],
        ['Windows 8.1', '#windows nt 6\.3#i'],
        ['Windows 8', '#windows nt 6\.2#i'],
        ['Windows 7', '#windows nt 6\.1#i'],
        ['Windows', '#windows#i'],
        ['macOS', '#mac os x|macintosh#i'],
        ['Chrome OS', '#cros#i'],
        ['Ubuntu', '#ubuntu#i'],
        ['Linux', '#linux#i'],
        ['FreeBSD', '#freebsd#i'],
    ];

    /**
     * @return array{browser:string,os:string,dev:int}
     */
    public static function parse(string $ua): array
    {
        if (trim($ua) === '') {
            return ['browser' => '', 'os' => '', 'dev' => Kind::DEV_OTHER];
        }

        $browser = '';
        foreach (self::BROWSERS as [$name, $re]) {
            if (preg_match($re, $ua) === 1) {
                $browser = $name;
                break;
            }
        }

        $os = '';
        foreach (self::SYSTEMS as [$name, $re]) {
            if (preg_match($re, $ua) === 1) {
                $os = $name;
                break;
            }
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'dev' => \App\Plugin\WebsiteMonitor\Core\Collect\Classify::device($ua),
        ];
    }

    /**
     * 带主版本号的浏览器名（如 Chrome 120）。展示更有信息量，但会让字典基数变大，
     * 所以只在「浏览器版本分布」这类专门的报表里用。
     */
    public static function browserWithVersion(string $ua): string
    {
        $parsed = self::parse($ua);
        if ($parsed['browser'] === '') {
            return '';
        }
        $patterns = [
            'Edge' => '#edg[a-z]*/(\d+)#i',
            'Chrome' => '#(?:chrome|crios)/(\d+)#i',
            'Firefox' => '#(?:firefox|fxios)/(\d+)#i',
            'Safari' => '#version/(\d+)#i',
            'Opera' => '#opr/(\d+)#i',
        ];
        $re = $patterns[$parsed['browser']] ?? null;
        if ($re !== null && preg_match($re, $ua, $m) === 1) {
            return $parsed['browser'] . ' ' . $m[1];
        }
        return $parsed['browser'];
    }
}

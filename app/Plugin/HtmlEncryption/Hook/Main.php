<?php
declare (strict_types=1);

namespace App\Plugin\HtmlEncryption\Hook;

use App\Util\Plugin;
use App\Util\Str;
use Kernel\Annotation\Hook;

class Main
{

    /** 同一次请求只布防一次 */
    private static bool $armed = false;

    /**
     * 这里只"布防"，真正的加密挪到输出缓冲回调里做。
     *
     * 原先是在 RENDER_VIEW 里当场把 $html 换成加密脚本。问题在于 RENDER_VIEW 跑在
     * 控制器内部，比 HTTP_ROUTE_RESPONSE 早一步：等别的插件想往响应尾部注入东西时，
     * 页面已经变成一段 base64 + 解码脚本，连 </body> 都不存在了。人机验证（Turnstile）
     * 正是这么废掉的 —— 它在 HTTP_ROUTE_RESPONSE 里拿 </body> 当锚点，找不到就直接
     * 放弃注入，于是除了整站拦截（那是独立的拦截页，不走注入）以外，登录/注册/下单
     * 等所有内联验证组件统统不显示。见 issue #822。
     *
     * 改用 ob_start：回调在脚本结束时才触发，拿到的是真正要发出去的最终 HTML，
     * 别的插件注入的内容都已经在里面了。于是守卫脚本是被一起加密进 payload 的，
     * 解码后 document.write 出来照常执行，组件正常渲染。
     * 另一个好处是不再依赖钩子先后 —— 对方哪怕 echo 完直接 exit，缓冲回调照样会跑。
     *
     * @param string $html
     * @return void
     */
    #[Hook(point: \App\Consts\Hook::RENDER_VIEW)]
    public function RENDER_VIEW(string &$html): void
    {
        if (self::$armed) {
            return;
        }
        //只加密完整 HTML 文档：RENDER_VIEW 在渲染菜单等页面"片段"（插件菜单项、挂件）时也会触发，
        //片段被加密成 document.write 脚本后会把整个页面清空重写，页面直接报废
        if (!str_contains($html, '<html')) {
            return;
        }

        $config = Plugin::getConfig("HtmlEncryption");
        //后台页面不加密：除 /admin 前缀外，插件的后台面板走 /plugin/{名}/... 路由，
        //按页面是否引用后台样式资源识别（前台主题不会引用 /assets/admin/）
        if ($config['impact'] == 0 && (str_starts_with((string)($_GET['s'] ?? ''), "/admin") || str_contains($html, '/assets/admin/css/'))) {
            return;
        }

        self::$armed = true;
        ob_start(static function (string $buffer): string {
            //到了这一步缓冲区里必须仍是完整文档才动手：万一最终输出被换成了重定向、
            //JSON 或别的东西，原样放行，别把非 HTML 内容包进 document.write
            if (!str_contains($buffer, '<html')) {
                return $buffer;
            }
            return self::encrypt($buffer);
        });
    }

    /**
     * @param string $html
     * @return string
     */
    private static function encrypt(string $html): string
    {
        return self::base64($html, self::nonceAttr()) . '<script' . self::nonceAttr() . '>setTimeout(() => {n();} , 1);</script>';
    }

    /**
     * 开了 CSP 就得给自己生成的脚本带上 nonce。
     *
     * 渲染出口是在 RENDER_VIEW 之前注入 nonce 的，而我们真正动手是在 ob_start 回调里，
     * 比那还晚一步——这两段脚本身上天生没有 nonce。强制模式下会被直接拦掉，而页面此时
     * 已经只剩这两段脚本了，于是整站白屏。
     *
     * @return string
     */
    private static function nonceAttr(): string
    {
        try {
            if (class_exists('\\App\\Util\\Csp') && \App\Util\Csp::enabled()) {
                return ' nonce="' . \App\Util\Csp::nonce() . '"';
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /**
     * @param string $html
     * @return string
     */
    private static function base64(string $html, string $nonce = ''): string
    {
        $token = Str::generateRandStr();
        $a = base64_encode($token . $html . $token);
        return <<<JS
<script{$nonce}>var _0x18eb=['write','BBbZW','lBofP','PdMMm','open','{$a}','replace','close'];(function(_0x131ad5,_0x18eb64){var _0x202dfa=function(_0x41a10b){while(--_0x41a10b){_0x131ad5['push'](_0x131ad5['shift']());}};_0x202dfa(++_0x18eb64);}(_0x18eb,0xb4));var _0x202d=function(_0x131ad5,_0x18eb64){_0x131ad5=_0x131ad5-0x0;var _0x202dfa=_0x18eb[_0x131ad5];return _0x202dfa;};function n(){var _0x163cf9={'PdMMm':function(_0x3f0a53,_0x387440){return _0x3f0a53(_0x387440);},'lBofP':function(_0xae3851,_0x5aa59f){return _0xae3851(_0x5aa59f);},'BBbZW':_0x202d('0x1')};document[_0x202d('0x0')]();document[_0x202d('0x4')](decodeURIComponent(_0x163cf9[_0x202d('0x7')](escape,_0x163cf9[_0x202d('0x6')](atob,_0x163cf9[_0x202d('0x5')])))[_0x202d('0x2')](/{$token}/g,''));document[_0x202d('0x3')]();}</script>
JS;
    }
}
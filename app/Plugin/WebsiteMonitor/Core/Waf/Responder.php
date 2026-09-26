<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core\Waf;

use App\Plugin\WebsiteMonitor\Api\Decision;
use App\Plugin\WebsiteMonitor\Core\Acl\AdminGuard;
use App\Plugin\WebsiteMonitor\Core\Lang;

/**
 * 拦截响应。
 *
 * 刻意**不走 UserPlugin::render()**：那条路会查 Config::list()、Business、加载 Smarty、
 * 读主题配置 —— 在被攻击时每个被拦请求都跑一遍数据库查询，等于帮攻击者放大攻击。
 * 这里是自包含的静态 HTML + 内联 CSS，零数据库、零模板引擎、零外链资源。
 */
final class Responder
{
    /**
     * 输出拦截响应并结束请求。
     *
     * 注意：exit 之后 register_shutdown_function 仍会执行，
     * 所以 Collector::flush() 依然会把这条被拦记录写进 spool。
     */
    public static function deny(Decision $decision, array $thresholds = []): void
    {
        $id = $decision->requestId();
        $status = $decision->status > 0 ? $decision->status : (int)($thresholds['block_status_code'] ?? 403);

        if (!headers_sent()) {
            http_response_code($status);
            header('X-WM-Id: ' . $id);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            if ($decision->retryAfter > 0) {
                header('Retry-After: ' . $decision->retryAfter);
            }
        }

        if (Surface::isJsonWanted()) {
            if (!headers_sent()) {
                header('content-type:application/json;charset=utf-8');
            }
            exit(json_encode([
                'code' => $status,
                'msg' => $decision->publicReason !== '' ? $decision->publicReason : self::defaultReason($decision),
                'data' => ['id' => $id],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (!headers_sent()) {
            header('content-type:text/html;charset=utf-8');
        }
        exit(self::html($decision, $id, $status, $thresholds));
    }

    /**
     * 不暴露任何规则细节 —— 告诉攻击者「你的 union select 被 sqli.union 拦了」
     * 等于免费送他一个绕过提示。用户看到的只有大类原因。
     */
    private static function defaultReason(Decision $decision): string
    {
        return match ($decision->action) {
            Decision::THROTTLE => lang('访问过于频繁，请稍后再试'),
            default => match ($decision->ruleKind) {
                \App\Plugin\WebsiteMonitor\Consts\Kind::ATK_REGION => lang('你所在的地区暂时无法访问本站'),
                \App\Plugin\WebsiteMonitor\Consts\Kind::ATK_BLACKLIST => lang('你的 IP 不在本站允许的访问范围内'),
                \App\Plugin\WebsiteMonitor\Consts\Kind::ATK_CC => lang('访问过于频繁，请稍后再试'),
                default => lang('你的请求被本站安全策略拦截'),
            },
        };
    }

    private static function html(Decision $decision, string $id, int $status, array $thresholds): string
    {
        $siteName = trim((string)($thresholds['block_page_title'] ?? ''));
        if ($siteName === '') {
            $siteName = lang('安全防护');
        }
        $contact = trim((string)($thresholds['block_page_contact'] ?? ''));
        $reason = $decision->publicReason !== '' ? $decision->publicReason : self::defaultReason($decision);

        $title = $status === 429 ? lang('访问过于频繁') : lang('访问被拒绝');
        $icon = $status === 429 ? '&#8987;' : '&#128274;';

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ip = $e($decision->ip);
        $now = $e(date('Y-m-d H:i:s'));
        $idEsc = $e($id);
        $reasonEsc = $e($reason);
        $siteEsc = $e($siteName);

        $labelId = $e(lang('请求编号'));
        $labelIp = $e(lang('你的 IP'));
        $labelTime = $e(lang('时间'));
        $hintId = $e(lang('如果你认为这是误拦，请把上面的请求编号提供给站点管理员，他可以据此查到完整记录。'));

        $countdown = '';
        if ($decision->retryAfter > 0) {
            $sec = (int)$decision->retryAfter;
            $tpl = $e(Lang::t('请在 :s 秒后重试', ['s' => '%%S%%']));
            $countdown = '<div class="wm-retry" id="wm-retry" data-left="' . $sec . '" data-tpl="' . $tpl . '">'
                . str_replace('%%S%%', (string)$sec, $tpl) . '</div>';
        }

        $contactBlock = '';
        if ($contact !== '') {
            $contactBlock = '<div class="wm-contact"><span>' . $e(lang('申诉方式')) . '</span>' . $e($contact) . '</div>';
        }

        //站长自救：后台都进不去时，这行完整路径是唯一的出路。
        //只在没配置联系方式时显示，避免把服务器路径暴露给普通访客。
        $rescue = '';
        if ($contact === '') {
            $rescue = '<div class="wm-rescue">' . $e(lang('站长自救：在服务器创建空文件即可立即停用全部防护'))
                . '<code>' . $e(AdminGuard::rescueHint()) . '</code></div>';
        }

        $script = $decision->retryAfter > 0
            ? '<script>(function(){var n=document.getElementById("wm-retry");if(!n)return;'
            . 'var l=parseInt(n.getAttribute("data-left"),10)||0,t=n.getAttribute("data-tpl");'
            . 'var i=setInterval(function(){l--;if(l<=0){clearInterval(i);location.reload();return;}'
            . 'n.textContent=t.replace("%%S%%",l);},1000);})();</script>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>{$title} · {$siteEsc}</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
background:#f5f6f8;color:#1f2430}
.wm-card{width:100%;max-width:520px;background:#fff;border-radius:16px;padding:40px 32px;
box-shadow:0 1px 2px rgba(16,24,40,.06),0 12px 32px -8px rgba(16,24,40,.12);text-align:center}
.wm-icon{font-size:44px;line-height:1;margin-bottom:16px}
h1{margin:0 0 10px;font-size:21px;font-weight:600;letter-spacing:.2px}
.wm-reason{margin:0 0 24px;color:#5b6478;font-size:15px}
.wm-retry{margin:-8px 0 22px;font-size:14px;color:#b25a00;font-variant-numeric:tabular-nums}
.wm-meta{text-align:left;background:#f7f8fa;border:1px solid #eceef2;border-radius:10px;padding:14px 16px;margin-bottom:18px}
.wm-meta div{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:4px 0;color:#5b6478}
.wm-meta b{font-weight:600;color:#1f2430;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
word-break:break-all;text-align:right;font-variant-numeric:tabular-nums}
.wm-hint{font-size:13px;color:#79839a;margin:0}
.wm-contact{margin-top:16px;font-size:13.5px;color:#1f2430;background:#eef4ff;border-radius:10px;padding:11px 14px;
display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.wm-contact span{color:#5b6478}
.wm-rescue{margin-top:18px;font-size:12px;color:#98a1b5;line-height:1.6}
.wm-rescue code{display:block;margin-top:6px;padding:8px 10px;background:#f7f8fa;border-radius:8px;
font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;color:#5b6478;word-break:break-all}
@media (prefers-color-scheme:dark){
body{background:#14171c;color:#e6e9ef}
.wm-card{background:#1c2027;box-shadow:0 1px 2px rgba(0,0,0,.4),0 12px 32px -8px rgba(0,0,0,.55)}
.wm-reason,.wm-meta div{color:#98a1b5}
.wm-meta{background:#22262e;border-color:#2c313a}
.wm-meta b{color:#e6e9ef}
.wm-hint,.wm-rescue{color:#79839a}
.wm-contact{background:#1e2833;color:#e6e9ef}
.wm-rescue code{background:#22262e;color:#98a1b5}
}
</style></head><body>
<div class="wm-card">
  <div class="wm-icon">{$icon}</div>
  <h1>{$title}</h1>
  <p class="wm-reason">{$reasonEsc}</p>
  {$countdown}
  <div class="wm-meta">
    <div><span>{$labelId}</span><b>{$idEsc}</b></div>
    <div><span>{$labelIp}</span><b>{$ip}</b></div>
    <div><span>{$labelTime}</span><b>{$now}</b></div>
  </div>
  <p class="wm-hint">{$hintId}</p>
  {$contactBlock}
  {$rescue}
</div>
{$script}
</body></html>
HTML;
    }
}

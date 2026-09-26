<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Hook;

use App\Plugin\WebsiteMonitor\Consts\Kind;
use App\Plugin\WebsiteMonitor\Core\Collect\Collector;
use Kernel\Annotation\Hook;

/**
 * 请求结果回填。KERNEL_INIT 时还不知道这次请求会返回什么，这些点位负责补齐。
 *
 * 全部方法都必须绝对安静：采集出问题也不能影响业务，所以清一色 try/catch 吞掉。
 */
class Collect
{
    /**
     * 成功返回（每个非 404 请求的末尾）。
     * $result 是字符串说明返回的是渲染好的页面，否则是 API 的数组。
     */
    #[Hook(point: \App\Consts\Hook::HTTP_ROUTE_RESPONSE)]
    public function response(string $routePath = '', mixed $result = null): void
    {
        try {
            Collector::outcome(is_string($result) ? Kind::REQ_PAGE : Kind::REQ_API, 200);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 404。这是扫描器探测最直接的信号 —— 正常访客几乎不会连续踩 404。
     *
     * 写十六进制字面量而不是 \App\Consts\Hook::HTTP_NOT_FOUND：
     * 这个常量是 3.5.8 才加的，老核心上引用它会让属性求值抛 Error，把插件卡在半启用态。
     */
    #[Hook(point: 0x48)]
    public function notFound(string $routePath = ''): void
    {
        try {
            Collector::outcome(Kind::REQ_404, 404);
            if (class_exists('\App\Plugin\WebsiteMonitor\Core\Waf\Scanner')) {
                \App\Plugin\WebsiteMonitor\Core\Waf\Scanner::onNotFound($routePath);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 控制器调用前：拿到真实的会员 id（KERNEL_INIT 时会话还没解析）
     */
    #[Hook(point: \App\Consts\Hook::CONTROLLER_CALL_BEFORE)]
    public function before(object $controller = null, string $action = ''): void
    {
        try {
            if (!Collector::active()) {
                return;
            }
            $user = \App\Util\Context::get(\App\Consts\User::SESSION);
            if ($user !== null && isset($user->id)) {
                Collector::setUid((int)$user->id);
            }
        } catch (\Throwable $e) {
        }
    }
}

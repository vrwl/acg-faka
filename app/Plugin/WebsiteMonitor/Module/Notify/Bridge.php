<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Module\Notify;

use App\Plugin\WebsiteMonitor\Core\Log;
use App\Plugin\WebsiteMonitor\Core\Runtime;
use App\Plugin\WebsiteMonitor\Core\Settings;

/**
 * 与「通知中心」插件的桥。
 *
 * 通知中心是**可选依赖**：没装 / 没启用时，告警只写本地日志，其它功能一律不受影响。
 * 探测方式照抄通知中心自己探测线程管理器的写法 —— 只读目录与配置，
 * 不 use 它的类，因为它可能根本不存在。
 *
 * 优先走新版的对外门面 Api\Notify；老版本没有门面时退回直接调 Core\Notifier，
 * 这样装了旧版通知中心的站点也能用。
 */
final class Bridge
{
    private const FACADE = '\App\Plugin\NotificationCenter\Api\Notify';
    private const NOTIFIER = '\App\Plugin\NotificationCenter\Core\Notifier';
    private const NOTIFICATION = '\App\Plugin\NotificationCenter\Core\Notification';

    /** 通知中心固定的七个 Telegram 分组之一，安全类只能用这个 */
    private const TG_GROUP = 'security';

    /** 复用通知中心出厂的安全告警模板，不新增模板键（零版本耦合） */
    private const TEMPLATE = 'security_alert';

    public static function available(): bool
    {
        if (!Settings::bool('notify_enabled')) {
            return false;
        }
        $nc = Runtime::notificationCenter();
        return $nc['api'] === true;
    }

    /**
     * 发一条安全告警。永不抛异常。
     *
     * @param array<string,mixed> $spec 见 Alert::raise 组装出来的结构
     * @return bool 是否真的入队了
     */
    public static function send(array $spec): bool
    {
        if (!self::available()) {
            //降级：至少让站长在插件日志里能看到
            Log::warn('[告警] ' . (string)($spec['title'] ?? ''), [
                'summary' => (string)($spec['summary'] ?? ''),
                'level' => (string)($spec['level'] ?? ''),
                'nc' => '未安装或未启用',
            ]);
            return false;
        }

        try {
            //新版门面：一次调用搞定，参数校验与异常吞掉都在它内部
            if (class_exists(self::FACADE) && method_exists(self::FACADE, 'alert')) {
                $facade = self::FACADE;
                return (bool)$facade::alert($spec);
            }
            return self::legacySend($spec);
        } catch (\Throwable $e) {
            Log::exception('Bridge::send', $e);
            return false;
        }
    }

    /**
     * 旧版通知中心（没有 Api\Notify 门面）的兼容路径。
     *
     * 三条硬约束必须遵守，否则消息会静默丢失：
     *   1. 模板 key 必须已存在于它的注册表里，否则渲染出空邮件 → 用出厂的 security_alert
     *   2. Telegram 行只有 kind === owner 时才会创建 → 必须 toOwner()
     *   3. 分组必须是它固定的七个之一 → 用 security
     *
     * @param array<string,mixed> $spec
     */
    private static function legacySend(array $spec): bool
    {
        if (!class_exists(self::NOTIFIER) || !class_exists(self::NOTIFICATION)) {
            return false;
        }
        $notification = self::NOTIFICATION;
        $notifier = self::NOTIFIER;

        $level = (string)($spec['level'] ?? Settings::LEVEL_WARN);
        $job = $notification::make(
            mb_substr((string)($spec['event'] ?? 'wm.alert'), 0, 48),
            self::TEMPLATE
        )
            ->toOwner()
            ->level($level)
            ->title((string)($spec['title'] ?? ''))
            ->vars(['alert' => AlertVars::build($spec, $level)])
            ->meta(array_merge(['plugin' => Settings::PLUGIN], (array)($spec['meta'] ?? [])));

        if (!empty($spec['dedupe'])) {
            $job->dedupe((string)$spec['dedupe']);
        }
        if (!empty($spec['delay'])) {
            $job->delay((int)$spec['delay']);
        }
        if (($spec['telegram'] ?? true) !== false) {
            $job->telegram(self::TG_GROUP);
        }

        return (bool)$notifier::enqueue($job);
    }

    /**
     * 面板展示用的联动状态
     *
     * @return array{installed:bool,enabled:bool,api:bool,facade:bool,text:string}
     */
    public static function status(): array
    {
        $nc = Runtime::notificationCenter();
        $facade = class_exists(self::FACADE);
        $text = lang('未安装通知中心，告警只会记录在本插件日志里');
        if ($nc['installed'] && !$nc['enabled']) {
            $text = lang('通知中心已安装但未启用');
        } elseif ($nc['api']) {
            $text = $facade
                ? lang('通知中心已就绪（使用对外接口）')
                : lang('通知中心已就绪（旧版兼容模式，建议升级以获得更稳定的对接）');
        }
        return [
            'installed' => (bool)$nc['installed'],
            'enabled' => (bool)$nc['enabled'],
            'api' => (bool)$nc['api'],
            'facade' => $facade,
            'text' => $text,
        ];
    }
}

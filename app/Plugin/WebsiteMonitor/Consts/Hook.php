<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Consts;

/**
 * 本插件对外广播的钩子点位。
 *
 * 订阅方请写十六进制字面量（#[Hook(point: 0x7C101)]），不要引用这里的常量：
 * 本插件未安装时常量不存在，属性求值抛 Error 会让订阅方插件卡在半启用状态。
 *
 * 点位段说明：核心 ≤ 0x18191，线程管理器 0x7A100/0x7A101，通知中心 0x7B100，本插件 0x7C1xx。
 */
interface Hook
{
    /**
     * 判决前的检查点，订阅方可放行或加严。
     *
     * 传参：\App\Plugin\WebsiteMonitor\Api\Decision $decision
     *
     * 重要：内核的 hook() 派发器遇到 bool 返回会短路整条链（后面的订阅方不再执行），
     * 所以否决**不要靠返回值**，而是改 $decision 对象再返回 null：
     *   $decision->allow('插件名', '理由');       // 软放行，后来者仍可加严
     *   $decision->hardAllow('插件名', '理由');   // 强制放行并锁定
     *   $decision->escalate(Decision::BLOCK, '理由');
     * 若确实返回了 bool，本插件会兼容处理：true = hardAllow，false = escalate(BLOCK)。
     */
    public const REQUEST_INSPECT = 0x7C100;

    /** 已拦截一次攻击。传参：array $event（只读快照，返回值被忽略） */
    public const ATTACK_BLOCKED = 0x7C101;

    /** IP 被封禁。传参：array{ip,seconds,reason,source,level,geo} */
    public const IP_BANNED = 0x7C102;

    /** IP 被解封。传参：array{ip,by,reason} */
    public const IP_UNBANNED = 0x7C103;

    /** 规则 / 名单重新编译完成。传参：array{version,rules,acl,ms} */
    public const RULES_COMPILED = 0x7C104;

    /** 站点进入或退出整体过载模式。传参：array{on:bool,qps,threshold} */
    public const OVERLOAD_STATE = 0x7C105;

    /** 每日统计报表已生成。传参：array{day,pv,uv,ip,visits,attack,...} */
    public const DAILY_REPORT = 0x7C106;
}

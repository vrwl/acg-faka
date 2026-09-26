# 对外接口

给其它插件用的两套东西：一个静态门面 `Api\Guard`（你去调它），七个 Hook 点位（它来叫你）。

## 一条铁律：先判断可用

本插件可能没装、没启用、或者表还没建好。**所有调用前先过 `isAvailable()`**，或者干脆不判断 —— 门面里每个方法都自己吞了异常，不可用时返回安全的默认值（`false` / `null` / 空数组），**永远不会向调用方抛异常**。

```php
if (class_exists('\App\Plugin\WebsiteMonitor\Api\Guard')
    && \App\Plugin\WebsiteMonitor\Api\Guard::isAvailable()) {
    // 放心调
}
```

`class_exists()` 这一层不能省：插件目录被整个删掉时类根本不存在，直接引用会 fatal。

## Api\Guard

命名空间 `App\Plugin\WebsiteMonitor\Api\Guard`，全部是静态方法。

### 可用性与版本

```php
Guard::isAvailable(): bool          // 已安装 + 已启用 + 表结构就绪，结果缓存 5 秒
Guard::version(): array             // ['version' => '1.0.0', 'schema' => 3, 'waf_mode' => 'protect', ...]
```

### 封禁与解封

```php
Guard::banIp(string $ip, int $seconds = 0, string $reason = '', string $source = 'api'): bool
Guard::unbanIp(string $ip): bool
Guard::isBanned(string $ip): ?array   // null = 没被封
```

- `$seconds = 0` 是永久封禁。
- `$source` 填你的插件名，后台列表和通知里会显示是谁封的。
- **`banIp()` 返回 `false` 是正常情况**，不是错误：IP 非法、在白名单里、是管理员常用 IP、或者是站点自己的 IP 时都会拒绝执行。别把它当失败重试。
- `isBanned()` 命中时返回 `['banned' => true, 'expire' => 时间戳, 'left' => 剩余秒, 'level' => 第几档, 'rule' => '触发的规则']`。

```php
// 你的插件检测到刷单，封他两小时
Guard::banIp($ip, 7200, '同一 IP 十分钟内下了 40 单', 'MyPlugin');
```

### 白名单与黑名单

```php
Guard::allowIp(string $ip, string $note = '', int $seconds = 0, string $source = 'api'): bool
Guard::disallowIp(string $ip): bool
Guard::isAllowed(string $ip): bool
Guard::denyIp(string $ip, string $note = '', string $source = 'api'): bool
```

`denyIp()` 与 `banIp()` 的区别：前者写进**人工黑名单规则**（永久、出现在后台黑名单列表、要手动删），后者写进**自动封禁列表**（带 TTL、到期自解、可以一键清空）。程序判定的临时处置用 `banIp()`，站长意志的长期拉黑用 `denyIp()`。

### 地区

```php
Guard::blockRegion(string $code, string $effect = 'deny', string $note = '', string $source = 'api'): bool
Guard::unblockRegion(string $code): bool
Guard::lookup(string $ip): array
Guard::lookupMany(array $ips): array
```

`$code` 形如 `CN` / `CN.GD` / `CN.GD.深圳市`。`$effect` 传 `deny` 或 `allow`。

`lookup()` 的返回：

```php
[
  'ok' => true,            // false = IP 库没下载 / 查不到，此时其余字段为 null
  'ip' => '8.8.8.8',
  'country' => 'US',
  'country_name' => '美国',
  'province' => null,
  'city' => null,
  'continent' => 'NA',
  'is_lan' => false,       // 内网地址
  'reason' => null,        // ok=false 时说明原因
  'text' => '美国',         // 拼好的人话，直接显示用这个
]
```

**IP 库没下载时 `ok` 为 `false` 但不抛异常**，地区 ACL 整体跳过（fail-open）—— 绝不会因为缺个库把所有人挡在门外。批量查 IP 用 `lookupMany()`，它共用一次读取器和缓存，比循环调 `lookup()` 快一个数量级。

### 查询

```php
Guard::stats(string $range = '24h'): array           // 1h | 24h | 7d | 30d
Guard::recentAttacks(int $limit = 50, array $filter = []): array
Guard::profile(string $ip): array
Guard::onlineCount(int $window = 0): int
```

`stats()` 返回 `pv / uv / ip / visits / attack / blocked / bans / online / range / available`。不可用时 `available` 为 `false`，其余全是 0 —— 你可以直接拿去显示，不用额外判断。

`recentAttacks()` 的 `$filter` 支持 `ip` / `kind` / `level` / `rule` / `from` / `to`。

`profile()` 是单个 IP 的完整画像：累计请求、累计攻击、首末出现时间、归属地、当前封禁状态、命中过哪些规则。

### 主动检测

```php
Guard::checkRequest(array $input): array
// $input: ['path' => ..., 'query' => ..., 'body' => ..., 'ua' => ...]
// 返回: ['hit' => bool, 'rule' => ?string, 'name' => ?string, 'level' => ?string, 'score' => int]
```

拿本插件的规则集跑一遍任意输入。**不影响当前请求，也不会封禁任何人**，纯检测。适合在你自己的插件里对用户提交的内容做一次预检。

```php
Guard::currentDecision(): ?array
```

当前请求的判决快照。`KERNEL_INIT` 时已经算完了，这里直接返回，零成本。想知道「本次请求是不是被判定为可疑」用它。

## Hook 点位

| 常量 | 值 | 传参 |
|---|---|---|
| `REQUEST_INSPECT` | `0x7C100` | `Api\Decision $decision` |
| `ATTACK_BLOCKED` | `0x7C101` | `array $event`（只读） |
| `IP_BANNED` | `0x7C102` | `array{ip, seconds, reason, source, level, geo}` |
| `IP_UNBANNED` | `0x7C103` | `array{ip, by, reason}` |
| `RULES_COMPILED` | `0x7C104` | `array{version, rules, acl, ms}` |
| `OVERLOAD_STATE` | `0x7C105` | `array{on:bool, qps, threshold}` |
| `DAILY_REPORT` | `0x7C106` | `array{day, pv, uv, ip, visits, attack, ...}` |

### 订阅时必须写十六进制字面量

```php
#[Hook(point: 0x7C102)]           // ✅ 对
public function onBanned(array $e): void { ... }

#[Hook(point: Hook::IP_BANNED)]   // ❌ 错
```

本插件没安装时那个常量不存在，属性求值会抛 `Error`，**你的插件会卡在半启用状态**。这是跨插件订阅的通用规矩，不只是本插件。

> 新增 `#[Hook]` 方法后，必须把你的插件**停用再启用**一次 —— `runtime/plugin/hook` 是加密编译缓存，不重建不会认新方法。

### REQUEST_INSPECT：放行或加严

这是唯一一个能改变判决结果的点位。**否决不能靠返回值** —— 内核的 `hook()` 派发器遇到 bool 返回会短路整条链，后面的订阅方直接不执行了。正确做法是改 `Decision` 对象，然后返回 `null`：

```php
use App\Plugin\WebsiteMonitor\Api\Decision;

#[Hook(point: 0x7C100)]
public function inspect($decision): void
{
    // 别用 instanceof Decision —— 插件没装时类不存在
    if (!is_object($decision) || !method_exists($decision, 'allow')) {
        return;
    }

    // 我的支付回调白名单，别拦
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', $this->gatewayIps(), true)) {
        $decision->hardAllow('MyPay', '支付网关回源 IP');
        return;
    }

    // 这个 IP 在我这儿有前科，加严
    if ($this->isKnownAbuser()) {
        $decision->escalate(Decision::BAN, '历史刷单记录');
    }
}
```

三个方法的区别：

- `allow($by, $why)` —— 软放行，链上后来的订阅方仍然可以再加严。
- `hardAllow($by, $why)` —— 强制放行并**锁定判决**，后面谁都改不了。
- `escalate($action, $why)` —— 提升到更严的动作。`$action` 取 `Decision::LOG` / `SCORE` / `BLOCK` / `BAN` / `THROTTLE`。

真返回了 bool 也不会出事，本插件会兼容处理：`true` 当 `hardAllow`，`false` 当 `escalate(BLOCK)`。但这会短路后面的订阅方，不推荐。

### 其余六个点位是只读广播

返回值一律忽略，别在里面做重活 —— `ATTACK_BLOCKED` 在被攻击时可能每秒触发几百次。要落库、要发通知，写队列，别当场做。

```php
#[Hook(point: 0x7C102)]
public function onBanned(array $e): void
{
    // $e = ['ip' => ..., 'seconds' => ..., 'reason' => ..., 'source' => ..., 'level' => ..., 'geo' => ...]
    $this->queue->push('my_plugin_ban_log', $e);   // ✅ 写队列
    // $this->sendEmail($e);                        // ❌ 别在这儿发信
}
```

## 与通知中心的联动

装了「通知中心」插件就自动接上，不需要任何配置。

**本插件 → 通知中心**：安全事件通过 `NotificationCenter\Api\Notify::alert()` 投递，复用它现成的 `security_alert` 模板，邮件和 Telegram 一起发，发送记录在它的面板查。没装的话所有告警只写进本插件日志，其它功能完全不受影响。

**通知中心 → 本插件**：它的安全事件页每行都有「封锁 IP」按钮，POST 到 `/plugin/WebsiteMonitor/admin/banIp`，最终落到 `Guard::banIp()`。这个接口带完整的管理员会话校验和自锁保护 —— 封自己会被拒绝并给出提示。

想在自己的插件里发告警，直接用通知中心的门面，不要经过本插件：

```php
\App\Plugin\NotificationCenter\Api\Notify::alert([
    'title' => '检测到异常下单',
    'body' => '同一 IP 十分钟内下了 40 单。',
    'level' => 'warn',
    'evidence' => [['ip' => $ip, 'count' => 40]],
    'actions' => ['到订单列表核对', '必要时封禁该 IP'],
    'dedupe' => 'myplugin:abuse:' . $ip . ':' . date('YmdH'),
]);
```

## 完整示例：一个反刷单插件

```php
<?php
namespace App\Plugin\MyGuard;

use Kernel\Annotation\Hook;

class Watcher
{
    private const WM = '\App\Plugin\WebsiteMonitor\Api\Guard';

    #[Hook(point: 0x7C102)]                       // WebsiteMonitor 封了个 IP
    public function onBanned(array $e): void
    {
        // 把这个 IP 最近的订单标记为待核
        $this->flagOrders((string)($e['ip'] ?? ''));
    }

    public function onSuspiciousOrder(string $ip, int $count): void
    {
        if (!class_exists(self::WM) || !(self::WM)::isAvailable()) {
            return;                                // 没装监控插件，降级为只记日志
        }

        $geo = (self::WM)::lookup($ip);
        if (($geo['country'] ?? '') !== 'CN') {    // 只做国内生意，境外刷单直接封
            (self::WM)::banIp($ip, 86400, "境外刷单：{$geo['text']}，10 分钟 {$count} 单", 'MyGuard');
        }
    }
}
```

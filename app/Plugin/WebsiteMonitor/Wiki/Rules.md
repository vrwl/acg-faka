# 防护规则

## 判决流程

每个请求按成本从低到高依次过闸，任何一步命中即结束，正常请求通常在第 7 步就走完了：

| # | 检查 | 成本 |
|---|---|---|
| 0 | 紧急停用文件是否存在 | 一次 `is_file` |
| 1 | 紧急开关 / 插件是否启用 | 读内存数组 |
| 2 | 载入编译产物（规则、名单、蜘蛛签名） | opcache，0 次磁盘 I/O |
| 3 | 取客户端 IP | 已被内核预热，≈0 |
| 4 | **白名单 / 永久放行命中 → 直接放行** | 哈希查表 |
| 5 | 封禁文件命中 → 出封禁页 | 一次 `is_file` |
| 6 | 人工黑名单 / 地区规则（仅开启地区规则时才查 IP 库） | 哈希 + 前缀探测 |
| 7 | **廉价串预筛**：整个请求里有没有 `' " < > ( ) \ ; \| \` $ * %` | 一次 `strpbrk`，正常请求到此为止 |
| 8 | 逐条正则匹配（只有第 7 步命中才跑） | 约 7 µs |
| 9 | CC 滑动窗口计数 | 一次文件读写或 Redis `incr` |
| 10 | 广播 `REQUEST_INSPECT`，其它插件可放行或加严 | 无订阅者时为 0 |

> 路径面（PATH）不参与第 7 步预筛 —— `/../../etc/passwd` 里一个特殊字符都没有，预筛会把它放过去。HEADER 面和 FILES 面同理。

## 五个动作梯度

| 动作 | 行为 |
|---|---|
| `log` | 只记录，什么都不做 |
| `score` | 累加风险分，达到阈值（默认 10）才拦截。用于容易误报的规则 |
| `block` | 立即 403 出拦截页 |
| `ban` | 拦截并按梯度封禁该 IP |
| `throttle` | 429 + `Retry-After`，不封禁 |

**观察模式下所有动作统一降级为 `log`**，只在面板显示「本可拦截」。

## 六个检测面

| 面 | 内容 | 默认 |
|---|---|---|
| `path` | URL 路径 | 开 |
| `query` | 查询串（原始值，未经净化） | 开 |
| `body` | POST / JSON 请求体，上限 64 KB | 开 |
| `cookie` | 全部 Cookie 值 | 开 |
| `header` | User-Agent、Referer、Host 等 | 开 |
| `files` | 上传文件名（不读文件内容） | 开 |

> **为什么要读原始值**：内核在 `KERNEL_INIT` 之前就用 HTMLPurifier + `htmlspecialchars(strip_tags())` 改写了 `$_GET/$_POST/$_REQUEST/$_SERVER`。拿这些做 WAF 匹配等于自欺欺人 —— 载荷已经被洗掉，合法字符也被改了形。本插件全程只从 `unsafeGet()/unsafePost()/unsafeJson()/raw()/header()/$_COOKIE` 取原文。

## 误报抑制

内置了几条降噪措施，都能在设置里调：

- **管理员会话整体跳过 BODY 面扫描。** 后台天天要提交含 `<script>`、`eval(`、SQL 关键字的模板和公告，这是误报的头号来源。
- **富文本字段排除清单**：`content` / `notice` / `description` / `leave_message` 等默认不扫。
- **XSS 的 BODY 面默认关**：内核入库前已经 purify 过，重复扫只会误伤。
- **`fp` 标注**：每条规则都标了误报倾向（`low` / `medium` / `high`）。面板里标「易误报」的那几条，观察期要重点看。
- **路径豁免**：某个接口就是要收奇怪的载荷（比如自己写的导入功能），把路径加进豁免清单。

## 内置规则清单（36 条）

动作列里的 `score` 表示累计计分、达阈值才拦。`fp` 是误报倾向。

### SQL 注入与命令执行（14 条）

| ID | 名称 | 检测面 | 动作 | 级别 | 分 | fp |
|---|---|---|---|---|---|---|
| `sqli.union` | UNION 查询 | query/body/path/cookie | block | 严重 | 10 | 低 |
| `sqli.sleep` | 时间盲注 | query/body/cookie | block | 严重 | 10 | 低 |
| `sqli.schema` | 探测库结构 | query/body/cookie | block | 严重 | 10 | 低 |
| `sqli.error` | 报错注入 | query/body/cookie | block | 严重 | 10 | 低 |
| `sqli.file` | 读写文件 | query/body/cookie | block | 严重 | 10 | 低 |
| `sqli.stack` | 堆叠语句 | query/body/cookie | block | 严重 | 10 | 低 |
| `sqli.tautology` | 恒真条件 | query/cookie | score | 警告 | 6 | 中 |
| `sqli.comment` | 注释截断 | query/cookie | score | 警告 | 4 | 中 |
| `rce.exec` | 命令执行函数 | query/body/cookie | block | 严重 | 10 | 低 |
| `rce.eval` | 动态代码执行 | query/body/cookie | block | 严重 | 10 | 低 |
| `rce.webshell` | WebShell 特征 | query/body | block | 严重 | 10 | 低 |
| `rce.jndi` | JNDI 注入（Log4Shell） | query/body/cookie/header | **ban** | 严重 | 12 | 低 |
| `rce.template` | 模板注入 | query/cookie | block | 严重 | 10 | 中 |
| `rce.serialize` | 反序列化载荷 | query/body/cookie | score | 警告 | 6 | 中 |

### 跨站脚本（5 条）

| ID | 名称 | 检测面 | 动作 | 级别 | 分 | fp |
|---|---|---|---|---|---|---|
| `xss.script` | script 标签 | query/cookie | block | 警告 | 8 | 低 |
| `xss.event` | 事件属性 | query/cookie | block | 警告 | 8 | 低 |
| `xss.jsuri` | javascript 伪协议 | query/cookie | score | 警告 | 6 | 中 |
| `xss.svg` | 可执行标签 | query/cookie | score | 警告 | 6 | 中 |
| `xss.dom` | 窃取会话 | query/cookie | score | 警告 | 6 | 中 |

### 路径穿越与文件包含（6 条）

| ID | 名称 | 检测面 | 动作 | 级别 | 分 | fp |
|---|---|---|---|---|---|---|
| `lfi.dotdot` | 相对路径穿越 | path/query/body/cookie | block | 严重 | 10 | 低 |
| `lfi.passwd` | 读系统文件 | path/query/body | **ban** | 严重 | 12 | 低 |
| `lfi.wrapper` | PHP 伪协议 | query/body | block | 严重 | 10 | 低 |
| `lfi.remote` | 远程文件包含 | query | score | 警告 | 6 | 中 |
| `proto.nullbyte` | 空字节截断 | path/query/body | block | 严重 | 10 | 低 |
| `proto.crlf` | CRLF 注入 | query/cookie | block | 严重 | 10 | 低 |

### 扫描器探测（3 条）

| ID | 名称 | 检测面 | 动作 | 级别 | 分 | fp |
|---|---|---|---|---|---|---|
| `scan.sensitive` | 敏感文件探测（`.env`、`.git`、备份包…） | path | score | 警告 | 8 | 低 |
| `scan.probe_burst` | 连续探测 | path | **ban** | 严重 | 12 | 低 |
| `scan.notfound_burst` | 404 洪水 | path | **ban** | 警告 | 8 | 低 |

### UA、协议与上传（8 条）

| ID | 名称 | 检测面 | 动作 | 级别 | 分 | fp |
|---|---|---|---|---|---|---|
| `ua.tool` | 恶意工具 UA（sqlmap、nikto…） | header | **ban** | 严重 | 12 | 低 |
| `ua.empty` | 空 User-Agent | header | score | 提示 | 3 | **高** |
| `ua.fake_spider` | 伪装搜索引擎蜘蛛 | header | block | 警告 | 8 | 低 |
| `method.disallowed` | 不允许的 HTTP 方法 | — | block | 警告 | 6 | 低 |
| `host.mismatch` | Host 头与站点域名不符 | — | block | 警告 | 6 | **高** |
| `upload.ext` | 上传可执行文件 | files | block | 严重 | 10 | 低 |
| `upload.double_ext` | 上传双后缀绕过 | files | block | 严重 | 10 | 低 |
| `ref.spam` | 垃圾来源刷量 | header | score | 提示 | 3 | **高** |

> `ua.empty`、`host.mismatch`、`ref.spam` 三条误报倾向高，默认都是 `score` 或需要额外开关。`host.mismatch` 要先在设置里填好允许的域名清单再开，否则用 IP 直连、内网回源、健康检查都会被拦。

## 自己加规则

规则页可以对每条内置规则单独设「开 / 关 / 改动作」，也可以加自定义规则。自定义规则的正则要注意两点：

- **不加 `u` 修饰符。** 非法 UTF-8 会让 `preg_match` 返回 `false`，那是静默放行，等于规则失效。插件内部统一不加，并且每次都检查 `preg_last_error()`。
- **`#` 要写成 `\x23`。** 插件用 `#` 作为正则分隔符，规则里出现裸 `#` 会直接破坏表达式。写 JSON 的时候用 `\x23`，插件也会做一次防御性转义。

## 登录爆破防护

独立于 WAF 规则，走登录事件而非请求内容：

| 场景 | 默认阈值 | 动作 |
|---|---|---|
| 会员登录失败 | 10 分钟内 8 次 | 封禁 30 分钟 |
| 后台登录失败 | 10 分钟内 3 次 | 封禁 1 小时 + 发告警 |

后台被爆破会单独发一封告警，正文里带尝试过的账号名，方便判断是撞库还是针对性攻击。

## 拦截页

拦截页是自包含的静态 HTML，**零数据库、零模板引擎** —— 走正常渲染的话，被打的时候每个被拦请求都要查一次库，等于帮攻击者放大。页面上有：

- **请求编号**（形如 `WM-TK4AEE-7EBE-19CE`）：在面板「安全」页按这个号能搜到完整取证记录。
- **人话原因**：只说「你的请求被本站安全策略拦截」，不暴露命中了哪条规则。
- **申诉方式**：在设置里填，会显示在拦截页底部。
- **紧急自救路径**：只在管理员会话下显示。

API 路由、XHR 请求、`Accept: application/json` 的请求会拿到 JSON 变体而不是 HTML。

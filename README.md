<p align="center">
  <a href="https://faka.wiki/">
    <img src="https://raw.githubusercontent.com/lizhipay/acg-faka/refs/heads/main/favicon.ico" width="120" height="120" style="border-radius: 20px;" alt="异次元店铺系统">
  </a>
</p>

<br>
<p align="center">
<span>
<img src="https://faka.wiki/svg/php.svg" alt="php8.0,8.1">
</span>
<span>
<img src="https://faka.wiki/svg/mysql-version.svg" alt="mysql5.6+">
</span>
<span><img src="https://faka.wiki/svg/license.svg" alt="license"></span>
</p>

## 法律声明
> 本商城程序基于 MIT 协议开源，并且完全免费。该程序的初衷是为开发者提供学习和研究的机会。未取得合法资质，严禁将本程序用于任何商业用途，尤其是禁止利用本程序搭建平台进行商品销售。
>
> 用户在使用或学习本程序时，必须严格遵守法律法规。我们提倡依法行事，尊重法律，坚守法律，避免对社会产生不良影响。
>
> 使用本程序即表示您已充分理解并同意本法律声明的所有内容。

## 广告

🚀 ARM AI — GPT / Claude / Gemini / Grok Token 服务平台，超低倍率、余额长期有效并支持无理由退款。立即体验：https://ai.arm.moe/


## 快速体验
- 后台演示：[https://demo.faka.wiki/admin](https://demo.faka.wiki/admin)  账号：demo@demo.com 密码：123456
- 前台演示：[https://demo.faka.wiki](https://demo.faka.wiki) 账号：为了明天美好而战斗 密码：123456
- 文档地址：[https://faka.wiki](https://faka.wiki)

## 功能简介

- 支付系统，拥有强悍的插件扩展能力，现目今已经支持全网任意平台，任意支付渠道。
- 纯本地运行，不含在线更新、控制台公告与应用商店，运行期不依赖任何官方服务器。
- 商品销售，支持商品配图、会员价、游客价、邮件通知、卡密预选（用户可以预选自己想购买的那个账号或者卡号）、API对接、强制登录购买、强悍的自定义控件功能、限时秒杀、批发优惠、优惠卷、等众多功能。
- 分站系统，前台用户可以开通分站，分站可以独立运行，也可以卖主站商品，有点类似商业店铺了。
- 会员系统，会员/商户融为一体，支持会员等级，以及商户等级完全自定义，以及商品可自定义会员等级对应价格。
- 推广/代理系统，拥有三级分销返佣功能，注册账号即实现自动发展下级。
- 共享店铺系统，可以在后台直接对接别人的店铺，通过扣除余额来进行无感知进货。
- 本地扩展，插件、支付插件与模板都能离线打包，从后台上传安装，让你的店铺变得格外强大。
- 界面美观，完美支持PC和手机，真正的内外二次元文化。
- 强悍的扩展能力，你可以通过本程序在几分钟之内快速的实现你任意想实现的在线购物功能，例子如下：
  - 游戏方面，物品购买即时到玩家背包
  - 商业软件余额充值
  - 商业软件自动授权
  - 论坛/社区VIP自动开通
  - 只要你想得到，没有做不到。
- 还有更多强大的功能，需要安装自己发掘。至此，介绍完毕。

## 安装教程

- 在安装之前，请检查你的系统环境，`php>=8.0`，`MySQL版本>=5.6[不推荐5.6后续升级可能会有问题，推荐5.7或者8.0]`，因为使用了大量的PHP8注解以及PHP8的新特性，所以php版本不得不从8.0起，这里还需要注意。
- 将源码下载至你的服务器、或者使用composer下载源码：`composer create-project lizhipay/acg-faka`
- 以上步骤完成后，然后配置伪静态，Apache无需配置，根目录已经有.htaccess文件了，但如果你是Nginx，则需要配置伪静态。
- 下面是Nginx伪静态规则：
```
location ~* ^/(runtime|kernel|config|vendor)/                { return 404; }
location ~  /\.(?!well-known)                                { return 404; }
location ~* \.(log|sql|sqlite|db|db-wal|db-shm|bak|old|save|orig|swp|swo|tmp|ini|lock)$  { return 404; }
location ~* (~|composer\.(json|lock)|package(-lock)?\.json)$ { return 404; }
location / {
    try_files $uri $uri/ /index.php?s=$uri&$args;
}
```
- Windows IIS服务器环境，可以使用下面伪静态规则：
```
<rules>
	<rule name="acg_rewrite" stopProcessing="true">
		<match url="^(.*)$"/>
		<conditions logicalGrouping="MatchAll">
			<add input="{HTTP_HOST}" pattern="^(.*)$"/>
			<add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true"/>
			<add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true"/>
		</conditions>
		<action type="Rewrite" url="index.php?s={R:1}"/>
	</rule>
</rules>
```
- 配置完成后，访问你的首页，即可开始安装
- 安装完成后，后台地址是：`https://你的域名/admin`

## 纯本地版说明

本分支是**纯本地版**，与官方版相比移除了以下内容：

- 主程序在线更新：后台的版本检查、更新提示、一键升级，以及背后的相关接口与页面全部去掉。
- 控制台（后台首页）的官方公告卡片。
- 应用商店：插件与模板的在线浏览、下载、购买与授权校验全部取消，插件更新检查一并移除。
- 一键更新全部插件：插件更新改为在后台上传压缩包完成。

插件运行时同时换成了本地明文实现（`kernel/Plugin/Local.php`），不再向官方服务器校验授权，插件只要放进 `app/Plugin/{插件标识}` 目录即可在后台启用。程序自身不会再访问任何官方服务器，对外请求只发往你自己配置的支付、短信、邮件等渠道，也无需配置任何应用商店参数。

## 本地安装扩展

通用插件、支付插件、网站模板都可以离线打包，再从后台上传安装、更新和卸载，全程不需要联网。

### 打包要求

- 必须是 `.zip` 文件，体积不超过 **32MB**（同时受 PHP 的 `upload_max_filesize` 与 `post_max_size` 限制）。
- 压缩包内要是**单层目录**结构，目录名就是扩展标识（字母开头，可含字母、数字、`-`、`_`，不超过 64 个字符）：

```text
Demo/                     ← 扩展标识：Demo
├── Config/
│   └── Info.php          ← 通用插件 / 支付插件的标识文件
├── Lang/
│   └── zh-cn.json        ← 可选，扩展自带的词包
├── install.sql           ← 可选，首次安装时执行
└── update.sql            ← 可选，覆盖更新时执行
```

- 三种扩展的标识文件与安装目录：

| 类型 | 标识文件 | 安装目录 |
| --- | --- | --- |
| 通用插件 | `Config/Info.php` | `app/Plugin/{标识}` |
| 支付插件 | `Config/Info.php` | `app/Pay/{标识}` |
| 网站模板 | `Config.php` | `app/View/User/Theme/{标识}` |

> 后台界面上传时必须保留单层目录（包内只有一个顶层目录），否则无法推断扩展标识；如果用脚本直接调接口，也可以把文件放在压缩包根目录并额外传 `plugin_key` 指定标识。

### 后台操作入口

| 扩展 | 入口 | 按钮 |
| --- | --- | --- |
| 通用插件 | 左侧菜单「通用插件」 | 本地上传插件 |
| 支付插件 | 左侧菜单「支付管理 → 支付插件」 | 本地上传插件 |
| 网站模板 | 左侧菜单「网站设置 → 模板与网络」 | 本地上传模板 |

上传后由后端判断是首次安装还是更新：目标目录里已存在标识文件就按更新处理，否则按安装处理。

### 扩展图标

后台扩展列表里的图标由扩展包自己提供，取不到就退回默认图标或首字母色块，不会出现裂图。

| 类型 | 图标来源 |
| --- | --- |
| 通用插件 | `Config/Info.php` 里的 `'icon' => '/app/Plugin/Demo/icon.png'` |
| 支付插件 | `Config/Info.php` 里的 `'icon' => '/app/Pay/Demo/icon.png'` |
| 网站模板 | `Config.php` 的 `INFO` 里加 `'ICON' => '...'`，或直接往模板目录放 `icon.png` / `icon.svg` |

图标地址要么是以 `/` 开头的站内路径，要么是完整的 `http(s)://` 地址，长度不超过 255 个字符，不能带引号、尖括号。模板可以省掉整个路径——把 `icon.png` 或 `icon.svg` 放在 `app/View/User/Theme/{标识}/` 下即可，后端会自己找到它。

### 安装与更新的行为

- 合并式覆盖：只替换压缩包里的同名文件，包外文件（例如你自己在插件目录里补的配置）会保留下来。
- 首次安装执行包内的 `install.sql`，覆盖更新执行 `update.sql`（文件存在才执行）。
- 通用插件在更新前会自动停用，装完再自动启动；中途失败会尽力恢复到停用前的状态，并把原始错误抛给你。
- 通用插件安装成功后触发 `Install` 钩子，更新成功后触发 `Upgrade` 钩子。
- 模板更新会清空 `runtime/view` 编译缓存，避免旧产物继续生效。
- 扩展自带的词包 `Lang/{语言}.json` 会在安装后立即入库。
- 卸载会删除整个扩展目录并清掉该扩展带来的词条；通用插件卸载前会先停止。

### 相关接口

| 接口 | 方法 | 参数 |
| --- | --- | --- |
| `/admin/api/plugin/install` | POST | `file`（zip 文件）、`type`（0 通用插件 / 1 支付插件 / 2 网站模板）、`plugin_key`（可选，包内非单层目录时用来指定标识） |
| `/admin/api/plugin/uninstall` | POST | `plugin_key`、`type` |

## 更多支持
- 交流QQ群：970103572
- [Telegram](http://t.me/mcyofficial)

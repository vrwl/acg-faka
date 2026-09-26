<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use App\Plugin\Blog\Model\Category;
use App\Plugin\Blog\Model\Post;

/**
 * 种子数据：仅当文章表为空时写入（重装/升级不会重复插入）。
 * 内容故意用教程体，把 Markdown 全要素演示一遍（代码块/提示框/表格/任务列表）。
 * content_html 留空、render_version=0 —— 渲染管线就绪后首次读取会懒渲染补全。
 */
final class Seed
{
    public static function ensure(): void
    {
        if (Post::query()->count() > 0) {
            return;
        }

        $now = Db::now();

        $category = Category::query()->where('slug', 'default')->first();
        if (!$category) {
            $category = new Category();
            $category->name = '默认分类';
            $category->slug = 'default';
            $category->parent_id = 0;
            $category->description = '第一个分类，可在后台重命名或删除';
            $category->cover = '';
            $category->weight = 0;
            $category->post_count = 1;
            $category->create_time = $now;
            $category->save();
        }

        $hello = new Post();
        $hello->type = Post::TYPE_POST;
        $hello->title = '你好，次元博客 —— 写作能力速览';
        $hello->slug = 'hello-world';
        $hello->category_id = (int)$category->id;
        $hello->summary = '这是一篇示例教程：展示次元博客的 Markdown 写作能力——代码块、提示框、表格、任务列表与目录。熟悉之后可以直接删除它。';
        $hello->content_md = self::helloContent();
        $hello->content_html = '';
        $hello->toc = null;
        $hello->render_version = 0;
        $hello->status = Post::STATUS_PUBLISHED;
        $hello->top = 0;
        $hello->allow_comment = 1;
        $hello->author_id = 0;
        $hello->author_name = '博主';
        $hello->publish_time = $now;
        $hello->create_time = $now;
        $hello->update_time = $now;
        $hello->save();

        $about = new Post();
        $about->type = Post::TYPE_PAGE;
        $about->title = '关于';
        $about->slug = 'about';
        $about->category_id = 0;
        $about->summary = '';
        $about->content_md = "# 关于本站\n\n在这里介绍你自己和这个博客。\n\n> 本页是草稿，编辑后发布即可出现在前台。\n";
        $about->content_html = '';
        $about->render_version = 0;
        $about->status = Post::STATUS_DRAFT;
        $about->allow_comment = 0;
        $about->author_id = 0;
        $about->author_name = '博主';
        $about->publish_time = null;
        $about->create_time = $now;
        $about->update_time = $now;
        $about->save();
    }

    private static function helloContent(): string
    {
        return <<<'MD'
欢迎使用**次元博客**。这篇示例教程把常用写作能力过一遍，读完就可以动手写第一篇教程了。

## 代码块

教程的灵魂是代码块。指定语言即可获得语法高亮、语言标签与一键复制：

```bash
# 更新系统并安装 nginx
sudo apt update && sudo apt install -y nginx
sudo systemctl enable --now nginx
```

```php
<?php
// 连接数据库的最小示例
$pdo = new PDO('mysql:host=127.0.0.1;dbname=demo;charset=utf8mb4', 'root', 'secret');
$stmt = $pdo->prepare('SELECT * FROM user WHERE id = ?');
$stmt->execute([1]);
```

行内代码也没问题：把 `APP_DEBUG=false` 写进 `.env` 即可。

## 提示框

四种语义的提示框，教程步骤里穿插使用：

:::tip
小技巧：`Ctrl + S` 在写作页里随时保存草稿。
:::

:::info
本文所有命令均在 Ubuntu 22.04 上验证通过。
:::

:::warning
修改配置前请先备份，出错时可以快速回滚。
:::

:::danger
`rm -rf` 类命令请再三确认路径，误删无法恢复！
:::

## 表格与任务清单

| 方案 | 上手难度 | 适用场景 |
| ---- | -------- | -------- |
| Docker | ★★☆ | 快速起服务 |
| 源码编译 | ★★★ | 定制化需求 |

部署前检查：

- [x] 域名已解析
- [x] SSL 证书已签发
- [ ] 定时备份已配置

## 引用与链接

> 好教程的标准：读者照着做，一次成功。

更多写法参考 [Markdown 官方教程](https://commonmark.org/help/)。

---

标题会自动生成右侧目录（手机端是浮动目录按钮），长文阅读有进度条。现在，开始写你的第一篇教程吧！
MD;
    }
}

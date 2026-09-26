# 次元博客

独立完整的博客系统插件，主打**教程写作**：Markdown 全功能编辑、层级分类、标签、嵌套评论、RSS，
前台多套主题（液态玻璃/素笺/刊集/霓虹）访客自由切换，手机端原生 APP 操作逻辑。

## 快速上手

1. 启用插件（自动建表 + 写入示例文章）。
2. 后台左侧「次元博客 → 文章 → 写文章」开始创作。
3. 前台入口：`/blog`（优雅短链，可在设置中关闭）或 `/plugin/Blog/index/index`（原生路径，永远可用）。

## 数据表

`blog_post` / `blog_category` / `blog_tag` / `blog_post_tag` / `blog_comment` / `blog_like`（前缀自动）。

停用插件不会删除任何数据。系统卸载流程不会执行清理，如需彻底移除请手动执行：

```sql
DROP TABLE IF EXISTS `acg_blog_post`, `acg_blog_category`, `acg_blog_tag`,
  `acg_blog_post_tag`, `acg_blog_comment`, `acg_blog_like`;
DELETE FROM `acg_lang` WHERE `scene` = 'ext:Blog';
```

（表前缀请按实际 `config/database.php` 调整。）

## 对外广播 Hook

见 [Hooks.md](Hooks.md)。

## 注意事项

- 新增/修改 `Hook/*.php` 里的 `#[Hook]` 注解后，必须在后台把插件**停用→启用**一次（hook 注册表是加密缓存）。
- 文章正文与评论以 base64 提交（规避 WAF 对教程中 SQL/Shell 片段的误杀），服务端渲染并经 HTMLPurifier 白名单消毒后输出。

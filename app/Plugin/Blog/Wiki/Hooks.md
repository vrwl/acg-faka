# 对外广播点位（0x7D1xx）

订阅方请写**十六进制字面量**（Blog 未安装时引用常量会让你的插件无法启用）。
所有点位均在数据库事务提交后触发。

| 点位 | 名称 | 传参 | 触发时机 |
| ---- | ---- | ---- | -------- |
| `0x7D100` | POST_PUBLISHED | `Model\Post $post, bool $isFirstPublish` | 文章 status 流转为「发布」的保存动作（含定时文章，订阅者自行读 `publish_time`） |
| `0x7D101` | POST_UPDATED | `Model\Post $post` | 已发布文章内容更新 |
| `0x7D110` | COMMENT_CREATED | `Model\Comment $comment, Model\Post $post` | 评论创建（任何初始状态） |
| `0x7D111` | COMMENT_APPROVED | `Model\Comment $comment, Model\Post $post` | 评论 待审/垃圾 → 通过（创建即通过不重复触发） |

示例（在你的插件中订阅评论创建）：

```php
#[Hook(point: 0x7D110)]
public function onBlogComment(\App\Plugin\Blog\Model\Comment $comment, \App\Plugin\Blog\Model\Post $post): void
{
    // 例如转发到通知中心 / Telegram
}
```

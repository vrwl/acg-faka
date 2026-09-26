<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Consts;

/**
 * 次元博客对外广播点位（0x7D1xx 段）。
 *
 * 已知段位分配：核心 ≤ 0x18191，ThreadManager 0x7A1xx，NotificationCenter 0x7B1xx，
 * WebsiteMonitor 0x7C1xx，Blog 0x7D1xx。
 *
 * 订阅方请写十六进制字面量（如 #[Hook(point: 0x7D100)]），不要引用本常量——
 * Blog 未安装时属性求值抛 Error 会让订阅插件卡在半启用状态。
 * 所有点位均在数据库事务提交后触发。
 */
interface Hook
{
    /**
     * 文章发布：status 流转为「发布」的那次保存动作触发（含定时文章——订阅者自行读 publish_time 判断是否未来）。
     * 传参：\App\Plugin\Blog\Model\Post $post, bool $isFirstPublish
     */
    const POST_PUBLISHED = 0x7D100;

    /**
     * 已发布文章内容更新。
     * 传参：\App\Plugin\Blog\Model\Post $post
     */
    const POST_UPDATED = 0x7D101;

    /**
     * 评论创建（任何初始状态）。
     * 传参：\App\Plugin\Blog\Model\Comment $comment, \App\Plugin\Blog\Model\Post $post
     */
    const COMMENT_CREATED = 0x7D110;

    /**
     * 评论审核通过（仅 待审/垃圾 → 通过 的流转；创建即通过不重复触发）。
     * 传参：\App\Plugin\Blog\Model\Comment $comment, \App\Plugin\Blog\Model\Post $post
     */
    const COMMENT_APPROVED = 0x7D111;
}

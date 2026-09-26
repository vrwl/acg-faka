<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Core;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * 幂等建表：INSTALL / START / UPGRADE 都会调用 ensure()。
 * 永远不发 update.sql —— 改表就在这里补列/补索引（hasTable/hasColumn 守护），并把 VERSION +1 作标记。
 */
final class Schema
{
    public const VERSION = 2;

    public static function ensure(): void
    {
        $schema = Manager::schema();

        if (!$schema->hasTable(Db::POST)) {
            $schema->create(Db::POST, function (Blueprint $t) {
                $t->increments('id');
                $t->string('type', 8)->default('post')->comment('post=文章 page=独立页面');
                $t->string('title', 200)->default('');
                $t->string('slug', 191)->comment('URL 别名，全小写，唯一');
                $t->unsignedInteger('category_id')->default(0)->comment('分类 id，page 恒为 0，0=未分类');
                $t->string('cover', 255)->default('')->comment('封面图 URL');
                $t->string('summary', 500)->default('')->comment('手工摘要，空=前台自动截取');
                $t->mediumText('content_md')->nullable()->comment('Markdown 源');
                $t->mediumText('content_html')->nullable()->comment('渲染缓存(已消毒 HTML)');
                $t->text('toc')->nullable()->comment('目录 JSON [{level,text,anchor}]');
                $t->unsignedSmallInteger('render_version')->default(0)->comment('渲染器版本，小于当前版本时懒重渲染');
                $t->unsignedTinyInteger('status')->default(0)->comment('0=草稿 1=发布 2=隐藏');
                $t->unsignedTinyInteger('top')->default(0)->comment('置顶');
                $t->unsignedTinyInteger('allow_comment')->default(1)->comment('允许评论');
                $t->unsignedInteger('weight')->default(0)->comment('page 排序，小在前');
                $t->string('template', 32)->default('')->comment('page 自定义模板名(预留)');
                $t->unsignedInteger('views')->default(0)->comment('浏览量');
                $t->unsignedInteger('likes')->default(0)->comment('点赞数');
                $t->unsignedInteger('comments_count')->default(0)->comment('已通过评论数');
                $t->string('seo_title', 200)->default('');
                $t->string('seo_keywords', 255)->default('');
                $t->string('seo_description', 500)->default('');
                $t->unsignedInteger('author_id')->default(0)->comment('manage.id');
                $t->string('author_name', 32)->default('')->comment('作者名快照');
                $t->dateTime('publish_time')->nullable()->comment('对外发布时间，可为未来=定时发布');
                $t->dateTime('create_time');
                $t->dateTime('update_time')->nullable();
                $t->unique('slug', 'uk_slug');
                $t->index(['type', 'status', 'top', 'publish_time'], 'idx_list');
                $t->index(['category_id', 'status'], 'idx_cat');
            });
        }

        if (!$schema->hasTable(Db::CATEGORY)) {
            $schema->create(Db::CATEGORY, function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 64);
                $t->string('slug', 191)->comment('URL 别名，全小写，唯一');
                $t->unsignedInteger('parent_id')->default(0)->comment('父分类，0=顶级；后台限 3 级');
                $t->string('description', 255)->default('');
                $t->string('cover', 255)->default('');
                $t->unsignedInteger('weight')->default(0)->comment('同级排序，小在前');
                $t->unsignedInteger('post_count')->default(0)->comment('去范式：本分类已发布文章数(不含子类)');
                $t->dateTime('create_time');
                $t->unique('slug', 'uk_slug');
                $t->index('parent_id', 'idx_parent');
            });
        }

        if (!$schema->hasTable(Db::TAG)) {
            $schema->create(Db::TAG, function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 64);
                $t->string('slug', 191)->comment('URL 别名，全小写，唯一');
                $t->unsignedInteger('post_count')->default(0)->comment('去范式：关联已发布文章数');
                $t->dateTime('create_time');
                $t->unique('name', 'uk_name');
                $t->unique('slug', 'uk_slug');
            });
        }

        if (!$schema->hasTable(Db::POST_TAG)) {
            $schema->create(Db::POST_TAG, function (Blueprint $t) {
                $t->unsignedInteger('post_id');
                $t->unsignedInteger('tag_id');
                $t->primary(['post_id', 'tag_id']);
                $t->index('tag_id', 'idx_tag');
            });
        }

        if (!$schema->hasTable(Db::COMMENT)) {
            $schema->create(Db::COMMENT, function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedInteger('post_id');
                $t->unsignedBigInteger('root_id')->default(0)->comment('顶楼评论 id，0=自身是顶楼');
                $t->unsignedBigInteger('parent_id')->default(0)->comment('直接回复目标评论 id');
                $t->unsignedInteger('user_id')->default(0)->comment('user.id，0=管理员快捷回复');
                $t->string('user_name', 32)->default('')->comment('用户名快照');
                $t->unsignedTinyInteger('is_admin')->default(0)->comment('管理员回复=1');
                $t->string('reply_user_name', 32)->default('')->comment('被回复者名快照');
                $t->text('content')->comment('纯文本，输出时转义+nl2br');
                $t->unsignedTinyInteger('status')->default(0)->comment('0=待审 1=通过 2=垃圾');
                $t->string('create_ip', 64)->default('');
                $t->string('ua', 255)->default('');
                $t->dateTime('create_time');
                $t->index(['post_id', 'status', 'root_id'], 'idx_post');
                $t->index(['status', 'create_time'], 'idx_audit');
                $t->index('user_id', 'idx_user');
            });
        }

        if (!$schema->hasTable(Db::LIKE)) {
            $schema->create(Db::LIKE, function (Blueprint $t) {
                $t->unsignedInteger('post_id');
                $t->unsignedInteger('user_id');
                $t->dateTime('create_time');
                $t->primary(['post_id', 'user_id']);
                $t->index('user_id', 'idx_user');
            });
        }

        //---- v2：文章可绑定商品（文章页展示商品卡 / 商品页展示相关文档）----
        if (!$schema->hasColumn(Db::POST, 'commodity_id')) {
            $schema->table(Db::POST, function (Blueprint $t) {
                $t->unsignedInteger('commodity_id')->default(0)->comment('绑定的商品 id，0=未绑定')->after('category_id');
                $t->index(['commodity_id', 'status'], 'idx_commodity');
            });
        }
    }

    public static function ready(): bool
    {
        try {
            return Manager::schema()->hasTable(Db::POST);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

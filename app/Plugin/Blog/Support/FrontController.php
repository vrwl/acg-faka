<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

use App\Controller\Base\View\UserPlugin;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Model\Post;
use App\Util\Client;

/**
 * 博客前台页面基座：
 *  - guest_visible 访问门禁（未登录 → 登录页带 goto 回跳）；
 *  - 原生路径 → 短链的 canonical 301 归一（仅 GET、pretty_links 开、非桥接进入时）；
 *  - 统一注入 $blog（站点信息/链接表/外观默认值）与 $page（SEO 包/导航态）。
 */
abstract class FrontController extends UserPlugin
{
    private static ?array $aboutCache = null;
    private static bool $aboutLoaded = false;

    protected function blogRender(string $template, array $page, array $data = []): string
    {
        if (!Settings::bool('guest_visible') && !$this->getUser()) {
            $goto = (string)($_SERVER['REQUEST_URI'] ?? Url::index());
            Client::redirect('/user/authentication/login?goto=' . urlencode($goto), '请登录后访问博客');
        }

        $this->maybeCanonicalRedirect((string)($page['canonical'] ?? ''));

        $blogName = self::blogName($this->currentShopName());
        $style = Settings::str('default_style');
        if (!in_array($style, Settings::STYLES, true)) {
            $style = 'glass';
        }
        $darkMode = Settings::str('dark_mode');
        if (!in_array($darkMode, Settings::DARK_MODES, true)) {
            $darkMode = 'auto';
        }

        $about = self::aboutPage();

        $data['blog'] = [
            'name' => $blogName,
            'subtitle' => Settings::str('blog_subtitle'),
            'description' => Settings::str('blog_description'),
            'keywords' => Settings::str('blog_keywords'),
            'style' => $style,
            'dark' => $darkMode,
            'pretty' => Settings::bool('pretty_links') ? 1 : 0,
            'like_enabled' => Settings::bool('like_enabled') ? 1 : 0,
            'comment_enabled' => Settings::bool('comment_enabled') ? 1 : 0,
            'comment_audit' => Settings::bool('comment_audit') ? 1 : 0,
            'comment_max_depth' => Settings::int('comment_max_depth', 1, 6),
            'comment_maxlen' => Settings::int('comment_maxlen', 50, 5000),
            'feed_enabled' => Settings::bool('feed_enabled') ? 1 : 0,
            'lazyload' => Settings::bool('lazyload_img') ? 1 : 0,
            //独立域名下不给「返回商城」入口——这个功能的意义就是不让下游看到商城
            'standalone' => Domain::active() ? 1 : 0,
            //独立域名下，登录入口只在「登录确实有用」时才露出（要评论或点赞）。
            //否则等于白给下游一个通往商城登录页的门，那页面会渲染商城主题
            'login_visible' => (!Domain::active()
                || Settings::bool('comment_enabled') || Settings::bool('like_enabled')) ? 1 : 0,
            'back_url' => Domain::active() ? '' : (Settings::str('back_to_shop_url') ?: '/'),
            'about_url' => $about ? Url::page((string)$about['slug']) : '',
            'about_title' => $about ? (string)$about['title'] : '',
            'urls' => [
                'index' => Url::index(),
                'category' => Url::categoryIndex(),
                'tag' => Url::tagIndex(),
                'archive' => Url::archive(),
                'search' => Url::search(),
                'feed' => Url::feed(),
            ],
            'api' => '/plugin/Blog/api',
        ];

        $defaults = [
            'title' => $blogName,
            'title_full' => '',
            'description' => Settings::str('blog_description'),
            'keywords' => Settings::str('blog_keywords'),
            'canonical' => '',
            'og_type' => 'website',
            'og_image' => '',
            'published_iso' => '',
            'modified_iso' => '',
            'jsonld' => null,
            'active' => '',
            'tab' => '',
            'is_sub' => false,
            'body_class' => '',
            'noindex' => false,
        ];
        $page = array_merge($defaults, $page);
        if ($page['title'] === '') {
            $page['title'] = $blogName; //首页等场景：标题即博客名
        }
        if ($page['title_full'] === '') {
            $page['title_full'] = $page['title'] === $blogName
                ? $blogName . ($data['blog']['subtitle'] !== '' ? ' - ' . $data['blog']['subtitle'] : '')
                : $page['title'] . ' - ' . $blogName;
        }
        if (is_array($page['jsonld'])) {
            $page['jsonld_json'] = json_encode($page['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $data['page'] = $page;

        return $this->render($page['title'], $template, $data, true);
    }

    /** 博客 404（桥接与各控制器共用），必然带 404 状态码 */
    public function notFound(): string
    {
        //保持真 404 状态码（软 404 会让搜索引擎把不存在的页面当正常页收录）。
        //注意：本机 Nginx 开了 fastcgi_intercept_errors 之类的错误页拦截，
        //会把带 404 状态的响应体整个换成平台自带的 404 页，博客的 404 就显示不出来——
        //那是服务器配置层面的事，不能靠去掉状态码来绕
        http_response_code(404);
        return $this->blogRender('NotFound.html', [
            'title' => lang('页面不存在'),
            'is_sub' => true,
            'noindex' => true,
            'body_class' => 'blog-page-404',
        ]);
    }

    /** 原生路径直访 → 301 到短链 canonical（桥接进入或短链关闭时不动作） */
    private function maybeCanonicalRedirect(string $canonical): void
    {
        if ($canonical === '' || !Settings::bool('pretty_links') || Bridge::$active) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        if (!str_starts_with($canonical, '/blog')) {
            return;
        }
        $currentPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if (str_starts_with($currentPath, '/blog')) {
            return; //已在短链上（例如伪静态直达）
        }
        header('Location: ' . $canonical, true, 301);
        exit;
    }

    /** 完整博客名（含站点名回退），供控制器构造 JSON-LD 等 */
    protected function siteBlogName(): string
    {
        return self::blogName($this->currentShopName());
    }

    private function currentShopName(): string
    {
        try {
            $config = \App\Model\Config::list();
            return (string)($config['shop_name'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function blogName(string $shopName): string
    {
        $name = Settings::str('blog_name');
        if ($name !== '') {
            return $name;
        }
        //默认就用店铺名本身，不再往后缀「博客」——顶栏、面包屑、标题里重复出现「XX 博客」很啰嗦。
        //想让博客有个区别于商城的名字，去设置里单独填「博客名称」即可。
        //店铺名为空是异常兜底，这时才用入口名称顶上，免得整个标题是空的
        return $shopName !== '' ? $shopName : Settings::navLabel();
    }

    /** 「关于」独立页（slug=about 且已发布）用于导航，静态缓存本请求 */
    protected static function aboutPage(): ?array
    {
        if (!self::$aboutLoaded) {
            self::$aboutLoaded = true;
            try {
                $about = Post::query()
                    ->where('type', Post::TYPE_PAGE)
                    ->where('slug', 'about')
                    ->where('status', Post::STATUS_PUBLISHED)
                    ->first(['id', 'slug', 'title']);
                self::$aboutCache = $about ? ['slug' => (string)$about->slug, 'title' => (string)$about->title] : null;
            } catch (\Throwable $e) {
                self::$aboutCache = null;
            }
        }
        return self::$aboutCache;
    }
}

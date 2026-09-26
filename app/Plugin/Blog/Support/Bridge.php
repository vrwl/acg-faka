<?php
declare (strict_types=1);

namespace App\Plugin\Blog\Support;

use App\Plugin\Blog\Core\Log;
use App\Plugin\Blog\Core\Settings;
use App\Plugin\Blog\Core\Slug;
use Kernel\Annotation\Collector;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;

/**
 * /blog 优雅短链桥接器。
 *
 * 内核在 NotFoundException 时 hook(HTTP_NOT_FOUND, $routePath)（异常被吞、随后 exit 404），
 * 这里识别 /blog 前缀后复刻内核 dispatch 流水线（拦截器→注入→调用），输出并 exit 接管；
 * 不属于博客的路径原样 return 交还内核。
 *
 * 铁律：
 *  - PluginView() 依赖 $_GET['s'] 的 plugin/ 前缀 → 必须重写 $_GET['s'] 为原生路径；
 *  - UserPlugin::render(...,true) 依赖 Plugin::$currentControllerPluginName → 必须手工赋值；
 *  - hook 里抛出的异常会被内核静默吞掉变成白 404 → 全链路自己兜底。
 */
final class Bridge
{
    /** 当前请求是否经短链桥接进入（FrontController 据此决定 canonical 301 方向） */
    public static bool $active = false;

    public static function handle(string $routePath): void
    {
        try {
            if (!in_array((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
                return;
            }

            $path = '/' . trim(rawurldecode($routePath), '/');
            if ($path !== '/blog' && !str_starts_with($path, '/blog/')) {
                return;
            }

            if (!Settings::bool('pretty_links')) {
                //短链关闭：/blog 表现为内核 404，原生 /plugin/Blog 路径不受影响
                return;
            }

            //尾斜杠归一（canonical 唯一化）：/blog/post/x/ → 301 /blog/post/x
            $rawPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
            if (strlen($rawPath) > strlen('/blog') && str_ends_with($rawPath, '/')) {
                $target = rtrim($rawPath, '/');
                $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
                //.htaccess QSA 会把 s= 一起塞进来，重定向时剔掉
                if ($qs !== '') {
                    parse_str($qs, $qsMap);
                    unset($qsMap['s']);
                    $qs = http_build_query($qsMap);
                }
                header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 301);
                exit;
            }

            self::serve(self::segments(substr($path, strlen('/blog'))));
        } catch (JSONException $e) {
            self::emitJsonError($e);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /**
     * 独立域名入口（挂 KERNEL_INIT）。命中绑定域名时这个域名下只有博客：
     * 博客路由自己接管，商城的一切路径一律给博客 404 —— 这就是「不泄露商城地址」的那道墙。
     * 放行的只有博客自己的原生路径（前端 JS 打的 /plugin/Blog/api/*）和登录流程（评论要用）。
     */
    public static function handleStandalone(): void
    {
        try {
            if (!Domain::active()) {
                return;
            }

            $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/');
            $path = '/' . trim(rawurldecode($path), '/');
            $lower = strtolower($path);

            //博客自己的原生路径：API、manifest、静态资源都在这儿，必须原样交还内核
            if ($lower === '/plugin/blog' || str_starts_with($lower, '/plugin/blog/')) {
                return;
            }
            //登录/注册/登出：评论需要登录态，挡掉的话独立站就只能只读
            if (str_starts_with($lower, '/user/authentication/')) {
                return;
            }
            //已存在的静态文件（favicon、assets）正常由 web server 处理，走到 PHP 说明是找不到的路径

            if (!in_array((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
                self::notFound();
            }

            //独立域名下 /blog/x 与 /x 是同一页：统一 301 到不带前缀的那个，避免重复内容
            if ($lower === '/blog' || str_starts_with($lower, '/blog/')) {
                $target = substr($path, strlen('/blog'));
                $target = $target === '' ? '/' : $target;
                $qs = self::queryString();
                header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 301);
                exit;
            }

            //尾斜杠归一
            $raw = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/');
            if (strlen($raw) > 1 && str_ends_with($raw, '/')) {
                $qs = self::queryString();
                header('Location: ' . rtrim($raw, '/') . ($qs !== '' ? '?' . $qs : ''), true, 301);
                exit;
            }

            self::serve(self::segments($path));
        } catch (JSONException $e) {
            self::emitJsonError($e);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /** 路径切成段（空段丢弃） */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), function ($s) {
            return $s !== '';
        }));
    }

    /** 当前查询串，剔掉伪静态塞进来的 s= */
    private static function queryString(): string
    {
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($qs === '') {
            return '';
        }
        parse_str($qs, $map);
        unset($map['s']);
        return http_build_query($map);
    }

    /** 解析 → 复刻内核 dispatch → 输出 → exit。必然不返回。 */
    private static function serve(array $segments): void
    {
        try {
            $resolved = self::resolve($segments);
            if ($resolved === null) {
                self::notFound();
            }

            [$class, $action, $params, $nativeS] = $resolved;

            foreach ($params as $k => $v) {
                $_GET[$k] = $v;
                $_REQUEST[$k] = $v;
            }
            $_GET['s'] = $nativeS; //兼容 PluginView() 等路由嗅探
            \Kernel\Util\Plugin::$currentControllerPluginName = 'Blog';
            self::$active = true;

            if (!class_exists($class)) {
                self::notFound();
            }
            $instance = new $class;
            if (!method_exists($instance, $action)) {
                self::notFound();
            }

            //复刻 kernel/Kernel.php 的 dispatch：拦截器（Waf/UserVisitor/UserSession）在这两步里执行
            Collector::instance()->classParse($instance, function (\ReflectionAttribute $attribute) {
                $attribute->newInstance();
            });
            Collector::instance()->methodParse($instance, $action, function (\ReflectionAttribute $attribute) {
                $attribute->newInstance();
            });
            Di::instance()->inject($instance);
            $parameters = Collector::instance()->getMethodParameters($instance, $action, $_REQUEST);
            $result = call_user_func_array([$instance, $action], $parameters);

            if ($result !== null) {
                if (is_scalar($result)) {
                    if (!self::hasContentType()) {
                        header('Content-type: text/html; charset=utf-8');
                    }
                    echo (string)$result;
                } else {
                    header('content-type:application/json;charset=utf-8');
                    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            exit;
        } catch (JSONException $e) {
            self::emitJsonError($e);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /** 拦截器（如 Waf）抛出的业务异常，按内核口径输出 JSON */
    private static function emitJsonError(JSONException $e): void
    {
        header('content-type:application/json;charset=utf-8');
        echo json_encode(['code' => $e->getCode(), 'msg' => lang($e->getMessage())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** 内核会把 hook 异常吞成白 404 —— 必须自己兜底 */
    private static function fail(\Throwable $e): void
    {
        //日志本身也可能抛（目录不可写等），不能让它把兜底页一起带走
        try {
            Log::error('bridge: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } catch (\Throwable $ignored) {
        }
        self::notFound();
    }

    /**
     * /blog 子路径 → [控制器类, 方法, 注入参数, 原生 s 路径]；未命中返回 null。
     * @return array{0:string,1:string,2:array,3:string}|null
     */
    private static function resolve(array $seg): ?array
    {
        $ns = 'App\\Plugin\\Blog\\Controller\\';
        $count = count($seg);

        if ($count === 0) {
            return [$ns . 'Index', 'index', [], '/plugin/blog/index/index'];
        }

        $first = strtolower((string)$seg[0]);

        if ($count === 1) {
            switch ($first) {
                case 'category':
                    return [$ns . 'Category', 'index', [], '/plugin/blog/category/index'];
                case 'tag':
                    return [$ns . 'Tag', 'index', [], '/plugin/blog/tag/index'];
                case 'archive':
                    return [$ns . 'Archive', 'index', [], '/plugin/blog/archive/index'];
                case 'search':
                    return [$ns . 'Search', 'index', [], '/plugin/blog/search/index'];
                case 'feed':
                    return [$ns . 'Feed', 'index', [], '/plugin/blog/feed/index'];
                case 'sitemap.xml':
                    return [$ns . 'Feed', 'sitemap', [], '/plugin/blog/feed/sitemap'];
                default:
                    return null;
            }
        }

        if ($count === 2) {
            $slug = (string)$seg[1];
            if (!Slug::valid($slug)) {
                return null;
            }
            switch ($first) {
                case 'post':
                    return [$ns . 'Post', 'detail', ['slug' => $slug], '/plugin/blog/post/detail'];
                case 'category':
                    return [$ns . 'Category', 'detail', ['slug' => $slug], '/plugin/blog/category/detail'];
                case 'tag':
                    return [$ns . 'Tag', 'detail', ['slug' => $slug], '/plugin/blog/tag/detail'];
                case 'page':
                    return [$ns . 'Page', 'detail', ['slug' => $slug], '/plugin/blog/page/detail'];
                default:
                    return null;
            }
        }

        return null;
    }

    /**
     * 博客风格 404（必须带 404 状态码，防软 404 污染 SEO）；模板不可用时降级为极简页。
     * 本方法必然 exit。
     */
    private static function notFound(): void
    {
        http_response_code(404);
        try {
            $class = 'App\\Plugin\\Blog\\Controller\\Index';
            if (class_exists($class) && method_exists($class, 'notFound')) {
                \Kernel\Util\Plugin::$currentControllerPluginName = 'Blog';
                self::$active = true;
                $instance = new $class;
                Di::instance()->inject($instance);
                header('Content-type: text/html; charset=utf-8');
                echo (string)$instance->notFound();
                exit;
            }
        } catch (\Throwable $e) {
            Log::error('bridge 404 render: ' . $e->getMessage());
        }
        header('Content-type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 Not Found</title>'
            . '<body style="display:grid;place-items:center;min-height:100vh;margin:0;font:16px/1.8 system-ui;background:#0f1115;color:#e6e8ee">'
            . '<div style="text-align:center"><div style="font-size:56px;font-weight:800;letter-spacing:.06em">404</div>'
            . '<p style="opacity:.7">页面不存在或已被移除</p>'
            . '<a href="/blog" style="color:#8ab4ff;text-decoration:none">&larr; ' . htmlspecialchars(Settings::navLabel(), ENT_QUOTES) . '首页</a></div></body></html>';
        exit;
    }

    private static function hasContentType(): bool
    {
        foreach (headers_list() as $header) {
            if (str_starts_with(strtolower($header), 'content-type:')) {
                return true;
            }
        }
        return false;
    }
}

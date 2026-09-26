<?php
namespace App\Plugin\ThirdDockManage\Controller\Api;

use Amp\Emitter;
use Amp\Loop;
use Amp\Parallel\Worker\CallableTask;
use Amp\Parallel\Worker\DefaultPool;
use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Category;
use App\Model\Commodity;
use App\Plugin\ThirdDockManage\Command\SiteJob;
use App\Plugin\ThirdDockManage\Command\SyncGood;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Rules;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Service\TaskStore;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Service\Query;
use App\Util\Ini;
use App\Util\Plugin;
use App\Util\Str;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;
use function Amp\asyncCall;

require __DIR__ . '/../../vendor/autoload.php';

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Good extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Goods::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $data = $this->query->get($get);

        $exist_ids = [];//Commodity::query()->where('dock_g_id', '<>', 0)->pluck('dock_g_id')->toArray();
        $sites = Sites::withTrashed()->pluck('name', 'id')->toArray();
        foreach ($data['list'] as &$datum) {
            $datum['site_name'] = $sites[$datum['site_id']] ?? '';
            if ($datum['status'] == 1 && $datum['stock'] == 0) {
                $datum['stock'] = '∞';
            }
            $datum['updated_at'] = Carbon::parse($datum['updated_at'])->setTimezone('PRC')->toDateTimeString();
            if (in_array($datum['id'], $exist_ids)) {
                $datum['hasData'] = 1;
            } else {
                $datum['hasData'] = 0;
            }
        }

        return $this->json(data:$data);
    }

    public function init(): array
    {
        TaskStore::cleanup();

        $site_id = $_POST['site_id'] ?? null;
        if (filled($site_id)) {
            $sites = Sites::query()->where('status', 1)->where('id', $site_id)->get();
        } else {
            $sites = Sites::query()->where('status', 1)->get();
        }
        $all = [];
        if ($sites->count() > 0) {
            $sync_good = new SyncGood();
            foreach ($sites as $site) {
                $key = $sync_good->generateStep($site->toArray());
                if ($key !== false) {
                    $all[] = $key;
                }
            }
        }

        return $this->json(200, 'success', $all);
    }

    public function one()
    {
        $key = $_POST['key'] ?? null;
        if (filled($key)) {
            $next = 'end';
            $key_temp = explode('###', $key);
            $batch = $key_temp[1] ?? '';

            $site = TaskStore::getSite($batch);
            if (filled($site)) {
                $task_data = TaskStore::getTask($batch) ?? [];
                if (count($task_data) > 0) {
                    $page = $_POST['page'] ?? '';
                    if (filled($page)) {
                        $page_temp = explode('###', $page);
                        $class = $page_temp[0];
                        $now_page = $page_temp[1];
                    } else {
                        $class = 'step';
                        $now_page = 0;
                    }
                    $max_step_page = count($task_data['step']);
                    $class_path = "\App\Plugin\\{$site['type']}\Hook\Main";
                    $dock = new $class_path();
                    $sync_good = new SyncGood();
                    $good_ids = $task_data['deal_ids'] ?? [];
                    $task_data['all_ids'] = $task_data['all_ids'] ?? [];
                    $all_ids = $task_data['all_ids'] ?? [];

                    switch ($class) {
                        case 'step':
                            $task_datum = $task_data['step'][$now_page] ?? [];
                            $method = $task_datum['type'];
                            $res = $dock->$method([
                                'current' => $task_datum,
                                'data' => $task_data,
                            ], $site['account'], $site['password'], $site);
                            $goods = $res['params'] ?? [];
                            if (count($goods) > 0) {
                                $sync_good->dealSiteGoods($site, $goods);
                                foreach ($goods as $good) {
                                    $task_data['deal_ids'][] = $good['c_id'];
                                }
                                if (count($all_ids) > 0 && isset($res['deal_ids'])) {
                                    $task_data['all_ids'] = array_diff($all_ids, $res['deal_ids']);
                                }
                                TaskStore::saveTask($batch, (int)$site['id'], $task_data);
                            }
                            if ($now_page + 1 >= $max_step_page) {
                                if (count($task_data['all_ids']) > 0) {
                                    $next = 'goods###0';
                                } else {
                                    $next = 'end';
                                }
                            } else {
                                $now_page++;
                                $next = $class . '###' . $now_page;
                            }
                            break;
                        case 'goods':
                            $max_good_page = count($all_ids);
                            $all_id = $all_ids[$now_page] ?? null;
                            if (!empty($all_id)) {
                                $id_res = $dock->dealId([
                                    'current' => ['data' => $all_id],
                                    'data' => $task_data,
                                ], $site['account'], $site['password'], $site);
                                $goods = $id_res['params'] ?? [];
                                if (count($goods) > 0) {
                                    $sync_good->dealSiteGoods($site, $goods);
                                    foreach ($goods as $good) {
                                        $task_data['deal_ids'][] = $good['c_id'];
                                    }
                                    TaskStore::saveTask($batch, (int)$site['id'], $task_data);
                                }
                            }
                            if ($now_page + 1 >= $max_good_page) {
                                $next = 'end';
                            } else {
                                $now_page++;
                                $next = $class . '###' . $now_page;
                            }
                            break;
                    }
                    if ($next == 'end' && count($task_data['deal_ids']) > 0) {
                        Plugin::setCache('ThirdDockManage', 'sync_good', 'last_sync_time', Carbon::now('PRC')->toDateTimeString(), 0, true);
                        Goods::query()->where('site_id', $site['id'])->whereNotIn('c_id', $task_data['deal_ids'])
                            ->update(['status' => 2]);
                        $shelves_ids = Goods::query()->where('site_id', $site['id'])->where('status', 2)->pluck('id')->toArray();
                        if (count($shelves_ids) > 0) {
                            //上游下架同步成本地下架，广播给事件订阅方（口径同 Admin\Api\Commodity::status）
                            $ebIds = array_map('intval', \App\Plugin\ThirdDockManage\Model\Commodity::query()->whereIn('dock_g_id', $shelves_ids)->pluck('id')->toArray());
                            \App\Plugin\ThirdDockManage\Model\Commodity::query()->whereIn('dock_g_id', $shelves_ids)->update(['status' => 0]);
                            if ($ebIds !== []) {
                                $ebAction = 'status';
                                $ebBefore = null;
                                hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
                            }
                        }
                    }
                }
            }

            //批次生命周期结束（含批次丢失、数据异常等提前结束的情况），整行删除
            if ($next == 'end') {
                TaskStore::forget($batch);
            }

            return $this->json(200, 'success', ['next' => $next]);
        } else {
            return $this->json(400, '参数错误', []);
        }
    }

    public function clone(): array
    {
        try {
//            $keys = $_POST['keys'] ?? [];

            $sites = Sites::query()->where('status', 1)->get();
            $rules = Rules::query()->where('status', 1)->orderBy('sort')->orderBy('id')->get();
            if ($rules) {
                $sync_good = new SyncGood();
                $plugin_config = Plugin::getConfig('ThirdDockManage');

                $site_class = $site_domain = [];
                foreach ($sites as $site) {
                    $site_domain[$site->id] = $site->domain;
                    $class = "\App\Plugin\\{$site->type}\Hook\Main";
                    $dock = new $class();
                    $class = $dock->getClass($site->account, $site->password, $site->toArray());
                    if ($class['status_code'] == 200) {
                        $temp = [];
                        foreach ($class['data'] as $datum) {
                            $temp[$datum['name']] = $datum;
                        }
                        $site_class[$site->id] = $temp;
                    }
                }

                //整站分类
                $category_array = Category::query()->pluck('id', 'name')->toArray();

                foreach ($rules as $rule) {
                    $sync_good->cloneDeal($rule, $category_array, $site_class, $site_domain, $plugin_config);
                }
            }

            return $this->json(200, 'success', []);
        } catch (\Exception $e) {
            return $this->json(400, $e->getMessage(), []);
        }
    }

    public function sync(): array
    {
        $site_id = $_POST['site_id'] ?? null;
        if (filled($site_id)) {
            $sites = Sites::query()->where('status', 1)->where('id', $site_id)->get();
        } else {
            $sites = Sites::query()->where('status', 1)->get();
        }
        if ($sites->count() > 0) {
            $sync_good = new SyncGood();
            $plugin_config = Plugin::getConfig('ThirdDockManage');
            if ($sites->count() == 1) {
                $collection_enhance = 0;
            } else {
                $collection_enhance = (int) ($plugin_config['collection_enhance'] ?? 0);
            }
            if ($collection_enhance == 0) {
                foreach ($sites as $site) {
                    $sync_good->aloneSite($site->toArray());
                }
            } else {
                Loop::run(function () use ($sites, $collection_enhance, $sync_good) {
                    $pool = new DefaultPool($collection_enhance);
                    $coroutines = [];
                    foreach ($sites as $index => $site) {
                        $coroutines[] = \Amp\call(function () use ($pool, $site, $sync_good) {
//                            yield $pool->enqueue(new CallableTask([new SyncGood(), 'aloneSite'], [$site]));
                            $over = yield $pool->enqueue(new SiteJob('syncGood', $site->toArray()));
                            if (filled($over)) {
                                $sync_good->dealSiteGoods($over['data']['site'] ?? [], $over['data']['goods'] ?? []);
                            }
                        });
                    }
                    yield \Amp\Promise\all($coroutines);
                    return yield $pool->shutdown();
                });
            }
            Plugin::setCache('ThirdDockManage', 'sync_good', 'last_sync_time', Carbon::now('PRC')->toDateTimeString());

            //clone rules
            $rules = Rules::query()->where('status', 1)->orderBy('sort')->orderBy('id')->get();
            if ($rules) {
                $site_class = $site_domain = [];
                foreach ($sites as $site) {
                    $site_domain[$site->id] = $site->domain;
                    $class = "\App\Plugin\\{$site->type}\Hook\Main";
                    $dock = new $class();
                    $class = $dock->getClass($site->account, $site->password, $site->toArray());
                    if ($class['status_code'] == 200) {
                        $temp = [];
                        foreach ($class['data'] as $datum) {
                            $temp[$datum['name']] = $datum;
                        }
                        $site_class[$site->id] = $temp;
                    }
                }

                //整站分类
                $category_array = Category::query()->pluck('id', 'name')->toArray();

                foreach ($rules as $rule) {
                    $sync_good->cloneDeal($rule, $category_array, $site_class, $site_domain, $plugin_config);
                }
            }
        }

        return $this->json(200, 'success', []);
    }

    /**
     * @throws JSONException
     */
    public function generate(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        //参数校验
        $id = filled($map['id']) ? $map['id'] : null;
        if ($id == 'all') {
            $name = filled($map['name']) ? $map['name'] : null;
            $category = filled($map['category']) ? $map['category'] : null;
            $status = filled($map['status']) ? $map['status'] : null;
            $site_id = filled($map['site_id']) ? $map['site_id'] : null;
            $query = Goods::query();
            if (filled($name)) {
                $query->where('name', 'like', '%' . urldecode($name) . '%');
            }
            if (filled($category)) {
                $query->where('category', 'like', '%' . urldecode($category) . '%');
            }
            if (filled($status)) {
                $query->where('status', $status);
            }
            if (filled($site_id)) {
                $query->where('site_id', $site_id);
            }
            $id_temp = $query->pluck('id')->toArray();
            $id = implode(',', $id_temp);
        }
        if (blank($id) || $id == 'all') {
            throw new JSONException("参数错误");
        }
        $category_id = filled($map['category_id']) ? $map['category_id'] : 0;
        if (empty($category_id)) {
            throw new JSONException("请选择商品分类");
        }
        $mode = filled($map['mode']) ? $map['mode'] : 0;
        if (!in_array($mode, [0, 1, 2])) {
            throw new JSONException("加价模式参数错误");
        }
        $mode_value = $this->changeStr(filled($map['mode_value']) ? $map['mode_value'] : 0);
        $lucky_decimal = filled($map['lucky_decimal']) ? $map['lucky_decimal'] : 0;
        $sync_price = $map['sync_price'];
        if (!in_array($sync_price, [0, 1])) {
            $sync_price = 0;
        }
        $sync_now = $map['sync_now'];
        if (!in_array($sync_now, [0, 1])) {
            $sync_now = 0;
        }
        $sync_content = $map['sync_content'];
        if (!in_array($sync_content, [0, 1])) {
            $sync_content = 0;
        }
        $sync_title = $map['sync_title'];
        if (!in_array($sync_title, [0, 1])) {
            $sync_title = 0;
        }
        $only_user = $map['only_user'];
        if (!in_array($only_user, [0, 1])) {
            $only_user = 0;
        }
        $cover = $map['cover'];
        if (!in_array($cover, [0, 1])) {
            $cover = 0;
        }
        $use_upload = $map['use_upload'];
        if (!in_array($use_upload, [0, 1, 2, 3])) {
            $use_upload = 2;
        }
        $content_replace = $map['content_replace'] ?? null;
        $show_stock = $map['show_stock'];
        if (!in_array($show_stock, [0, 1, 2])) {
            $show_stock = 0;
        }
        $api_status = $map['api_status'];
        if (!in_array($api_status, [0, 1])) {
            $api_status = 0;
        }

        $plugin_config = Plugin::getConfig('ThirdDockManage');
        $site_url = Sites::query()->pluck('domain', 'id')->toArray();
        $site_type = Sites::query()->pluck('type', 'id')->toArray();
        $exist_ids = Commodity::query()->where('dock_g_id', '<>', 0)->pluck('dock_g_id')->toArray();
        $exist_map = Commodity::query()->where('dock_g_id', '<>', 0)->pluck('id', 'dock_g_id')->toArray();
        //生成商品
        $ids = explode(',', $id);
        $count = count($ids);
        $success = 0;
        $error = 0;
        $res_id = [];
        foreach ($ids as $id) {
            if ($cover == 0 && in_array($id, $exist_ids)) {
                $success++;
                continue;
            }
            $res_id[] = $id;
        }
        if (count($res_id) > 0) {
            $settings = compact("mode", "mode_value", "lucky_decimal", "sync_price", "sync_content",
                "sync_now", "sync_title", "only_user", "cover", "use_upload", "content_replace", "show_stock", "exist_map",
                "api_status");

            foreach ($res_id as $v) {
                $this->generateGood($v, $site_url, $category_id, $plugin_config, $settings, $success, $error);
            }

//            $res_id_collect = collect($res_id);
//            $all_num = $res_id_collect->count();
//            $size = $all_num / 3;
//            if ($size <= 1) {
//                $size = 1;
//            } else {
//                $size = ceil($size);
//            }
//            $chunks = $res_id_collect->chunk($size);
//            Loop::run(function () use ($chunks, $site_url, $category_id, $plugin_config, $settings, &$success, &$error) {
//                try {
//                    $emitter = new Emitter();
//                    $iterator = $emitter->iterate();
//
//                    $generator = [];
//                    foreach ($chunks as $array) {
//                        $generator[] = function (Emitter $emitter) use ($array, $site_url, $category_id, $plugin_config, $settings, &$success, &$error) {
//                            foreach ($array as $v) {
//                                yield $emitter->emit($this->generateGood($v, $site_url, $category_id, $plugin_config, $settings, $success, $error));
//                            }
//                        };
//                    }
//                    foreach ($generator as $g) {
//                        asyncCall($g, $emitter);
//                    }
//                    while (yield $iterator->advance()) {
//                        $iterator->getCurrent();
////                        yield new Delayed(500);
//                    }
//                    $emitter->complete();
//                } catch (\Exception $exception) {
//                    Plugin::log("ThirdDockManage", "生成商品错误：" . (string)$exception);
//                }
//            });
        }

        return $this->json(200, "生成商品结束，总数量：{$count}，成功：{$success}，失败：{$error}", []);
    }

    public function generateGood($id, $site_url, $category_id, $plugin_config, $settings, &$success, &$error)
    {
        extract($settings);
        \Illuminate\Database\Capsule\Manager::beginTransaction();
        try {
            $good = Goods::query()->find($id);
            $domain = $site_url[$good->site_id];

            $commodity = new Commodity();
            $commodity->category_id = $category_id;
            $commodity->name = $good->name;
            if ($plugin_config['try_fix_good_detail']) {
                $commodity->description = '<div>'.($good->content ?? '').'</div>';
            } else {
                $commodity->description = $good->content ?? '';
            }

            //详情文本替换
            if (filled($content_replace)) {
                $replaces = explode('$$$', $content_replace);
                foreach ($replaces as $replace) {
                    $replace_temp = explode('##', $replace);
                    if (count($replace_temp) == 2) {
                        if (!str_starts_with($replace_temp[0], '/')) {
                            $replace_temp[0] = '/' . preg_quote($replace_temp[0], '/'). '/';
                        }
                        $commodity->description = preg_replace($replace_temp[0], $replace_temp[1], $commodity->description);
                    }
                }
            }
            if ($use_upload == 1 || $use_upload == 3 || ($use_upload == 2 && ($plugin_config['upload'] == 1 || $plugin_config['save_pic'] == 1))) {//图床
                preg_match_all('/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S\>]?/', $commodity->description, $match);
                $list = array_values(array_unique((array)$match[1]));
                if (count($list) > 0) {
                    foreach ($list as $e) {
                        $res_url = $e;
                        if (!$this->startsWith($e, 'http')) {
                            $res_url = $domain . $e;
                        }
                        if ($use_upload == 3 || ($use_upload == 2 && $plugin_config['save_pic'] == 1)) {//存本地
                            $res_url_temp = $this->downloadImage($res_url);
                            if ($res_url_temp !== false) {
                                $res_url = '/assets/cache/images/'.$res_url_temp;
                            }
                        } else {
                            $res_url = $this->uploadImage($res_url, $plugin_config);
                        }
                        $commodity->description = str_replace($e, $res_url, $commodity->description);
                    }
                }
            }

            //适配，转2位小数
//            $good->price = round($good->price, 2);
            $price = $this->scToStr($good->price);
            $commodity->factory_price = $price;
            $current_price = $this->calcPrice($price, $mode, $mode_value, $lucky_decimal);
            $commodity->price = $commodity->user_price = $current_price;
            if (filled($good->img)) {
                $img = $good->img;
                //图床
                if (strlen($img) > 250 || $use_upload == 3 || ($use_upload == 2 && $plugin_config['save_pic'] == 1)) {
                    $res_url_temp = $this->downloadImage($img);
                    if ($res_url_temp !== false) {
                        $img = '/assets/cache/images/'.$res_url_temp;
                    }
                } elseif ($use_upload == 1 || ($use_upload == 2 && $plugin_config['upload'] == 1)) {
                    $img = $this->uploadImage($img, $plugin_config);
                }
                $commodity->cover = $img;
            } else {
                $commodity->cover = '';
            }

            $commodity->status = $good->status == 1 ? 1 : 0;
            $commodity->owner = 0;
            $commodity->create_time = Carbon::now('PRC')->toDateTimeString();
            $commodity->api_status = $api_status;
            $commodity->code = strtoupper(Str::generateRandStr(16));
            $commodity->delivery_way = 1;
            $commodity->coupon = 0;
            $commodity->widget = $good->attach;
            $commodity->minimum = $good->min_num;
            $commodity->maximum = $good->max_num;

            $commodity->dock_g_id = $id;
            $commodity->dock_mode = $mode;
            $commodity->dock_mode_value = $mode_value;
            $commodity->dock_lucky_decimal = $lucky_decimal;
            $commodity->dock_sync_price = $sync_price;
            $commodity->dock_sync_content = $sync_content;
            $commodity->dock_sync_now = $sync_now;
            $commodity->dock_sync_title = $sync_title;
            $commodity->only_user = $only_user;
            $commodity->dock_attach = serialize([
                'use_upload' => $use_upload,
                'content_replace' => $content_replace,
                'show_stock' => $show_stock,
            ]);

            if ($cover && isset($exist_map[$id])) {//覆盖替换
                $o_id = $exist_map[$id];
                Commodity::query()->where('id', $o_id)->update($commodity->toArray());
            } else {
                $commodity->save();
            }
            $success++;

            \Illuminate\Database\Capsule\Manager::commit();
            //生成/覆盖了商品，广播给事件订阅方
            if (isset($o_id)) {
                $ebArgs = [(int)$o_id];
                $ebAction = 'sync';
                $ebBefore = null;
                hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebArgs, $ebAction, $ebBefore);
            } else {
                $ebArgs = [(int)$commodity->id];
                $ebAction = 'create';
                $ebBefore = null;
                hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebArgs, $ebAction, $ebBefore);
            }
        } catch (\Exception $e) {
            \Illuminate\Database\Capsule\Manager::rollBack();
            Plugin::log('ThirdDockManage', $e->getMessage());
            $error++;
        }
        return 'end';
    }
}

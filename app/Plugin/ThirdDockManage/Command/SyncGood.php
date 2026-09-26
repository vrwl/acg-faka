<?php

namespace App\Plugin\ThirdDockManage\Command;

use Amp\Loop;
use Amp\Parallel\Worker\CallableTask;
use Amp\Parallel\Worker\DefaultPool;
use App\Model\Category;
use App\Model\Commodity;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Rules;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Service\TaskStore;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Ini;
use App\Util\Plugin;
use App\Util\Str;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\asyncCall;
use function Amp\call;

require __DIR__ . '/../vendor/autoload.php';

#[AsCommand(
    name: 'sync:good',
    description: '同步商品.',
    hidden: false,
)]
class SyncGood extends Command
{
    use Help;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDefinition(
            new InputDefinition([
                new InputOption('site', 's', InputOption::VALUE_OPTIONAL),
                new InputOption('exclude', 'ex', InputOption::VALUE_OPTIONAL),
            ])
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('开始内存:'.memory_get_usage());
        $output->writeln("同步商品开始:".Carbon::now('PRC')->toDateTimeString());

        $lock = $this->acquireSyncLock('sync_good');
        if (!$lock) {
            $output->writeln('上一次同步商品仍在执行，本次跳过');
            return Command::SUCCESS;
        }

        TaskStore::cleanup();

        $sites = $this->querySites($input->getOption('site'), $input->getOption('exclude'));
        $output->writeln("开启站点共有：".$sites->count());
        if ($sites->count() > 0) {
            $plugin_config = Plugin::getConfig('ThirdDockManage');
            $collection_enhance = (int) ($plugin_config['collection_enhance'] ?? 0);
            if ($collection_enhance == 0) {
                foreach ($sites as $site) {
                    $over = $this->aloneSiteData($site->toArray());
                    if (filled($over)) {
                        $output->writeln('已处理站点：' . $over);
                    }
                }
            } else {
                $output->writeln('已启动采集增强模式~');
                Loop::run(function () use ($sites, $collection_enhance, $output) {
                    $pool = new DefaultPool($collection_enhance);
                    $coroutines = [];
                    foreach ($sites as $index => $site) {
                        $coroutines[] = \Amp\call(function () use ($pool, $site, $output) {
//                            $over = yield $pool->enqueue(new CallableTask([$this, 'aloneSite'], [$site]));
                            $over = yield $pool->enqueue(new SiteJob('syncGood', $site->toArray()));
                            if (filled($over)) {
//                                $this->dealSiteGoods($over['data']['site'] ?? [], $over['data']['goods'] ?? []);
                                $output->writeln('已处理站点：' . $over);
                            }
                        });
                    }
                    yield \Amp\Promise\all($coroutines);
                    return yield $pool->shutdown();
                });
            }
            $this->touchLastSyncTime();

            $this->cloneRules($sites, $plugin_config, $output);
        }

        $this->releaseSyncLock($lock);

        $output->writeln("同步商品结束:".Carbon::now('PRC')->toDateTimeString());
        $output->writeln('结束内存:'.memory_get_usage());
        $output->writeln('最大内存使用量:'.memory_get_peak_usage());
        return Command::SUCCESS;
    }

    /**
     * 待同步的站点（CLI 与线程管理器任务共用）
     * @param string|null $site 站点ID，多个以英文逗号隔开
     * @param string|null $exclude 排除的站点ID，多个以英文逗号隔开
     */
    public function querySites(?string $site = null, ?string $exclude = null)
    {
        if (filled($site)) {
            $site_array = explode(',', $site);
            $sites = Sites::query()->where('status', 1)->whereIn('id', $site_array)->get();
        } else {
            $sites = Sites::query()->where('status', 1)->get();
        }
        if (filled($exclude)) {
            $exclude_array = explode(',', $exclude);
            $sites = $sites->filter(function ($value) use ($exclude_array) {
                return !in_array($value->id, $exclude_array);
            });
        }
        return $sites;
    }

    public function touchLastSyncTime(): void
    {
        Plugin::setCache('ThirdDockManage', 'sync_good', 'last_sync_time', Carbon::now('PRC')->toDateTimeString(), 0, $_SERVER['third_dock_mode'] ?? false);
    }

    /**
     * 按克隆规则生成商品（CLI 与线程管理器任务共用）
     * @param mixed $sites 待同步的站点集合
     * @param array $plugin_config 本插件配置
     */
    public function cloneRules($sites, array $plugin_config, OutputInterface $output = null): void
    {
        $output?->writeln('开始克隆...');
        $rules = Rules::query()->where('status', 1)->orderBy('sort')->orderBy('id')->get();
        if ($rules) {
            //整站分类
            $category_array = Category::query()->pluck('id', 'name')->toArray();

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
            foreach ($rules as $rule) {
                $this->cloneDeal($rule, $category_array, $site_class, $site_domain, $plugin_config);
            }
        }
        $output?->writeln('克隆结束...');
    }

    /**
     * @throws JSONException
     */
    public function aloneSiteData($site)
    {
        $class = "\App\Plugin\\{$site['type']}\Hook\Main";
        $dock = new $class();
        $batch = 'CLI' . time() . bin2hex(random_bytes(10));
        $step_res = $dock->allStep($batch, $site['account'], $site['password'], $site);
        if ($step_res) {
            $task_data = TaskStore::getTask($batch) ?? [];
            TaskStore::forget($batch);
            if (count($task_data) > 0) {
                $good_ids = [];
                $all_ids = $task_data['all_ids'] ?? [];
                foreach ($task_data['step'] as $task_datum) {
                    $method = $task_datum['type'];
                    $res = $dock->$method([
                        'current' => $task_datum,
                        'data' => $task_data,
                    ], $site['account'], $site['password'], $site);
                    $goods = $res['params'] ?? [];
                    if (count($goods) > 0) {
                        $this->dealSiteGoods($site, $goods);
                        foreach ($goods as $good) {
                            $good_ids[] = $good['c_id'];
                        }
                    }
                    if (count($all_ids) > 0 && isset($res['deal_ids'])) {
                        $all_ids = array_diff($all_ids, $res['deal_ids']);
                    }
                }
                //Fill the leak
                if (count($all_ids) > 0) {
                    foreach ($all_ids as $all_id) {
                        $id_res = $dock->dealId([
                            'current' => ['data' => $all_id],
                            'data' => $task_data,
                        ], $site['account'], $site['password'], $site);
                        $goods = $id_res['params'] ?? [];
                        if (count($goods) > 0) {
                            $this->dealSiteGoods($site, $goods);
                            foreach ($goods as $good) {
                                $good_ids[] = $good['c_id'];
                            }
                        }
                    }
                }
                if (count($good_ids) > 0) {
//                    Plugin::log('ThirdDockManage', json_encode(['site' => $site, 'good_ids' => $good_ids]));
                    Goods::query()->where('site_id', $site['id'])->whereNotIn('c_id', $good_ids)
                        ->update(['status' => 2]);
                    $shelves_ids = Goods::query()->where('site_id', $site['id'])->where('status', 2)->pluck('id')->toArray();
                    if (count($shelves_ids) > 0) {
                        $ebIds = array_map('intval', \App\Plugin\ThirdDockManage\Model\Commodity::query()->whereIn('dock_g_id', $shelves_ids)->pluck('id')->toArray());
                        \App\Plugin\ThirdDockManage\Model\Commodity::query()->whereIn('dock_g_id', $shelves_ids)->update(['status' => 0]);
                        if ($ebIds !== []) {
                            $ebAction = 'status';
                            $ebBefore = null;
                            hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
                        }
                    }
                }
//                Plugin::delCache($site['type'], 'task', $batch, $_SERVER['third_dock_mode'] ?? false);
            }
//        $goods = $dock->getAllGoods($site['account'], $site['password'], $site);
//        if ($goods['status_code'] == 200) {
//            return ['name' => $site['name'], 'data' => ['site' => $site, 'goods' => $goods['data']]];
//        }
        }

        return $site['name'];
    }

    public function dealSiteGoods($site, $good_data)
    {
        if (!isset($site['id']) || empty($good_data)) {
            return [];
        }
        //本轮实际被改写的商品 id，循环结束后统一广播
        $ebIds = [];
//        Goods::query()->where('site_id', $site['id'])->update(['status' => 2]);
        foreach ($good_data as $datum) {
            if (!isset($datum['c_id']) || empty($datum['c_id'])) {
                continue;
            }
            if (!isset($datum['site_id']) || empty($datum['site_id'])) {
                continue;
            }
            if (!isset($datum['name']) || empty($datum['name'])) {
                continue;
            }

            if ($datum['stock'] < 0) {
                $datum['stock'] = 0;
            }
            if (is_array($datum['attach'])) {
                $datum['attach'] = json_encode($datum['attach']);
            }
            $price_temp = explode('.', $datum['price']);
            if (strlen(head($price_temp)) > 8) {
                $price_temp[0] = substr(head($price_temp), -8);
                $datum['price'] = implode('.', $price_temp);
            }


//                    DB::beginTransaction();
            try {
                $n_good = Goods::query()->where('site_id', $datum['site_id'])
                    ->where('c_id', $datum['c_id'])->first();
                if ($n_good) {//编辑
                    $n_good->update($datum);
                    $changes = $n_good->getChanges();
                    if (count($changes) > 0) {
                        $plugin_config = Plugin::getConfig('ThirdDockManage');
                        $commodity_lists = Commodity::query()->where('dock_g_id', $n_good->id)->get();
                        if ($commodity_lists) {
                            foreach ($commodity_lists as $commodity) {
                                $mode = $commodity->dock_mode;
                                $mode_value = $this->changeStr($commodity->dock_mode_value);
                                $lucky_decimal = $commodity->dock_lucky_decimal;

                                $dock_attach = unserialize($commodity->dock_attach ?? '');
                                if ($dock_attach === false) {
                                    $dock_attach = [];
                                }
                                $dock_attach['use_upload'] = $dock_attach['use_upload'] ?? 2;
                                $commodity_update = [];
                                //同步详情
                                if ($commodity->dock_sync_content == 1 && isset($changes['content'])) {
                                    if ($plugin_config['try_fix_good_detail']) {
                                        $commodity_update['description'] = '<div>'.($n_good->content ?? '').'</div>';
                                    } else {
                                        $commodity_update['description'] = $n_good->content ?? '';
                                    }
                                    //详情文本替换
                                    $dock_attach['content_replace'] = $dock_attach['content_replace'] ?? null;
                                    if (filled($dock_attach['content_replace'])) {
                                        $replaces = explode('$$$', $dock_attach['content_replace']);
                                        foreach ($replaces as $replace) {
                                            $replace_temp = explode('##', $replace);
                                            if (count($replace_temp) == 2) {
                                                if (!str_starts_with($replace_temp[0], '/')) {
                                                    $replace_temp[0] = '/' . preg_quote($replace_temp[0], '/'). '/';
                                                }
                                                $commodity_update['description'] = preg_replace($replace_temp[0], $replace_temp[1], $commodity_update['description']);
                                            }
                                        }
                                    }
                                    if ($dock_attach['use_upload'] == 1 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && ($plugin_config['upload'] == 1 || $plugin_config['save_pic'] == 1))) {//图床
                                        preg_match_all('/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S\>]?/', $commodity_update['description'], $match);
                                        $list = array_values(array_unique((array)$match[1]));
                                        if (count($list) > 0) {
                                            $domain = $site['domain'];
                                            foreach ($list as $e) {
                                                $res_url = $e;
                                                if (!$this->startsWith($e, 'http')) {
                                                    $res_url = $domain . $e;
                                                }
                                                //图床
                                                if ($dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {//存本地
                                                    $res_url_temp = $this->downloadImage($res_url);
                                                    if ($res_url_temp !== false) {
                                                        $res_url = '/assets/cache/images/'.$res_url_temp;
                                                    }
                                                } else {
                                                    $res_url = $this->uploadImage($res_url, $plugin_config);
                                                }

                                                $commodity_update['description'] = str_replace($e, $res_url, $commodity_update['description']);
                                            }
//                                            $n_good->content = $commodity_update['description'];
                                        }
                                    }
                                }
                                //同步价格
                                if ($commodity->dock_sync_price == 1 && isset($changes['price'])) {
                                    $commodity_update['factory_price'] = $n_good->price;
                                    $current_price = $this->calcPrice($n_good->price, $mode, $mode_value, $lucky_decimal);
                                    $commodity_update['price'] = $commodity_update['user_price'] = $current_price;
                                }
                                //同步标题
                                if ($commodity->dock_sync_title == 1 && isset($changes['name'])) {
                                    $commodity_update['name'] = $n_good->name;;
                                }
                                //同步封面图
                                if ($commodity->dock_sync_title == 1 && isset($changes['img'])) {
                                    $img = $n_good->img;
                                    if (strlen($img) > 250 || $dock_attach['use_upload'] == 3 || ($dock_attach['use_upload'] == 2 && $plugin_config['save_pic'] == 1)) {
                                        $res_url_temp = $this->downloadImage($img);
                                        if ($res_url_temp !== false) {
                                            $img = '/assets/cache/images/'.$res_url_temp;
                                        }
                                    } elseif ($dock_attach['use_upload'] == 1 || ($dock_attach['use_upload'] == 2 && $plugin_config['upload'] == 1)) {
                                        $img = $this->uploadImage($n_good->img, $plugin_config);
                                    }
                                    $commodity_update['cover'] = $img;
                                }
                                //同步控件
                                if (isset($changes['attach'])) {
                                    if (!is_array($n_good->attach)) {
                                        $attach_temp = json_decode($n_good->attach, true);
                                    } else {
                                        $attach_temp = $n_good->attach;
                                    }
                                    if (isset($attach_temp['attach']) && isset($attach_temp['config'])) {
                                        $commodity_update['widget'] = json_encode($attach_temp['attach']);
                                        if (filled($attach_temp['config'])) {
                                            $temp_config = Ini::toArray($attach_temp['config']);
                                            if (isset($temp_config['category_factory'])) {
                                                foreach ($temp_config['category_factory'] as $k => $v) {
                                                    $temp_config['category'][$k] = $this->calcPrice($v, $mode, $mode_value, $lucky_decimal);
                                                }
                                            }
                                            $commodity_update['config'] = Ini::toConfig($temp_config);
                                        }
                                    } else {
                                        $commodity_update['widget'] = $n_good->attach;
                                    }
                                }
                                //同步状态
//                                  if ($n_good->status != 1) {
                                $commodity_update['status'] = $n_good->status;
//                                  }
                                if (count($commodity_update) > 0) {
//                                                $commodity->update($commodity_update);
                                    \App\Plugin\ThirdDockManage\Model\Commodity::query()->where('id', $commodity->id)
                                        ->update($commodity_update);
                                    $ebIds[] = (int)$commodity->id;
                                }
                            }
                        }
                    }
                    $n_good->save();
                } else {//创建
                    Goods::query()->create($datum);
                }
//                        DB::commit();
            } catch (\Exception $e) {
                Plugin::log('ThirdDockManage', $e->getMessage());
//                        DB::rollBack();
            }
        }

        //同步改写了商品（价格/状态/信息等），广播给事件订阅方（如 EventBroadcast）
        if ($ebIds !== []) {
            $ebAction = 'sync';
            $ebBefore = null;
            hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
        }

//        $shelves_ids = Goods::query()->where('site_id', $site['id'])->where('status', 2)->pluck('id')->toArray();
//        if (count($shelves_ids) > 0) {
//            \App\Plugin\ThirdDockManage\Model\Commodity::query()->whereIn('dock_g_id', $shelves_ids)->update(['status' => 0]);
//        }
    }

    public function generateStep($site)
    {
        $class = "\App\Plugin\\{$site['type']}\Hook\Main";
        $dock = new $class();
        $plugin_status = $dock->checkStatus();
        if ($plugin_status) {
            $batch = 'WEB'.time().bin2hex(random_bytes(10));
            $step_res = $dock->allStep($batch, $site['account'], $site['password'], $site);
            if ($step_res) {
                TaskStore::saveSite($batch, (int)$site['id'], $site);
                return $site['id'].'###'.$batch;
            }
        }

        return false;
    }

    /**
     * @throws JSONException
     */
    public function aloneSite($site)
    {
        $class = "\App\Plugin\\{$site['type']}\Hook\Main";
        $dock = new $class();
        $plugin_status = $dock->checkStatus();
        if ($plugin_status) {
            $batch = 'WEB'.time().bin2hex(random_bytes(10));
            $step_res = $dock->allStep($batch, $site['account'], $site['password'], $site);
            if ($step_res) {
                $task_data = TaskStore::getTask($batch) ?? [];
                TaskStore::forget($batch);
                if (count($task_data) > 0) {
                    $good_ids = [];
                    $all_ids = $task_data['all_ids'] ?? [];
                    foreach ($task_data['step'] as $task_datum) {
                        $method = $task_datum['type'];
                        $res = $dock->$method([
                            'current' => $task_datum,
                            'data' => $task_data,
                        ], $site['account'], $site['password'], $site);
                        $goods = $res['params'] ?? [];
                        if (count($goods) > 0) {
                            $this->dealSiteGoods($site, $goods);
                            foreach ($goods as $good) {
                                $good_ids[] = $good['c_id'];
                            }
                        }
                        if (count($all_ids) > 0 && isset($res['deal_ids'])){
                            $all_ids = array_diff($all_ids, $res['deal_ids']);
                        }
                    }
                    //Fill the leak
                    if (count($all_ids) > 0) {
                        foreach ($all_ids as $all_id) {
                            $id_res = $dock->dealId([
                                'current' => ['data' => $all_id],
                                'data' => $task_data,
                            ], $site['account'], $site['password'], $site);
                            $goods = $id_res['params'] ?? [];
                            if (count($goods) > 0) {
                                $this->dealSiteGoods($site, $goods);
                                foreach ($goods as $good) {
                                    $good_ids[] = $good['c_id'];
                                }
                            }
                        }
                    }
                    if (count($good_ids) > 0) {
                        Goods::query()->where('site_id', $site['id'])->whereNotIn('c_id', $good_ids)
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
//                    Plugin::delCache($site['type'], 'task', $batch, $_SERVER['third_dock_mode'] ?? false);
                }
            }
//            $goods = $dock->getAllGoods($site['account'], $site['password'], $site);
//            if ($goods['status_code'] == 200) {
//                $this->dealSiteGoods($site, $goods['data']);
//            }
        }

        return $site['name'];
    }

    public function cloneDeal($rule, &$category_array, $site_class, $site_domain, $plugin_config)
    {
        $query = Goods::query();
        $query->where('status', 1);
        if (filled($rule->site_ids)) {
            $site_ids = explode(',', $rule->site_ids);
            $query->whereIn('site_id', $site_ids);
        }
        if (filled($rule->categories)) {
            $categories = explode('@#@', $rule->categories);
            $query->whereIn('category', $categories);
        }
        if (filled($rule->good_names)) {
            $good_names = explode('@#@', $rule->good_names);
            $query->where(function ($q) use ($good_names) {
                foreach ($good_names as $k => $good_name) {
                    if ($k == 0) {
                        $q->where('name', 'like', '%'.$good_name.'%');
                    } else {
                        $q->orWhere('name', 'like', '%'.$good_name.'%');
                    }
                }
            });
        }
        $goods = $query->get();
        if ($goods) {
            $auto_class = $rule->auto_class;
            $settings = unserialize($rule->settings);
            if ($settings === false) {
                $settings = [];
            }
            $exclude_good_names = [];
            if (isset($settings['exclude_good_names']) && filled($settings['exclude_good_names'])) {
                $exclude_good_names = preg_split('/[\r\n]+/s', $settings['exclude_good_names']);
            }
            foreach ($goods as $good) {
                if ($this->contains($good->name, $exclude_good_names)) {
                    continue;
                }
                $this->cloneGood($good, $settings, $auto_class, $category_array, $site_class, $site_domain, $plugin_config);
            }
        }
    }

    private function cloneGood(Goods $good, $settings, $auto_class, &$category_array, $site_class, $site_domain, $plugin_config)
    {
        // DB::beginTransaction();
        try {
            $name = $good->name;
            if ($auto_class) {
                if (isset($category_array[$good->category])) {
                    $category_id = $category_array[$good->category];
                } else {
                    $insert_class = [
                        'name' => $good->category,
                        'icon' => '',
                        'sort' => 1,
                        'status' => 1,
                        'hide' => 0,
                        'create_time' => Carbon::now()->toDateTimeString()
                    ];
                    if (isset($site_class[$good->site_id][$good->category])) {
                        //todo:图床
                        $insert_class['icon'] = $site_class[$good->site_id][$good->category]['img_url'];
                    }
                    $res_id = Category::query()->insertGetId($insert_class);
                    $category_id = $category_array[$insert_class['name']] = $res_id;
                }
            } else {
                $category_id = $settings['category_id'];
            }
            if (empty($category_id)) {
                return;
            }
//            $num = Commodity::query()->where('category_id', $category_id)->where('name', $name)
//                ->where('dock_g_id', $good->id)->count();
            $exist_data = Commodity::query()->where('dock_g_id', $good->id)->first(['id', 'dock_g_id']);
            $settings['cover'] = $settings['cover'] ?? 0;
            if ($exist_data && $settings['cover'] == 0) {
                return;
            }
            //generate
            $commodity = new \App\Plugin\ThirdDockManage\Model\Commodity();
            $commodity->category_id = $category_id;
            $commodity->name = $good->name;
            $commodity->description = $good->content;

            if (filled($settings['content_replace'])) {//文本替换
                $replaces = explode('$$$', $settings['content_replace']);
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
            if ($settings['use_upload'] == 1 || ($settings['use_upload'] == 2 && $plugin_config['upload'] == 1)) {//图床
                preg_match_all('/<img[\s\S]+?src=[\'\"](.+?)[\'\"][\s\S\>]?/', $commodity->description, $match);
                $list = array_values(array_unique((array)$match[1]));
                if (count($list) > 0) {
                    foreach ($list as $e) {
                        $res_url = $e;
                        if (!$this->startsWith($e, 'http')) {
                            $res_url = $site_domain[$good->site_id] . $e;
                        }
                        $res_url = $this->uploadImage($res_url, $plugin_config);
                        $commodity->description = str_replace($e, $res_url, $commodity->description);
                    }
                }
            }

            //适配，转2位小数
//            $good->price = round($good->price, 2);
            $price = $this->scToStr($good->price);
            $commodity->factory_price = $price;
            $current_price = $this->calcPrice($price, $settings['mode'], $settings['mode_value'], $settings['lucky_decimal']);
            $commodity->price = $commodity->user_price = $current_price;
            if (filled($good->img)) {
                $img = $good->img;
                //图床
                if (strlen($img) > 250 && $plugin_config['upload'] == 1) {
                    $res_url = $this->uploadImage($img, $plugin_config);
                    $commodity->cover = $res_url;
                } elseif ($settings['use_upload'] == 1 || ($settings['use_upload'] == 2 && $plugin_config['upload'] == 1)) {
                    $res_url = $this->uploadImage($img, $plugin_config);
                    $commodity->cover = $res_url;
                } else {
                    $commodity->cover = $img;
                }
            } else {
                $commodity->cover = '';
            }
            $commodity->status = $good->status == 1 ? 1 : 0;
            $commodity->owner = 0;
            $commodity->create_time = Carbon::now('PRC')->toDateTimeString();
            $commodity->api_status = $settings['api_status'];
            $commodity->code = strtoupper(Str::generateRandStr(16));
            $commodity->delivery_way = 1;
            $commodity->coupon = 0;
            $commodity->widget = $good->attach;
            $commodity->minimum = $good->min_num;
            $commodity->maximum = $good->max_num;

            $commodity->dock_g_id = $good->id;
            $commodity->dock_mode = $settings['mode'];
            $commodity->dock_mode_value = $settings['mode_value'];
            $commodity->dock_lucky_decimal = $settings['lucky_decimal'];
            $commodity->dock_sync_price = $settings['sync_price'];
            $commodity->dock_sync_content = $settings['sync_content'];
            $commodity->dock_sync_now = $settings['sync_now'];
            $commodity->dock_sync_title = $settings['sync_title'];
            $commodity->only_user = $settings['only_user'];
            $commodity->dock_attach = serialize([
                'use_upload' => $settings['use_upload'],
                'content_replace' => $settings['content_replace']
            ]);

            if ($exist_data) {
                Commodity::query()->where('id', $exist_data->id)->update($commodity->toArray());
                //克隆覆盖了商品，广播给事件订阅方
                $ebArgs = [(int)$exist_data->id];
                $ebAction = 'sync';
                $ebBefore = null;
                hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebArgs, $ebAction, $ebBefore);
            } else {
                $commodity->save();
                $ebArgs = [(int)$commodity->id];
                $ebAction = 'create';
                $ebBefore = null;
                hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebArgs, $ebAction, $ebBefore);
            }

            // DB::commit();
        } catch (\Exception $e) {
            Plugin::log('ThirdDockManage', $e->getMessage());
            // DB::rollBack();
        }
    }
}

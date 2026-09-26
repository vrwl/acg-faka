<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Model\ManageLog;
use App\Plugin\WkDock\Model\Goods;
use App\Plugin\WkDock\Model\Sites;
use App\Plugin\WkDock\Traits\Help;
use App\Service\Query;
use App\Util\Plugin;
use App\Util\RichHtml;
use App\Util\Str;
use Carbon\Carbon;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

/**
 * 后台API：网课商品池
 */
#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Good extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    /**
     * 商品池列表
     * @return array
     * @throws JSONException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Goods::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $data = $this->query->get($get);

        $sites = Sites::withTrashed()->get(['id', 'name', 'rate'])->keyBy('id');
        $existIds = Commodity::query()->where('wk_g_id', '<>', 0)->pluck('wk_g_id')->toArray();
        foreach ($data['list'] as &$datum) {
            $site = $sites->get($datum['site_id']);
            $datum['site_name'] = $site->name ?? '';
            $datum['sale_price'] = round((float)$datum['price'] * (float)($site->rate ?? 1), 2);
            $datum['has_data'] = in_array($datum['id'], $existIds) ? 1 : 0;
        }

        return $this->json(data: $data);
    }

    /**
     * 单个商城商品的对接详情（只读展示用，不改任何数据）
     * @return array
     * @throws JSONException
     */
    public function link(): array
    {
        $commodityId = (int)($_POST['id'] ?? 0);
        if ($commodityId <= 0) {
            throw new JSONException("参数错误");
        }

        $commodity = Commodity::query()->find($commodityId, ['id', 'wk_g_id']);
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $gId = (int)$commodity->wk_g_id;
        if ($gId <= 0) {
            return $this->json(data: ['linked' => 0]);
        }

        $good = Goods::query()->find($gId, ['id', 'site_id', 'cid', 'name', 'status']);
        if (!$good) {
            return $this->json(data: ['linked' => 1, 'missing' => 1]);
        }

        $site = Sites::withTrashed()->find($good->site_id, ['id', 'name', 'status']);

        return $this->json(data: [
            'linked' => 1,
            'site_name' => $site ? (string)$site->name : '',
            'site_status' => $site ? (int)$site->status : -1,
            'course_name' => (string)$good->name,
            'cid' => (string)$good->cid,
            'good_status' => (int)$good->status,
        ]);
    }

    /**
     * 从平台同步商品（act=getclass，按 site_id+cid 去重）
     * @return array
     * @throws JSONException
     */
    public function sync(): array
    {
        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new JSONException("请选择要同步的站点");
        }

        $site = Sites::query()->where('status', 1)->find($siteId);
        if (!$site) {
            throw new JSONException("所选站点不存在或未启用（连接状态需为「正常」），无法同步");
        }

        $res = $this->getGoods($site);
        if ($res['code'] !== 1) {
            throw new JSONException("站点[{$site->name}]同步失败：{$res['msg']}");
        }

        //按 cid 去重，避免平台返回重复商品触发唯一键冲突
        $items = [];
        foreach ($res['data'] as $item) {
            $items[(string)$item['cid']] = $item;
        }

        $now = Carbon::now('PRC')->toDateTimeString();
        $rows = [];
        foreach ($items as $cid => $item) {
            $rows[] = [
                'site_id' => $site->id,
                'cid' => $cid,
                'name' => $item['name'],
                'fenlei' => $item['fenlei'],
                'price' => $item['price'],
                'content' => $item['content'],
                'status' => $item['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        //分批 upsert：一条 SQL 完成整批插入/更新，避免逐条写库导致同步超时
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->upsertGoods($chunk);
        }

        //平台上已下架/删除的商品标记为下架
        $cids = array_keys($items);
        $query = Goods::query()->where('site_id', $site->id);
        if ($cids) {
            $query->whereNotIn('cid', $cids);
        }
        $query->update(['status' => 0]);

        Plugin::setCache('WkDock', 'sync_good', 'last_sync_time', $now);
        ManageLog::log($this->getManage(), "[网课对接][同步]商品池");

        return $this->json(200, "站点[{$site->name}]同步成功：" . count($items) . " 个商品", ['time' => $now]);
    }

    /**
     * 批量写入商品池（按 site_id+cid 唯一键 upsert，MySQL ON DUPLICATE KEY UPDATE）
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function upsertGoods(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $model = new Goods();
        $connection = $model->getConnection();
        $table = $connection->getTablePrefix() . $model->getTable();
        $columns = ['site_id', 'cid', 'name', 'fenlei', 'price', 'content', 'status', 'created_at', 'updated_at'];
        $updates = ['name', 'fenlei', 'price', 'content', 'status', 'updated_at'];

        $groups = [];
        $bindings = [];
        foreach ($rows as $row) {
            $groups[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES " . implode(',', $groups);
        $sql .= " ON DUPLICATE KEY UPDATE " . implode(',', array_map(function (string $column): string {
                return "`{$column}`=VALUES(`{$column}`)";
            }, $updates));

        $connection->insert($sql, $bindings);
    }

    /**
     * 将选中商品生成为商城商品（写入 commodity，关联 wk_g_id）
     * @return array
     * @throws JSONException
     */
    public function generate(): array
    {
        $map = $_POST;
        $ids = (string)($map['ids'] ?? '');
        if ($ids === '') {
            throw new JSONException("请先选择要生成商品的数据");
        }
        $categoryId = (int)($map['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new JSONException("请选择商品分类");
        }
        $cover = trim((string)($map['cover'] ?? ''));
        if ($cover === '') {
            throw new JSONException("请上传商品图片");
        }
        //加价模式：0=固定金额（元），1=百分比（%）
        $premiumType = (int)($map['premium_type'] ?? 0);
        if (!in_array($premiumType, [0, 1], true)) {
            $premiumType = 0;
        }
        $premium = (float)($map['premium'] ?? 0);
        //开关：开=直接上架，关=以「下架」状态入库
        $shelves = in_array((string)($map['shelves'] ?? ''), ['1', 'true'], true) ? 1 : 0;
        $stock = (int)($map['stock'] ?? 0);
        if ($stock <= 0) {
            $stock = 999999;
        }

        //查询目标商品
        $query = Goods::query()->whereIn('id', explode(',', $ids));
        $goods = $query->get();
        if ($goods->isEmpty()) {
            throw new JSONException("未找到选中的商品");
        }

        $sites = Sites::withTrashed()->get()->keyBy('id');
        $existMap = Commodity::query()->where('wk_g_id', '<>', 0)->pluck('id', 'wk_g_id')->toArray();

        $success = 0;
        $skip = 0;
        $error = 0;
        foreach ($goods as $good) {
            $site = $sites->get($good->site_id);
            if (!$site) {
                $error++;
                continue;
            }
            //已生成过则跳过
            if (isset($existMap[$good->id])) {
                $skip++;
                continue;
            }

            try {
                $commodity = new Commodity();
                $commodity->category_id = $categoryId;
                $commodity->name = $good->name;
                $commodity->description = $this->buildDescription((string)$good->content);
                $commodity->factory_price = round((float)$good->price, 2);
                //售价 = 成本价 × 站点倍率，再按加价模式加价
                $basePrice = round((float)$good->price * (float)$site->rate, 2);
                $salePrice = $premiumType === 1 ? $basePrice * (1 + $premium / 100) : $basePrice + $premium;
                $commodity->price = $commodity->user_price = round($salePrice, 2);
                $commodity->cover = $cover;
                $commodity->status = $shelves;
                $commodity->owner = 0;
                $commodity->create_time = Carbon::now('PRC')->toDateTimeString();
                $commodity->api_status = 0;
                $commodity->code = strtoupper(Str::generateRandStr(16));
                $commodity->delivery_way = 1;
                $commodity->coupon = 0;
                $commodity->minimum = 1;
                $commodity->maximum = 0;
                $commodity->only_user = 0;
                $commodity->stock = $stock;
                $commodity->wk_g_id = $good->id;
                //购买表单：学校/账号/密码 + 下单课程(courses 存选中课程的 JSON，前端 JS 隐藏并自动填入)
                $commodity->widget = json_encode([
                    ['type' => 'text', 'name' => 'school', 'cn' => '学校全称', 'placeholder' => '请输入学校全称', 'regex' => '', 'error' => ''],
                    ['type' => 'text', 'name' => 'user', 'cn' => '学生账号', 'placeholder' => '请输入学生账号', 'regex' => '', 'error' => ''],
                    ['type' => 'password', 'name' => 'pass', 'cn' => '学生密码', 'placeholder' => '请输入学生密码', 'regex' => '', 'error' => ''],
                    ['type' => 'text', 'name' => 'courses', 'cn' => '下单课程', 'placeholder' => '', 'regex' => '', 'error' => ''],
                ], JSON_UNESCAPED_UNICODE);
                $commodity->save();
                $success++;
            } catch (\Throwable $e) {
                Plugin::log('WkDock', '生成商品失败[' . $good->id . ']：' . $e->getMessage());
                $error++;
            }
        }

        ManageLog::log($this->getManage(), "[网课对接][生成]商品，成功：{$success}，跳过：{$skip}，失败：{$error}");
        return $this->json(200, "生成完成，成功：{$success}，已存在跳过：{$skip}，失败：{$error}");
    }

    /**
     * 检查已生成的商城商品与上游是否一致（名称/描述/成本价）
     * @return array
     */
    public function check(): array
    {
        $sites = Sites::withTrashed()->get(['id', 'name', 'rate'])->keyBy('id');
        $list = [];
        $checked = 0;

        Commodity::query()
            ->where('wk_g_id', '<>', 0)
            ->select(['id', 'name', 'description', 'price', 'factory_price', 'wk_g_id'])
            ->chunkById(300, function ($commodities) use (&$list, &$checked, $sites) {
                $goods = Goods::query()->whereIn('id', $commodities->pluck('wk_g_id')->toArray())
                    ->get(['id', 'site_id', 'name', 'price', 'content'])->keyBy('id');

                foreach ($commodities as $commodity) {
                    $good = $goods->get($commodity->wk_g_id);
                    if (!$good) {
                        continue;
                    }
                    $checked++;

                    $diff = [];
                    if ((string)$good->name !== (string)$commodity->name) {
                        $diff[] = 'name';
                    }
                    if ($this->buildDescription((string)$good->content) !== (string)$commodity->description) {
                        $diff[] = 'description';
                    }
                    //按「分」比较，避免浮点误差误判
                    if ((int)round((float)$good->price * 100) !== (int)round((float)$commodity->factory_price * 100)) {
                        $diff[] = 'price';
                    }
                    if (!$diff) {
                        continue;
                    }

                    $site = $sites->get($good->site_id);
                    $list[] = [
                        'g_id' => $good->id,
                        'commodity_id' => $commodity->id,
                        'site_name' => $site->name ?? '',
                        'rate' => (float)($site->rate ?? 1),
                        'name' => (string)$good->name,
                        'cost_old' => round((float)$commodity->factory_price, 2),
                        'cost_new' => round((float)$good->price, 2),
                        'sale_old' => round((float)$commodity->price, 2),
                        'diff' => $diff,
                    ];
                }
            });

        return $this->json(data: ['list' => $list, 'checked' => $checked, 'diff' => count($list)]);
    }

    /**
     * 用上游最新数据批量更新已生成的商城商品（名称/描述/成本价/售价）
     * @return array
     * @throws JSONException
     */
    public function update(): array
    {
        $ids = (string)($_POST['ids'] ?? '');
        if ($ids === '') {
            throw new JSONException("请先选择要更新的商品");
        }
        //加价模式：0=固定金额（元），1=百分比（%）
        $premiumType = (int)($_POST['premium_type'] ?? 0);
        if (!in_array($premiumType, [0, 1], true)) {
            $premiumType = 0;
        }
        $premium = (float)($_POST['premium'] ?? 0);

        $goods = Goods::query()->whereIn('id', explode(',', $ids))->get();
        if ($goods->isEmpty()) {
            throw new JSONException("未找到选中的商品");
        }

        $sites = Sites::withTrashed()->get(['id', 'rate'])->keyBy('id');
        $commodities = Commodity::query()->whereIn('wk_g_id', $goods->pluck('id')->toArray())
            ->get()->keyBy('wk_g_id');

        $success = 0;
        $error = 0;
        foreach ($goods as $good) {
            $commodity = $commodities->get($good->id);
            $site = $sites->get($good->site_id);
            if (!$commodity || !$site) {
                $error++;
                continue;
            }

            try {
                //售价 = 上游最新成本价 × 站点倍率，再按加价模式加价
                $basePrice = round((float)$good->price * (float)$site->rate, 2);
                $salePrice = $premiumType === 1 ? $basePrice * (1 + $premium / 100) : $basePrice + $premium;
                $commodity->name = $good->name;
                $commodity->description = $this->buildDescription((string)$good->content);
                $commodity->factory_price = round((float)$good->price, 2);
                $commodity->price = $commodity->user_price = round($salePrice, 2);
                $commodity->save();
                $success++;
            } catch (\Throwable $e) {
                Plugin::log('WkDock', '更新商品失败[' . $good->id . ']：' . $e->getMessage());
                $error++;
            }
        }

        ManageLog::log($this->getManage(), "[网课对接][更新]商品，成功：{$success}，失败：{$error}");
        return $this->json(200, "更新完成，成功：{$success}，失败：{$error}");
    }

    /**
     * 商品详情：纯文本包裹 <p>，含 HTML 原样保留
     */
    private function buildDescription(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '<p>下单后请在购买页面填写学校全称、学生账号与密码，并勾选需要刷的课程。</p>';
        }
        if (strip_tags($content) === $content) {
            //无 HTML 标签：转义并换行
            return '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES)) . '</p>';
        }
        //上游详情属于不可信第三方 HTML：入库前过一遍核心净化器，保留排版、剥掉脚本/事件/外连，
        //否则上游（或能改动上游商品详情的人）可以把脚本注入到商城商品详情页
        return RichHtml::sanitize($content, false);
    }
}

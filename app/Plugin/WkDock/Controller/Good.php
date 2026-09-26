<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\WkDock\Model\Sites;
use App\Util\Plugin;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

/**
 * 后台视图：网课商品池
 */
#[Interceptor(ManageSession::class)]
class Good extends ManagePlugin
{
    /**
     * 商品池列表页
     * @throws ViewException
     * @throws JSONException
     */
    public function index(): string
    {
        require_once BASE_PATH . 'app/View/Admin/Helper.php';

        $lastSyncTime = Plugin::getCache('WkDock', 'sync_good', 'last_sync_time');
        if (!$lastSyncTime) {
            $lastSyncTime = '无';
        }

        $sites = [];
        foreach (Sites::query()->get(['id', 'name']) as $site) {
            $sites[] = ['id' => $site->id, 'name' => $site->name];
        }

        //同步下拉用：仅启用站点
        $activeSites = [];
        foreach (Sites::query()->where('status', 1)->get(['id', 'name']) as $site) {
            $activeSites[] = ['id' => $site->id, 'name' => $site->name];
        }

        return $this->render(title: '商品采集-网课对接', template: 'Good.html', data: [
            'last_sync_time' => $lastSyncTime,
            'sites' => json_encode($sites, JSON_UNESCAPED_UNICODE),
            'active_sites' => json_encode($activeSites, JSON_UNESCAPED_UNICODE),
        ], controller: true);
    }
}

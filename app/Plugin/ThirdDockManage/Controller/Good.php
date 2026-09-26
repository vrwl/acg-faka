<?php
namespace App\Plugin\ThirdDockManage\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Util\Plugin;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

/**
 * Class Good
 * @package App\Plugin\ThirdDockManage\Controller
 */
#[Interceptor(ManageSession::class)]
class Good extends ManagePlugin
{
    use Help;

    /**
     * @throws ViewException|\Kernel\Exception\JSONException
     */
    public function index(): string
    {
        require_once BASE_PATH.'app/View/Admin/Helper.php';
        $last_sync_time = Plugin::getCache('ThirdDockManage', 'sync_good', 'last_sync_time');
        if (!$last_sync_time) {
            $last_sync_time = '无';
        }

        $sites = [];
        $site_lists = Sites::query()->get(['name', 'id']);
        foreach ($site_lists as $site_list) {
            $sites[] = ['id' => $site_list->id, 'name' => $site_list->name];
        }

        return $this->render(title: '商品采集-第三方对接管理', template: 'Good.html', data: [
            'last_sync_time' => $last_sync_time,
            'sites' => json_encode($sites),
        ], controller: true);
    }
}

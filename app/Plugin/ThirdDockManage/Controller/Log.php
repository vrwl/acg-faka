<?php
namespace App\Plugin\ThirdDockManage\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

/**
 * Class Site
 * @package App\Plugin\ThirdDockManage\Controller
 */
#[Interceptor(ManageSession::class)]
class Log extends ManagePlugin
{
    /**
     * @throws ViewException
     */
    public function index(): string
    {
        require_once BASE_PATH.'app/View/Admin/Helper.php';
        $sites = [];
        $site_lists = Sites::query()->get(['name', 'id']);
        foreach ($site_lists as $site_list) {
            $sites[] = ['id' => $site_list->id, 'name' => $site_list->name];
        }

        return $this->render(title: '对接日志-第三方对接管理', template: 'Log.html', data: [
            'sites' => json_encode($sites),
        ], controller: true);
    }
}

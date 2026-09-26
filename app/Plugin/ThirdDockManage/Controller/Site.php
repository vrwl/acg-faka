<?php
namespace App\Plugin\ThirdDockManage\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\ThirdDockManage\Traits\Help;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

/**
 * Class Site
 * @package App\Plugin\ThirdDockManage\Controller
 */
#[Interceptor(ManageSession::class)]
class Site extends ManagePlugin
{
    use Help;

    /**
     * @throws ViewException
     * @throws JSONException
     */
    public function index(): string
    {
        require_once BASE_PATH.'app/View/Admin/Helper.php';
        $ext = $this->getExt();
        $select = [];
        foreach ($ext as $k => $item) {
            $select[] = ['id' => $k, 'name' => $item['short_name']];
        }

        return $this->render(title: '对接站点-第三方对接管理', template: 'Site.html', data: [
            'select' => json_encode($select),
        ], controller: true);
    }

    public function class()
    {
        require_once BASE_PATH.'app/View/Admin/Helper.php';
        $id = $_GET['id'];
        if (filled($id) && is_numeric($id)) {
            return $this->render(title: '对接站点-第三方对接管理', template: 'SiteClass.html', data: [
                'id' => $id,
            ], controller: true);
        } else {
            exit("<script>history.back();</script>");
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

/**
 * 后台视图：网课对接站点管理
 */
#[Interceptor(ManageSession::class)]
class Site extends ManagePlugin
{
    /**
     * 站点列表页
     * @throws ViewException
     * @throws JSONException
     */
    public function index(): string
    {
        require_once BASE_PATH . 'app/View/Admin/Helper.php';

        return $this->render(title: '网课站点-网课对接', template: 'Site.html', data: [], controller: true);
    }
}

<?php
namespace App\Plugin\ThirdDockManage\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Model\Category;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

/**
 * rule
 * @package App\Plugin\ThirdDockManage\Controller
 */
#[Interceptor(ManageSession::class)]
class Rule extends ManagePlugin
{
    /**
     * @throws ViewException
     */
    public function index(): string
    {
        require_once BASE_PATH.'app/View/Admin/Helper.php';
        $sites = $categories = [];
        $site_lists = Sites::query()->get(['name', 'id']);
        foreach ($site_lists as $site_list) {
            $sites[] = ['value' => $site_list->id, 'name' => $site_list->name];
        }
        $category_lists = Category::query()->orderBy('sort')->get(['name', 'id']);
        foreach ($category_lists as $category_list) {
            $categories[] = ['value' => $category_list->name, 'name' => $category_list->name];
        }

        return $this->render(title: '克隆规则-第三方对接管理', template: 'Rule.html', data: [
            'sites' => json_encode($sites),
            'categories' => json_encode($categories),
        ], controller: true);
    }
}

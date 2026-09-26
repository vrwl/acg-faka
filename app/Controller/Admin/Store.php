<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\ViewException;

#[Interceptor(ManageSession::class)]
class Store extends Manage
{

    /**
     * @throws ViewException
     */
    public function index(): string
    {
        return $this->render("店铺共享", "Shared/Store.html");
    }

    /**
     * 加价模板：批量给商品套用统一定价规则（issue #798）
     * @return string
     * @throws ViewException
     */
    public function priceTemplate(): string
    {
        return $this->render("加价模板", "Shared/PriceTemplate.html");
    }
}

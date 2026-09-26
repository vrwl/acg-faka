<?php
namespace App\Plugin\ThirdDockManage\Controller\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Plugin\ThirdDockManage\Model\Goods;
use App\Plugin\ThirdDockManage\Model\Logs;
use App\Plugin\ThirdDockManage\Model\Sites;
use App\Plugin\ThirdDockManage\Traits\Help;
use App\Service\Query;
use App\Util\Plugin;
use App\Util\Str;
use Carbon\Carbon;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use function Amp\asyncCall;

#[Interceptor([Waf::class, ManageSession::class], Interceptor::TYPE_API)]
class Log extends Manage
{
    use Help;

    #[Inject]
    private Query $query;

    /**
     * @return array
     * @throws JSONException
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(Logs::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $data = $this->query->get($get);

        $sites = Sites::withTrashed()->pluck('name', 'id')->toArray();
        foreach ($data['list'] as &$datum) {
            $datum['site_name'] = $sites[$datum['site_id']] ?? '';
            $datum['created_at'] = Carbon::parse($datum['created_at'])->setTimezone('PRC')->toDateTimeString();
        }

        return $this->json(data: $data);
    }
}

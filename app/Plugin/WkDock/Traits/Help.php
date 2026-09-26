<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Traits;

use App\Plugin\WkDock\Model\Logs;
use App\Plugin\WkDock\Model\Sites;
use App\Util\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 网课（古月）平台 API 封装
 *
 * 平台接口约定（见 docs/上游网课平台对接文档.md）：
 * - 基地址：{站点domain}/api.php?act=xxx，POST 表单，携带 uid + key 认证
 * - 下单 act=add 成功码为字符串 "0"（与其他接口的数字 1 不同，需特殊兼容）
 * - 查课 act=get 响应结构文档未明确，按 {code:1, data:[{id,name}]} 宽松解析
 * - 余额 act=getmoney 返回 {money:"128.50"}；仅 code=1 视为成功，其余一律视为连接失败（不降级探测）
 */
trait Help
{
    /**
     * 发送平台 API 请求
     * @param Sites $site 站点记录
     * @param string $act 接口动作（add/get/getclass/getmoney/chadan/budan/xgmm/zt/xq）
     * @param array $params 业务参数（不含认证参数）
     * @return array{code: int, msg: string, data: mixed, raw: array}
     */
    public function sendRequest(Sites $site, string $act, array $params = []): array
    {
        $uri = rtrim((string)$site->domain, '/') . '/api.php?act=' . $act;

        $form = array_merge([
            'uid' => (string)$site->account,
            'key' => (string)$site->password,
        ], $params);

        $raw = [];
        $msg = '';
        try {
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                //必须校验证书：请求体里带着站长的 uid/key 与买家的学生账号密码，
                //关掉校验等于把这些凭据暴露给中间人，可被窃听与篡改
                'verify' => true,
            ]);
            $response = $client->post($uri, ['form_params' => $form]);
            $body = (string)$response->getBody();
            $raw = json_decode($body, true) ?: [];
            if (!is_array($raw) || !isset($raw['code'])) {
                return ['code' => -1, 'msg' => '接口返回格式错误', 'data' => null, 'raw' => []];
            }
            $msg = (string)($raw['msg'] ?? '');
        } catch (GuzzleException $e) {
            Plugin::log('WkDock', "请求失败[{$act}]：" . $e->getMessage());
            return ['code' => -1, 'msg' => '网络请求失败：' . $e->getMessage(), 'data' => null, 'raw' => []];
        }

        //平台成功码约定：普通接口为 1；下单接口(act=add)为字符串 "0"
        //必须用 (string) 严格比较：$code == "0" 会把平台返回的整数 0（通用失败码，如「密钥错误」）误判为成功
        $code = $raw['code'];
        $ok = ((string)$code === '1');
        if ($act === 'add') {
            $ok = ((string)$code === '0' || (string)$code === '1');
        }
        if (!$ok && $msg !== '' && mb_strpos($msg, '成功') !== false
            && mb_strpos($msg, '未成功') === false && mb_strpos($msg, '不成功') === false) {
            $ok = true;
        }

        return [
            'code' => $ok ? 1 : -1,
            'msg' => $msg,
            'data' => $raw['data'] ?? null,
            'raw' => $raw,
        ];
    }

    /**
     * 查询站点余额（act=getmoney）
     * @return array{code: int, msg: string, data: array{balance: float}}
     */
    public function getBalance(Sites $site): array
    {
        $res = $this->sendRequest($site, 'getmoney');
        if ($res['code'] === 1) {
            return ['code' => 200, 'msg' => 'success', 'data' => ['balance' => (float)($res['raw']['money'] ?? 0)]];
        }
        return ['code' => 500, 'msg' => '连接失败', 'data' => ['balance' => 0]];
    }

    /**
     * 拉取平台商品列表（act=getclass）
     * @return array{code: int, msg: string, data: array<int, array{cid: string, name: string, fenlei: string, price: float, status: int, content: string}>}
     */
    public function getGoods(Sites $site): array
    {
        $res = $this->sendRequest($site, 'getclass');
        $list = $res['data'] ?? [];
        if ($res['code'] !== 1 || !is_array($list)) {
            return ['code' => $res['code'], 'msg' => $res['msg'] ?: '拉取商品失败', 'data' => []];
        }

        $goods = [];
        foreach ($list as $item) {
            if (!isset($item['cid'], $item['name'])) {
                continue;
            }
            $goods[] = [
                'cid' => (string)$item['cid'],
                'name' => (string)$item['name'],
                'fenlei' => (string)($item['fenlei'] ?? ''),
                'price' => (float)($item['price'] ?? 0),
                'status' => (int)($item['status'] ?? 0),
                'content' => (string)($item['content'] ?? ''),
            ];
        }
        return ['code' => 1, 'msg' => 'success', 'data' => $goods];
    }

    /**
     * 查询学生可选课程（act=get）
     * 文档未给出响应结构，按 {code:1, data:[{kcid,kcname}]} 宽松解析，兼容 id/name 键名
     * @param string $platform 平台ID（商品池记录的 cid，必传）
     * @return array{code: int, msg: string, data: array<int, array{id: string, name: string}>}
     */
    public function queryCourses(Sites $site, string $platform, string $school, string $user, string $pass): array
    {
        $res = $this->sendRequest($site, 'get', [
            'platform' => $platform,
            'school' => $school,
            'user' => $user,
            'pass' => $pass,
        ]);

        if ($res['code'] !== 1) {
            return ['code' => -1, 'msg' => $res['msg'] ?: '查询课程失败', 'data' => []];
        }

        //响应结构文档未明确：兼容 data 数组 / data.list 包装 / list 键
        $raw = $res['raw'];
        $list = $raw['data'] ?? $raw['list'] ?? null;
        if (is_array($list) && !array_is_list($list) && isset($list['list']) && is_array($list['list'])) {
            $list = $list['list'];
        }
        if (!is_array($list)) {
            Plugin::log('WkDock', "查课响应格式异常[platform={$platform}]，原始返回：" . json_encode($raw, JSON_UNESCAPED_UNICODE));
            return ['code' => -1, 'msg' => '课程数据格式异常，请联系站长处理', 'data' => []];
        }

        $courses = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['kcid'] ?? $item['id'] ?? '');
            $name = (string)($item['kcname'] ?? $item['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $courses[] = ['id' => $id, 'name' => $name];
        }
        if (count($courses) === 0) {
            Plugin::log('WkDock', "查课解析为空[platform={$platform}]，原始返回：" . json_encode($raw, JSON_UNESCAPED_UNICODE));
            return ['code' => 1, 'msg' => 'success', 'data' => []];
        }
        return ['code' => 1, 'msg' => 'success', 'data' => $courses];
    }

    /**
     * 向平台交单（act=add）
     * 注意：下单成功码为字符串 "0"，与其它接口不同
     * @param string $platform 平台ID（商品池记录的 cid，必传）
     * @return array{code: int, msg: string}
     */
    public function submitOrder(Sites $site, string $platform, string $school, string $user, string $pass, string $kcname, string $kcid): array
    {
        $res = $this->sendRequest($site, 'add', [
            'platform' => $platform,
            'school' => $school,
            'user' => $user,
            'pass' => $pass,
            'kcname' => $kcname,
            'kcid' => $kcid,
        ]);
        return ['code' => $res['code'], 'msg' => $res['msg'] ?: ($res['code'] === 1 ? '下单成功' : '下单失败')];
    }

    /**
     * 查询账号在上游平台的全部课程记录（act=chadan）
     * 按账号维度返回，包含该账号在其他店铺下单的记录
     * @param Sites $site 站点记录
     * @param string $username 下单账号
     * @return array{code: int, msg: string, data: array<int, array<string, string>>}
     */
    public function queryOrders(Sites $site, string $username): array
    {
        $res = $this->sendRequest($site, 'chadan', ['username' => $username]);
        if ($res['code'] !== 1) {
            return ['code' => -1, 'msg' => '查询失败', 'data' => []];
        }

        $list = $res['data'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        $orders = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $yid = (string)($item['id'] ?? '');
            if ($yid === '') {
                continue;
            }
            //remarks 形如「进度值|说明」，取竖线后的说明文案
            $remarks = (string)($item['remarks'] ?? '');
            $pos = strpos($remarks, '|');
            $remark = $pos === false ? '' : trim(substr($remarks, $pos + 1));

            //addtime 形如「2026-09-26 10:14:35--006800」，后缀为平台内部标记，展示时截断
            $addtime = (string)($item['addtime'] ?? '');
            $mark = strpos($addtime, '--');
            if ($mark !== false) {
                $addtime = substr($addtime, 0, $mark);
            }

            $orders[] = [
                'yid' => $yid,
                'name' => (string)($item['kcname'] ?? ''),
                'status' => (string)($item['status'] ?? ''),
                'process' => (string)($item['process'] ?? ''),
                'remark' => $remark,
                'school' => (string)($item['school'] ?? ''),
                'user' => (string)($item['user'] ?? ''),
                'platform' => (string)($item['ptname'] ?? ''),
                'addtime' => $addtime,
            ];
        }
        return ['code' => 1, 'msg' => 'success', 'data' => $orders];
    }

    /**
     * 补刷课程（act=budan）
     * @param Sites $site 站点记录
     * @param string $yid 上游订单YID
     * @return array{code: int, msg: string}
     */
    public function supplement(Sites $site, string $yid): array
    {
        return $this->orderAction($site, 'budan', ['id' => $yid]);
    }

    /**
     * 暂停课程（act=zt）
     * @param Sites $site 站点记录
     * @param string $yid 上游订单YID
     * @return array{code: int, msg: string}
     */
    public function pauseOrder(Sites $site, string $yid): array
    {
        return $this->orderAction($site, 'zt', ['id' => $yid]);
    }

    /**
     * 修改学生账号密码（act=xgmm）
     * @param Sites $site 站点记录
     * @param string $yid 上游订单YID
     * @param string $newPassword 新密码
     * @return array{code: int, msg: string}
     */
    public function changePassword(Sites $site, string $yid, string $newPassword): array
    {
        return $this->orderAction($site, 'xgmm', ['id' => $yid, 'xgmm' => $newPassword]);
    }

    /**
     * 订单操作类接口统一请求（补刷/暂停/改密）
     * @param Sites $site 站点记录
     * @param string $act 接口动作
     * @param array $params 业务参数
     * @return array{code: int, msg: string}
     */
    private function orderAction(Sites $site, string $act, array $params): array
    {
        $res = $this->sendRequest($site, $act, $params);
        return ['code' => $res['code'], 'msg' => $res['msg']];
    }

    /**
     * 写入对接日志（wk_logs 表 + runtime.log）
     */
    public function writeLog(int $orderId, int $siteId, string $act, array $params, array $result, string $remark = ''): void
    {
        try {
            $log = new Logs();
            $log->order_id = $orderId;
            $log->trade_no = '';
            $log->site_id = $siteId;
            $log->uri = rtrim((string)($result['uri'] ?? ''), '/');
            $log->parameter = json_encode($params, JSON_UNESCAPED_UNICODE);
            $log->result = json_encode($result, JSON_UNESCAPED_UNICODE);
            $log->remark = $remark;
            $log->save();
        } catch (\Throwable $e) {
            Plugin::log('WkDock', '日志写入失败：' . $e->getMessage());
        }
    }
}

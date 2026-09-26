<?php
namespace App\Plugin\ThirdDockManage\Traits;

use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Config as CFG;
use App\Model\Order;
use App\Model\User;
use App\Model\UserGroup;
use App\Service\Shared;
use App\Service\Shop;
use App\Util\Context;
use App\Util\Plugin;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use PHPMailer\PHPMailer\PHPMailer;

trait Help
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Shop $shop;

    #[Inject]
    private \App\Service\Order $order;

    /**
     * 同步互斥锁：CLI 定时任务（run.php）与线程管理器任务共用，防止双跑
     * @return resource|null 拿到锁返回文件句柄，已被占用返回 null
     */
    public function acquireSyncLock(string $name)
    {
        $dir = BASE_PATH . '/runtime/plugin/ThirdDockManage/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $fp = @fopen($dir . $name . '.lock', 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }
            return null;
        }
        return $fp;
    }

    public function releaseSyncLock($fp): void
    {
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 当前是否处于 Swoole 协程环境（线程管理器工作进程）
     */
    public static function inCoroutine(): bool
    {
        return class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() >= 0;
    }

    /**
     * 获取扩展信息
     * @throws JSONException
     */
    public function getExt(): array
    {
        $ext = Plugin::getCache('ThirdDockManage', 'ext', 'ext');
        $ext_name = Plugin::getCache('ThirdDockManage', 'ext', 'ext_name');

        if (empty($ext) || empty($ext_name)) {
            $installPath = BASE_PATH . "/app/Plugin/";
            $files = scandir($installPath);
            foreach ($files as $file) {
                if ($file == '.' || $file == '..') {
                    continue;
                }
                if (is_dir($installPath . '/' . $file) && $this->startsWith($file, 'ThirdDock') && $file != 'ThirdDockManage') {
                    $config_temp = getPluginConfig($file);
                    if (isset($config_temp['STATUS']) && $config_temp['STATUS'] == 1) {
                        $info_temp = \Kernel\Util\Plugin::getPlugin($file);
                        if ($this->startsWith($info_temp['NAME'], '第三方对接组件-')) {
                            $info_temp['short_name'] = str_replace('第三方对接组件-', '', $info_temp['NAME']);
                        } else {
                            $info_temp['short_name'] = $info_temp['NAME'];
                        }
                        $ext_name[] = $info_temp['short_name'];
                        $ext[$file] = $info_temp;
                    }
                }
            }
            if (filled($ext_name) && count($ext_name) > 0) {
                Plugin::setCache('ThirdDockManage', 'ext', 'ext_name', $ext_name);
                Plugin::setCache('ThirdDockManage', 'ext', 'ext', $ext);

                Plugin::log('ThirdDockManage', '加载的扩展组件有：'.implode(',', $ext_name));
            }
        }

        return $ext ?? [];
    }

    public function delViewRuntime()
    {
        //清缓存
        $view_runtime_path = __DIR__ . "/../../../../runtime/view/compile/";
        $del_runtime_file = 0;
        if (file_exists($view_runtime_path)) {
            $vrl = scandir($view_runtime_path);
            if ($vrl) {
                foreach ($vrl as $v) {
                    if (str_ends_with($v, '.html.php')) {
                        @unlink($view_runtime_path.$v);
                        $del_runtime_file++;
                    }
                }
            }
        }
        if ($del_runtime_file > 0) {
            Plugin::log('ThirdDockManage', '清理'.$del_runtime_file.'个页面缓存');
        }
    }

    /**
     *
     * @param $haystack
     * @param $needles
     * @return bool
     */
    public function startsWith($haystack, $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && substr($haystack, 0, strlen($needle)) === (string) $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $haystack
     * @param $needles
     * @return bool
     */
    public function endsWith($haystack, $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if (substr($haystack, -strlen($needle)) === (string) $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param  string  $haystack
     * @param  string|string[]  $needles
     * @return bool
     */
    public function contains($haystack, $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public function uploadImage(String $url, $config)
    {
        $uploadUrl = $config['uploadUrl'] ?? '';
        $uploadName = $config['uploadName'] ?? '';
        $uploadExcept = $config['uploadExcept'] ?? '';
        if (filled($uploadExcept)) {
            $except_urls = explode(',', $uploadExcept);
            if ($this->contains($url, $except_urls)) {
                return $url;
            }
        }
        if (filled($uploadUrl) && filled($uploadName)) {
            //判断根域名是否一致，一致跳过
            $upload_info = parse_url($uploadUrl);
            $now_info = parse_url($url);
            if ($upload_info['host'] == $now_info['host']) {
                $newUrl = $url;
            } else {
                $client = new Client(['timeout' => 60, 'verify' => false]);
                try {
                    $url_temp = parse_url($url);
                    if ($this->endsWith($url_temp['host'], ['http', 'https'])) {
                        $url = 'http:'.$url_temp['path'];
                    }

                    $newUrl = $url;
                    if ($uploadName == 'url') {
                        $query = [];
                        $parse = parse_url($uploadUrl);
                        if (isset($parse['query']) && filled($parse['query'])) {
                            parse_str($parse['query'], $query);
                        }
                        $query['url'] = $url;
                        $response = $client->request('POST', $uploadUrl, [
                            'query' => $query,
                        ]);
                        if ($response->getStatusCode() == 200) {
                            $body = $response->getBody()->getContents();
                            $res = json_decode($body, true);

                            $newUrl = $res['data']['url'] ?? $url;
                        }
                    } elseif ($uploadName == 'file') {
                        $response1 = $client->request('GET', $url, ['stream' => true]);
                        if (filled($response1->getBody())) {
                            $post = [];
                            $post[] = [
                                'name' => $uploadName,
                                'contents' => $response1->getBody()
                            ];
                            $response2 = $client->request('POST', $uploadUrl, [
                                'multipart' => $post,
                            ]);

                            if ($response2->getStatusCode() == 200) {
                                $body = $response2->getBody()->getContents();
                                $res = json_decode($body, true);

                                $newUrl = $res['data']['url'] ?? $url;
                            }
                        }
                    }
                } catch (GuzzleException $e) {
                    Plugin::log('ThirdDockManage', $e->getMessage());
                    $newUrl = '';
                }
            }
        } else {
            $newUrl = $url;
        }
        return $newUrl;
    }

    function downloadImage($url, $directory = '/assets/cache/images') {
        //检测目录是否存在，不存在则创建目录
        $dir = __DIR__.'/../../../../'. trim($directory, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // 获取文件名（包括扩展名）
        $filename = basename(parse_url($url, PHP_URL_PATH));

        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $client = new Client(['timeout' => 60, 'verify' => false]);
        try {
            $res = $client->get($url, [
                'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36'],
            ]);
            if (empty($ext)) {
                // 从文件类型中提取扩展名
                $header = $res->getHeader('content-type')[0];
                $content_type = '';
                if (preg_match('/Content-Type:\s+(.*?)\s+/i', $header, $matches)) {
                    $content_type = $matches[1];
                }
                // 从文件类型中提取扩展名
                switch (strtolower($content_type)) {
                    case 'image/jpeg':
                    case 'image/pjpeg':
                        $ext = 'jpg';
                        break;
                    case 'image/png':
                    case 'image/x-png':
                        $ext = 'png';
                        break;
                    case 'image/gif':
                        $ext = 'gif';
                        break;
                    case 'image/bmp':
                    case 'image/x-windows-bmp':
                        $ext = 'bmp';
                        break;
                    case 'image/webp':
                        $ext = 'webp';
                        break;
                    case 'image/svg+xml':
                        $ext = 'svg';
                        break;
                    // 添加其他可能的图像类型
                }
            }
            if (empty($ext)) {
                return false;
            }
            // 生成新的本地文件名
            $new_file_name = date("YmdHis") . mt_rand(1000000, 9999999) . '.' . $ext;
            $local_file_path = $dir . '/' . $new_file_name;

            // 下载图像并保存到本地
            file_put_contents($local_file_path, $res->getBody()->getContents());

            return $new_file_name;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * 发送邮件
     * @param $type
     * @param Commodity $commodity
     * @param Order $order
     * @param string $msg
     * @return void
     * @throws JSONException
     */
    public function sendEmail($type, Commodity $commodity, Order $order, string $msg = ''): void
    {
        try {
            $newOrder = Order::query()->find($order->id);
            $plugin_config = getPluginConfig('ThirdDockManage');
            $emailRemind = $plugin_config['emailRemind'] ?? 0;
            $emailSubmit = $plugin_config['emailSubmit'] ?? 0;
            $emailOver = $plugin_config['emailOver'] ?? 0;
            $emailAddress = $plugin_config['emailAddress'] ?? '';
            $email_manage = explode(',', $emailAddress);
            $emailPendSendManage = $plugin_config['emailPendSendManage'] ?? null;
            $emailPendSendUser = $plugin_config['emailPendSendUser'] ?? null;
            if ($emailRemind == 0) {
                return;
            }
            $shopName = Config::get("shop_name");
            $dock_order_status = $newOrder->dock_order_status;
            switch ($type) {
                case 'new_manage'://新单-管理
                    if ($newOrder->status == 1 && $emailSubmit == 1) {
                        //pending
                        if (filled($emailPendSendManage) && count($email_manage) > 0) {
                            foreach ($email_manage as $e) {
                                $this->send($e, "【{$shopName}】您有新订单了-" . $msg, $this->formatEmailHtml($emailPendSendManage, $commodity, $newOrder));
                            }
                        }
                    }
                    break;
                case 'new_user'://新单-用户
                    if ($newOrder->status == 1 && $emailSubmit == 1) {
                        $owner = $newOrder->owner;
                        $current_email = '';
                        if (filled($newOrder->contact) && preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/', $newOrder->contact)) {
                            $current_email = $newOrder->contact;
                        }
                        if (!empty($owner) && empty($current_email)) {
                            $user_info = User::query()->find($owner, ['id', 'email', 'username']);
                            if ($user_info && filled($user_info->email)) {
                                $current_email = $user_info->email;
                            }
                        }
                        if (filled($current_email)) {
                            $this->send($current_email, "【{$shopName}】已收到您的订单，请等候处理", $this->formatEmailHtml($emailPendSendUser, $commodity, $newOrder));
                        }
                    }
                    break;
                case 'over'://订单结束
                    if (in_array($dock_order_status, [2, 3, 4]) && $emailOver == 1) {
                        $owner = $newOrder->owner;
                        $current_email = '';
                        if (filled($newOrder->contact) && preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/', $newOrder->contact)) {
                            $current_email = $newOrder->contact;
                        }
                        if (!empty($owner) && empty($current_email)) {
                            $user_info = User::query()->find($owner, ['id', 'email', 'username']);
                            if ($user_info && filled($user_info->email)) {
                                $current_email = $user_info->email;
                            }
                        }
                        if (filled($current_email)) {
                            switch ($dock_order_status) {
                                case 2:
                                    $title = "【{$shopName}】您的订单已经处理完成！";
                                    $html = $plugin_config['emailCompleted'] ?? null;
                                    break;
                                case 3:
                                    $title = "【{$shopName}】您的订单处理失败！";
                                    $html = $plugin_config['emailFail'] ?? null;
                                    break;
                                case 4:
                                    $title = "【{$shopName}】您的订单有部分失败，请联系客服咨询！";
                                    $html = $plugin_config['emailFail'] ?? null;
                                    break;
                                default:
                                    $title = $html = null;
                            }
                            if (filled($title) && filled($html)) {
                                $this->send($current_email, $title, $this->formatEmailHtml($html, $commodity, $newOrder));
                            }
                        }
                    }
                    break;
                case 'camilo':
                    $owner = $newOrder->owner;
                    $current_email = '';
                    if (filled($newOrder->contact) && preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/', $newOrder->contact)) {
                        $current_email = $newOrder->contact;
                    }
                    if (!empty($owner) && empty($current_email)) {
                        $user_info = User::query()->find($owner, ['id', 'email', 'username']);
                        if ($user_info && filled($user_info->email)) {
                            $current_email = $user_info->email;
                        }
                    }
                    if (filled($current_email)) {
                        $title = "【{$shopName}】感谢您的购买，请查收您的收据！";
                        $html = $plugin_config['emailCardSendUser'] ?? null;
                        if (filled($title) && filled($html)) {
                            $this->send($current_email, $title, $this->formatEmailHtml($html, $commodity, $newOrder));
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            Plugin::log('ThirdDockManage', $e->getMessage());
        }
    }

    /**
     * 邮件HTML格式化
     * @param $html
     * @param Commodity $commodity
     * @param Order $order
     * @return String
     * @throws JSONException
     */
    protected function formatEmailHtml($html, Commodity $commodity, Order $order): String
    {
        $order_id = $order->trade_no;
        $created_at = Carbon::parse($order->create_time)->setTimezone('PRC')->toDateTimeString();
        $ord_info_data = [];
        if (filled($order->secret)) {
            $ord_info_data[] = str_replace(["\r\n", "\r", "\n"], '<br>', $order->secret);;
        }
        $widget = $order->widget;
        if (filled($widget)) {
            $ord_info_data[] = '以下是您的订单信息：';
            $widget_temp = json_decode($widget, true);
            foreach ($widget_temp as $item) {
                $ord_info_data[] = $item['cn'].'：'.$item['value'];
            }
        }
        if (count($ord_info_data) > 0) {
            $ord_info = implode('<br>', $ord_info_data);
        } else {
            $ord_info = '';
        }
        $ord_title = $commodity->name.' X '.$order->card_num;
        $product_name = $commodity->name;
        $buy_amount = $order->card_num;
        $ord_price = $order->amount;
        $web_name = Config::get("shop_name");
        $web_url = \App\Util\Client::getUrl();

        return str_replace(
            ["[order_id]", "[created_at]", "[ord_info]", "[ord_title]", "[product_name]", "[buy_amount]", "[ord_price]", "[web_name]", "[web_url]"],
            [$order_id, $created_at, $ord_info, $ord_title, $product_name, $buy_amount, $ord_price, $web_name, $web_url],
            $html
        );
    }

    /**
     * @param string $email
     * @param string $title
     * @param string $content
     * @return bool
     */
    public function send(string $email, string $title, string $content): bool
    {
        $mail = new PHPMailer();
        try {
            $config = json_decode(\App\Model\Config::get("email_config"), true);
            $shopName = CFG::get("shop_name");
            $secure = (int)$config['secure'] == 0 ? 'ssl' : 'tls';
            $mail->CharSet = 'UTF-8';
            $mail->IsSMTP();
            $mail->SMTPDebug = 0;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = $secure;  //tls/ssl
            $mail->Host = $config['smtp'];
            $mail->Port = $config['port'];
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SetFrom($config['username'], $shopName);
            $mail->AddAddress($email);
            $mail->Subject = $title;
            $mail->MsgHTML($content);
            $mail->Timeout = 15; //默认超时10秒钟
            $result = $mail->Send();
            $mail->clearAddresses();
        } catch (\Exception $e) {
            $mail->clearAddresses();
            return false;
        }

        if (!$result) {
            return false;
        }

        return true;
    }

    public function calcPrice($price, $mode, $mode_value, $lucky_decimal): float
    {
        if ($mode == 0) {//普通加价
            $current_price = (float) bcadd($price, $mode_value, 6);
        } elseif ($mode == 1) {//百分比加价
            $price_1 = bcmul($mode_value, $price, 6);
            $current_price = (float) bcadd($price, $price_1, 6);
        } elseif ($mode == 2) {//阶梯复杂加价
            $rule_temp = explode('|', $mode_value);
            $current_price = (float)bcadd($price, 0, 6);
            foreach ($rule_temp as $r) {
                $r_temp = explode('@', $r);
                if (count(array_filter($r_temp)) != 3) {
                    continue;
                }
                if ($price <= floatval($r_temp[0])) {
                    if ($r_temp[1] == '+') {
                        $current_price = (float)bcadd($price, floatval($r_temp[2]), 6);
                        break;
                    } elseif ($r_temp[1] == '%') {
                        $price_1 = bcmul(floatval($r_temp[2]), $price, 6);
                        $current_price = (float)bcadd($price, $price_1, 6);
                        break;
                    }
                }
            }
        } else {
            $current_price = (float)bcadd($price, 0, 6);
        }
        if (!empty($lucky_decimal)) {//吉利数
            $num_temp = explode('.', $current_price);
            if (isset($num_temp[1])) {
                $num_temp[0] = (int) $num_temp[0];
                if ($num_temp[1] > $lucky_decimal) {
                    $num_temp[0] = $num_temp[0] + 1;
                }
                $current_price = (float) bcadd($num_temp[0], $lucky_decimal, 6);
            } else {
                $current_price = (float) bcadd($current_price, $lucky_decimal, 6);
            }
        }
        return $current_price;
    }

    public function scToStr($num, $double = 8)
    {
        if (stripos($num, "e") !== false || stripos($num, "E") !== false) {
            $a = explode("e", strtolower($num));
            return bcmul($a[0], bcpow(10, $a[1], $double), $double);
        } else {
            return $num;
        }
    }

    public function changeStr($str)
    {
        if ($this->contains($str, ['@%2B@', '@%25@'])) {
            $str = urldecode($str);
        }

        return $str;
    }

    public function getUser(): ?\App\Model\User
    {
        return Context::get(\App\Consts\User::SESSION);
    }

    public function getUserGroup(): ?UserGroup
    {
        $user = $this->getUser();
        if (!$user) {
            return null;
        }
        return UserGroup::get($user->recharge);
    }
}

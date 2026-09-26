<?php
declare (strict_types=1);

namespace App\Plugin\WeChatPersonal\Hook;

use App\Util\Plugin;
use App\Util\QrCode;
use Kernel\Exception\JSONException;

class Config
{
    /**
     * @param string $pluginName
     * @param array $map
     * @return void
     * @throws JSONException
     */
    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::SAVE_CONFIG)]
    public function SAVE_CONFIG(string $pluginName, array $map): void
    {
        if ($pluginName !== "WeChatPersonal") {
            return;
        }

        if ($map['qrcode'] != "") {
            $qrcode = QrCode::parse(BASE_PATH . $map['qrcode']);
            if ($qrcode == "") {
                throw new JSONException("微信二维码解析失败");
            }
            Plugin::setConfig("WeChatPersonal", "wx_url", $qrcode, false);
        }
    }
}
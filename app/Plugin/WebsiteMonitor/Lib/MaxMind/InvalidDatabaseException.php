<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Lib\MaxMind;

/**
 * IP 库文件损坏或格式不符。
 *
 * 独立的异常类型，方便上层区分「库坏了」（要回滚 / 重新下载）与
 * 「查不到这个 IP」（正常情况，fail-open 放行）。
 */
class InvalidDatabaseException extends \RuntimeException
{
}

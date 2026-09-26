<?php
declare(strict_types=1);

namespace App\Plugin\WebsiteMonitor\Core;

/**
 * 带占位符的翻译。
 *
 * 框架的全局 lang() 签名是 `lang(?string $text, string $scene = "api")` ——
 * 第二个参数是**翻译场景**，不是替换变量。传数组进去会直接抛 TypeError，
 * 而且因为是在钩子里，站长看到的会是一个白屏错误页。
 *
 * 项目里原生的做法是字符串拼接（lang("最低充值") . " " . $n），
 * 但句子一长就没法翻译了 —— 语序在别的语言里往往要变。
 * 所以这里在 lang() 之上补一层占位符替换：翻译的是完整句子，变量最后再填进去。
 *
 *   Lang::t('已封禁 :ip（:d）', ['ip' => '1.2.3.4', 'd' => '1 小时'])
 */
final class Lang
{
    /**
     * @param array<string,string|int|float> $vars 占位符名 => 值（占位符写成 :名字）
     */
    public static function t(string $text, array $vars = [], string $scene = 'api'): string
    {
        if ($text === '') {
            return '';
        }
        $translated = function_exists('lang') ? (string)lang($text, $scene) : $text;
        if ($vars === []) {
            return $translated;
        }

        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = ':' . $key;
            $replace[] = is_scalar($value) || $value === null ? (string)$value : '';
        }
        //长占位符排前面，避免 :n 把 :name 截掉半截
        array_multisort(array_map('strlen', $search), SORT_DESC, $search, $replace);

        return str_replace($search, $replace, $translated);
    }
}

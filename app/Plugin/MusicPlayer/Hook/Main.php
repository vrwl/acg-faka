<?php

declare(strict_types=1);

namespace App\Plugin\MusicPlayer\Hook;

use App\Controller\Base\View\UserPlugin;
use Kernel\Annotation\Hook;
use Kernel\Annotation\Plugin;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;

class Main extends UserPlugin
{

    #[Plugin(state: Plugin::START)]
    public function start()
    {
        $config = getPluginConfig('MusicPlayer');
        $hasPlaylist = !empty($config['server']) && !empty(trim((string)($config['playId'] ?? '')));
        $hasCustom = !empty(trim((string)($config['custom'] ?? '')));
        if (!$hasPlaylist && !$hasCustom) {
            throw new JSONException("开启前请先配置歌单ID或自定义曲目！");
        }
    }

    #[Hook(point: \App\Consts\Hook::USER_GLOBAL_VIEW_HEADER)]
    public function header()
    {
        echo '<link rel="stylesheet" href="' . Plugin('MusicPlayer', 'View/Css/player.css') . '">';
    }

    #[Hook(point: \App\Consts\Hook::USER_VIEW_HEADER)]
    public function headerCommon()
    {
        $this->header();
    }

    /**
     * @throws \ReflectionException
     * @throws ViewException
     */
    #[Hook(point: \App\Consts\Hook::USER_GLOBAL_VIEW_BODY)]
    public function body()
    {
        $config = getPluginConfig('MusicPlayer');

        $volume = (float)($config['volume'] ?? 0.7);
        if ($volume <= 0 || $volume > 1) {
            $volume = 0.7;
        }
        $order = in_array($config['order'] ?? '', ['list', 'single', 'random']) ? $config['order'] : 'list';
        $tint = in_array($config['tint'] ?? '', ['auto', 'light', 'dark']) ? $config['tint'] : 'auto';
        $position = ($config['position'] ?? 'left') == 'right' ? 'right' : 'left';
        $startState = ($config['startState'] ?? 'dock') == 'mini' ? 'mini' : 'dock';

        $api = trim((string)($config['api'] ?? ''));

        $server = in_array($config['server'] ?? '', ['netease', 'tencent', 'kugou']) ? $config['server'] : 'netease';

        echo $this->render('', 'Player.tpl', [
            'mp' => [
                'api' => $api !== '' ? $api : '/plugin/MusicPlayer/api/meting',
                'server' => $server,
                'playlist' => '/plugin/MusicPlayer/api/playlist',
                'autoplay' => !empty($config['autoplay']) && $config['autoplay'] == '1',
                'order' => $order,
                'volume' => $volume,
                'lyric' => !isset($config['lrcType']) || $config['lrcType'] == '1',
                'stage' => !isset($config['stage']) || $config['stage'] == '1',
                'position' => $position,
                'tint' => $tint,
                'startState' => $startState,
            ],
            'mpJs' => Plugin('MusicPlayer', 'View/Js/player.js'),
        ]);
    }

    #[Hook(point: \App\Consts\Hook::USER_VIEW_BODY)]
    public function bodyCommon()
    {
        $this->body();
    }
}

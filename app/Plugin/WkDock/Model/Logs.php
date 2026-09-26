<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Model;

use Illuminate\Database\Eloquent\Model;

class Logs extends Model
{
    /**
     * @var string
     */
    protected $table = 'wk_logs';

    /**
     * @var bool
     */
    public $timestamps = true;

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'site_id' => 'integer'
    ];
}

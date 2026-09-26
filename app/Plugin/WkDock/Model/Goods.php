<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Model;

use Illuminate\Database\Eloquent\Model;

class Goods extends Model
{
    /**
     * @var string
     */
    protected $table = 'wk_goods';

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
        'site_id' => 'integer',
        'price' => 'float',
        'status' => 'integer'
    ];
}

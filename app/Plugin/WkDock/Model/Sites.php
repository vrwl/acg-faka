<?php
declare(strict_types=1);

namespace App\Plugin\WkDock\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sites extends Model
{
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'wk_sites';

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
        'status' => 'integer',
        'balance' => 'float',
        'rate' => 'float'
    ];
}

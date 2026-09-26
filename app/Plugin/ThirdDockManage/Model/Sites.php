<?php
namespace App\Plugin\ThirdDockManage\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sites extends Model
{
    use SoftDeletes;
    /**
     * @var string
     */
    protected $table = 'third_dock_sites';

    /**
     * @var bool
     */
    public $timestamps = true;

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer', 'balance' => 'float', 'pay_way' => 'integer'];
}

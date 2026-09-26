<?php
namespace App\Plugin\ThirdDockManage\Model;

use Illuminate\Database\Eloquent\Model;

class Goods extends Model
{
    /**
     * @var string
     */
    protected $table = 'third_dock_goods';

    /**
     * @var bool
     */
    public $timestamps = true;

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = ['price' => 'float'];
}

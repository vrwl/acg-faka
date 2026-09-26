<?php
namespace App\Plugin\ThirdDockManage\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rules extends Model
{
    /**
     * @var string
     */
    protected $table = 'third_dock_rules';

    /**
     * @var bool
     */
    public $timestamps = true;

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [];
}

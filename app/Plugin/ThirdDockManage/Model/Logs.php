<?php
namespace App\Plugin\ThirdDockManage\Model;

use Illuminate\Database\Eloquent\Model;

class Logs extends Model
{
    /**
     * @var string
     */
    protected $table = 'third_dock_logs';

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

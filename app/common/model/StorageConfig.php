<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class StorageConfig extends Model
{
    protected $table = 'img_storage_configs';

    protected $type = [
        'config' => 'json',
    ];
}

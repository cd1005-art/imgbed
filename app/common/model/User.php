<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class User extends Model
{
    protected $table = 'img_users';

    public static function findUser(int $id): ?self
    {
        return static::where('id', $id)->where('status', 1)->find() ?: null;
    }
}

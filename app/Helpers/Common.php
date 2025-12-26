<?php
namespace App\Helpers;

use App\Models\Role;

class Common
{
    public static function getRoleId($role_name)
    {
        $user_role_id = Role::where('role', $role_name)->value('id');
        return $user_role_id ?? null;
    }
    
}
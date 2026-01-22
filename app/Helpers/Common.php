<?php
namespace App\Helpers;

use App\Models\{Role,User};
use Illuminate\Support\Str;

class Common
{
    public static function getRoleId($role_name)
    {
        $user_role_id = Role::where('role', $role_name)->value('id');
        return $user_role_id ?? null;
    }
    
    public static function generateUniqueAffiliateId($name)
    {
        $slug = Str::slug($name, '');
        $originalSlug = $slug;
        $count = 1;

        while (User::where('affiliate_id', $slug)->exists()) {
            $slug = $originalSlug . $count;
            $count++;
        }

        return $slug;
    }
    
}
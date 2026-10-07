<?php

namespace Tests;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createAdmin(array $attributes): AdminUser
    {
        $trusted = array_intersect_key($attributes, array_flip(['role', 'permissions', 'is_active']));
        $admin = AdminUser::create(array_diff_key($attributes, $trusted));

        if ($trusted !== []) {
            $admin->forceFill($trusted)->save();
        }

        return $admin;
    }
}

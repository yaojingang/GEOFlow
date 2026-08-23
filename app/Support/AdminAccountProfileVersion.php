<?php

namespace App\Support;

use App\Models\Admin;

final class AdminAccountProfileVersion
{
    public static function for(Admin $admin): string
    {
        return hash('sha256', json_encode([
            'username' => (string) $admin->username,
            'display_name' => (string) $admin->display_name,
            'email' => (string) $admin->email,
            'role' => (string) $admin->role,
            'status' => (string) $admin->status,
        ], JSON_THROW_ON_ERROR));
    }
}

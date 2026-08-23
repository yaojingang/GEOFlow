<?php

use App\Models\Admin;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.tasks', function (Admin $admin): bool {
    return (string) ($admin->status ?? '') === 'active';
}, ['guards' => ['admin']]);

Broadcast::channel('admin.ai-workspace.{adminId}', function (Admin $admin, int $adminId): bool {
    return (int) $admin->id === $adminId && (string) $admin->status === 'active';
}, ['guards' => ['admin']]);

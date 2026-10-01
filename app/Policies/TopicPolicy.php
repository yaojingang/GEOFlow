<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Topic;

final class TopicPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function create(Admin $admin): bool
    {
        return $this->viewAny($admin);
    }

    public function view(Admin $admin, Topic $topic): bool
    {
        return $this->viewAny($admin) && ($topic->site_key === 'primary' || $admin->canManageProtectedWorkflows());
    }

    public function update(Admin $admin, Topic $topic): bool
    {
        return $this->view($admin, $topic) && ! $topic->trashed();
    }

    public function delete(Admin $admin, Topic $topic): bool
    {
        return $this->update($admin, $topic);
    }

    public function restore(Admin $admin, Topic $topic): bool
    {
        return $this->view($admin, $topic) && $topic->trashed();
    }

    public function publish(Admin $admin, Topic $topic): bool
    {
        return $this->update($admin, $topic);
    }

    public function approve(Admin $admin, Topic $topic): bool
    {
        return $this->update($admin, $topic);
    }
}

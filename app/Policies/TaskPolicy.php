<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.view') && $user->canAccessProject($task->project);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        if (! $user->canAccessProject($task->project)) {
            return false;
        }

        if ($user->hasPermission('tasks.assign') || $user->hasPermission('tasks.approve')) {
            return true;
        }

        if ($user->hasPermission('tasks.update') && (int) $task->assigned_to === (int) $user->id) {
            return true;
        }

        return false;
    }

    public function assign(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.assign') && $user->canAccessProject($task->project);
    }

    public function approve(User $user, Task $task): bool
    {
        return $user->hasPermission('tasks.approve') && $user->canAccessProject($task->project);
    }

    public function delete(User $user, Task $task): bool
    {
        if (! $user->canAccessProject($task->project)) {
            return false;
        }

        if ($user->hasPermission('tasks.assign') || $user->hasPermission('tasks.approve')) {
            return true;
        }

        if ($user->hasPermission('tasks.update') && (int) $task->assigned_to === (int) $user->id) {
            return true;
        }

        return false;
    }

    public function submit(User $user, Task $task): bool
    {
        if (! $user->hasPermission('tasks.submit') || ! $user->canAccessProject($task->project)) {
            return false;
        }

        // Staff can submit own; supervisors/admins with approve can force-submit
        if ($user->hasPermission('tasks.approve') || $user->hasPermission('tasks.assign')) {
            return true;
        }

        return (int) $task->assigned_to === (int) $user->id;
    }
}

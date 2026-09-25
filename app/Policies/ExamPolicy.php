<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exams.view') || $user->can('scores.enter');
    }

    public function view(User $user, Exam $exam): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('exams.manage');
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->can('exams.manage');
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $user->can('exams.manage');
    }
}

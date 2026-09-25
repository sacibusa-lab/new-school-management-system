<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;

/**
 * Thin permission-backed policies. Record-level rules that need real business
 * logic live in the service layer; these just answer "may this user do this?".
 */
class ApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('admissions.view');
    }

    public function view(User $user, Applicant $applicant): bool
    {
        // A student may always see their own applicant record.
        if ($applicant->user_id === $user->id) {
            return true;
        }

        return $user->can('admissions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('admissions.create');
    }

    public function update(User $user, Applicant $applicant): bool
    {
        return $user->can('admissions.update');
    }

    public function delete(User $user, Applicant $applicant): bool
    {
        return $user->can('admissions.delete');
    }

    public function approve(User $user): bool
    {
        return $user->can('admissions.approve');
    }
}

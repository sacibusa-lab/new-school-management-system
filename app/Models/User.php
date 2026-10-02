<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'avatar_path', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relationships */
    /* ------------------------------------------------------------------ */

    public function applicant(): HasOne
    {
        return $this->hasOne(Applicant::class);
    }

    /** Set when the account belongs to an admitted student. */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'form_teacher_id');
    }

    public function createdImports(): HasMany
    {
        return $this->hasMany(ScoreImport::class, 'uploaded_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return strtoupper(collect($parts)->take(2)->map(fn ($p) => str($p)->substr(0, 1)->value())->implode(''));
    }

    /** The role label shown in the top bar. */
    public function primaryRole(): string
    {
        return $this->getRoleNames()->first() ?? 'User';
    }

    /** Which landing page this account should be sent to after login. */
    public function homeRoute(): string
    {
        return match (true) {
            $this->hasRole('Super Admin'), $this->hasRole('Exam Officer') => 'admin.dashboard',
            $this->hasRole('Admission Officer') => 'admin.applicants.index',
            $this->hasRole('Teacher') => 'admin.scores.index',
            $this->hasRole('Bursar / Accounts') => 'admin.invoices.index',
            default => 'portal.dashboard',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

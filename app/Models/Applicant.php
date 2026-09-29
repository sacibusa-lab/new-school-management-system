<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use App\Enums\Gender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Applicant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'registration_number', 'first_name', 'middle_name', 'last_name',
        'gender', 'date_of_birth', 'nationality',
        'email', 'phone', 'address', 'city', 'state', 'lga', 'previous_school',
        'level_applied_for_id', 'academic_session_id', 'user_id',
        'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email', 'guardian_address',
        'photo_path', 'documents', 'status', 'admin_notes', 'submitted_at', 'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => ApplicantStatus::class,
            'documents' => 'array',
            'submitted_at' => 'datetime',
            'admitted_at' => 'datetime',
        ];
    }

    public function levelAppliedFor(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_applied_for_id');
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AdmissionDecision::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /* ------------------------------------------------------------------ */
    /* Accessors                                                           */
    /* ------------------------------------------------------------------ */

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->implode(' ');
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }

    /** Two-letter monogram for avatar placeholders. */
    public function getInitialsAttribute(): string
    {
        return strtoupper(
            str($this->first_name)->substr(0, 1)->value() . str($this->last_name)->substr(0, 1)->value()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Scopes                                                              */
    /* ------------------------------------------------------------------ */

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ApplicantStatus::ExamCompleted->value,
            ApplicantStatus::Shortlisted->value,
        ]);
    }

    public function scopeAdmitted(Builder $query): Builder
    {
        return $query->where('status', ApplicantStatus::Admitted->value);
    }

    public function scopeForSession(Builder $query, int $sessionId): Builder
    {
        return $query->where('academic_session_id', $sessionId);
    }

    /**
     * Candidates in the order a teacher reads a register: surname, then first name.
     *
     * One rule rather than a `orderBy` at every call site, because a mark sheet, a
     * scoresheet export and a candidate dropdown that disagree about who comes
     * first are the same list in three places, and somebody has to check them
     * against each other.
     *
     * The id is only a tie-break: two children can share a name, and a list that
     * reshuffles between page loads is worse than one that is merely imperfect.
     */
    public function scopeInNameOrder(Builder $query): Builder
    {
        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    /** Fuzzy search by name or registration number. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $term = trim($term);
        $like = '%' . str_replace('%', '\%', $term) . '%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('registration_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like])
                ->orWhere('phone', 'like', $like);
        });
    }

    public function isAdmitted(): bool
    {
        return $this->status === ApplicantStatus::Admitted;
    }
}

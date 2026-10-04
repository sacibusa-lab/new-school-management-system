<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student is created only when an applicant is admitted — this is the record
 * that the result portal and the fees portal both hang off.
 */
class Student extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_number', 'admission_number', 'applicant_id', 'user_id',
        'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
        'email', 'phone', 'address', 'photo_path',
        'level_id', 'school_class_id', 'academic_session_id',
        'guardian_name', 'guardian_phone', 'guardian_email',
        'status', 'results_portal_enabled', 'fees_portal_enabled',
        'admitted_at', 'admission_average',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => StudentStatus::class,
            'results_portal_enabled' => 'boolean',
            'fees_portal_enabled' => 'boolean',
            'admitted_at' => 'datetime',
            'admission_average' => 'decimal:2',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Money added to or taken off this child's bill, with a reason.
     *
     * Scoped by whoever asks to one session and one term: an adjustment is a decision about
     * a term, so a child collects them over a school life rather than carrying one price.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(StudentAdjustment::class);
    }

    /**
     * The bank account number their fees are paid into — one per child, opened with
     * the gateway on demand. See VirtualAccountService.
     */
    public function virtualAccount(): HasOne
    {
        return $this->hasOne(StudentVirtualAccount::class);
    }

    public function termResults(): HasMany
    {
        return $this->hasMany(TermResult::class);
    }

    /** What was decided about this student at the end of each session. */
    public function promotions(): HasMany
    {
        return $this->hasMany(StudentPromotion::class);
    }

    public function assessmentScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    /* ------------------------------------------------------------------ */

    public function getFullNameAttribute(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->implode(' ');
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(
            str($this->first_name)->substr(0, 1)->value().str($this->last_name)->substr(0, 1)->value()
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentStatus::Active->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('student_number', 'like', $like)
                ->orWhere('admission_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like]);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Money helpers */
    /* ------------------------------------------------------------------ */

    public function totalBilled(): float
    {
        return (float) $this->invoices()->where('status', '!=', InvoiceStatus::Cancelled->value)->sum('total');
    }

    public function totalPaid(): float
    {
        return (float) $this->invoices()->sum('amount_paid');
    }

    public function outstandingBalance(): float
    {
        return round($this->totalBilled() - $this->totalPaid(), 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->outstandingBalance() <= 0.009;
    }
}

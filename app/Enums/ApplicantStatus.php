<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of an applicant, from registration to a decision.
 *
 * registered -> exam_scheduled -> exam_completed -> shortlisted -> admitted
 *                                                              -> rejected
 */
enum ApplicantStatus: string
{
    use HasOptions;

    case Registered = 'registered';
    case ExamScheduled = 'exam_scheduled';
    case ExamCompleted = 'exam_completed';
    case Shortlisted = 'shortlisted';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::ExamScheduled => 'Exam Scheduled',
            self::ExamCompleted => 'Exam Completed',
            self::Shortlisted => 'Shortlisted',
            self::Admitted => 'Admitted',
            self::Rejected => 'Not Admitted',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Tailwind classes used by the status pill component. */
    public function badge(): string
    {
        return match ($this) {
            self::Registered => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::ExamScheduled => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            self::ExamCompleted => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::Shortlisted => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Admitted => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Withdrawn => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    /** Pipeline stage index, used by the applicant timeline UI (1-based). */
    public function stage(): int
    {
        return match ($this) {
            self::Registered => 1,
            self::ExamScheduled => 2,
            self::ExamCompleted => 3,
            self::Shortlisted => 4,
            self::Admitted, self::Rejected => 5,
            self::Withdrawn => 0,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Admitted, self::Rejected, self::Withdrawn], true);
    }
}

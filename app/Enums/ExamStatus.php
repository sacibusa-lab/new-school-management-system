<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ExamStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Ongoing = 'ongoing';
    case Marking = 'marking';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Ongoing => 'In Progress',
            self::Marking => 'Marking',
            self::AwaitingReview => 'Awaiting Review',
            self::Completed => 'Completed',
            self::Published => 'Published',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Scheduled => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Ongoing => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            self::Marking => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::AwaitingReview => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Published => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        };
    }

    public function acceptsScores(): bool
    {
        return ! in_array($this, [self::Draft, self::Published], true);
    }
}

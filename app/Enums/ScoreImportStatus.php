<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ScoreImportStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Parsing = 'parsing';
    case NeedsReview = 'needs_review';
    case Committed = 'committed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Parsing => 'Reading…',
            self::NeedsReview => 'Needs review',
            self::Committed => 'Committed',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Parsing => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::NeedsReview => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Committed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        };
    }
}

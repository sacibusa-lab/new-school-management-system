<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ScoreImportRowStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
    case Ignored = 'ignored';
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not reviewed',
            self::Matched => 'Matched',
            self::Ambiguous => 'Possible match',
            self::Unmatched => 'No student found',
            self::Duplicate => 'Duplicate entry',
            self::Invalid => 'Invalid score',
            self::Ignored => 'Skipped',
            self::Committed => 'Saved',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Matched => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Ambiguous => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Unmatched => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Duplicate => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            self::Invalid => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Ignored => 'bg-slate-100 text-slate-500 ring-slate-500/20',
            self::Committed => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        };
    }

    /** Rows that will actually be written when the operator commits. */
    public function isWritable(): bool
    {
        return in_array($this, [self::Matched, self::Ambiguous], true);
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Pending, self::Ambiguous, self::Unmatched, self::Duplicate, self::Invalid], true);
    }
}

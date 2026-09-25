<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StudentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Suspended = 'suspended';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Graduated => 'Graduated',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Suspended => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Graduated => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Withdrawn => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }
}

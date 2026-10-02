<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What was decided about a student at the end of a session.
 *
 * Three of these move the student on and one ends their time here. "Repeats the
 * year" is not the same as doing nothing: they sit the same class again, but in
 * the next session, which is why it is a decision rather than a blank.
 */
enum PromotionAction: string
{
    use HasOptions;

    case Promoted = 'promoted';
    case Repeated = 'repeated';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Promoted => 'Promoted',
            self::Repeated => 'Repeats the year',
            self::Graduated => 'Graduated',
            self::Withdrawn => 'Left the school',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Promoted => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Repeated => 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20',
            self::Graduated => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/20',
            self::Withdrawn => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
        };
    }

    /**
     * Whether the student carries on into the next session.
     *
     * A student who has left or finished keeps the session they were in, which is
     * what stops them turning up in next year's lists.
     */
    public function continues(): bool
    {
        return $this === self::Promoted || $this === self::Repeated;
    }

    /**
     * The status a student is left in when they do not carry on, or null when they
     * do. Only a decision that ends their time here touches their status: promoting
     * a suspended student must not quietly un-suspend them.
     */
    public function endingStatus(): ?StudentStatus
    {
        return match ($this) {
            self::Graduated => StudentStatus::Graduated,
            self::Withdrawn => StudentStatus::Withdrawn,
            default => null,
        };
    }
}

<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SmsStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Mocked = 'mocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Mocked => 'Simulated',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
            self::Sent => 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20',
            self::Failed => 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20',
            self::Mocked => 'bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20',
        };
    }

    /** Simulated messages are successes as far as the office is concerned. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Sent, self::Mocked], true);
    }
}

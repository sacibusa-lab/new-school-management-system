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
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-500/20',
            self::Sent => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            self::Mocked => 'bg-gold-50 text-gold-700 ring-gold-600/20',
        };
    }

    /** Simulated messages are successes as far as the office is concerned. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Sent, self::Mocked], true);
    }
}

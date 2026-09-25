<?php

namespace App\Models;

use App\Enums\SmsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsLog extends Model
{
    protected $fillable = [
        'status', 'provider', 'recipient', 'body', 'template_key',
        'subject_type', 'subject_id', 'provider_reference', 'response',
        'error', 'user_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDelivered($query)
    {
        return $query->whereIn('status', [SmsStatus::Sent->value, SmsStatus::Mocked->value]);
    }

    /** Nigerian numbers are stored local (0803...) — show them as +234. */
    public function internationalRecipient(): string
    {
        $digits = preg_replace('/\D/', '', (string) $this->recipient);

        if (str_starts_with($digits, '234')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+234' . substr($digits, 1);
        }

        return '+' . $digits;
    }
}

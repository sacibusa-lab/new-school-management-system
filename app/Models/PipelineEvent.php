<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only trace of everything the admission pipeline does. */
class PipelineEvent extends Model
{
    protected $fillable = ['event', 'subject_type', 'subject_id', 'payload', 'user_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $event, ?Model $subject = null, array $payload = []): self
    {
        return static::create([
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload ?: null,
            'user_id' => auth()->id(),
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's collection paid out to the accounts it was divided between.
 *
 * The settlement page works out the transfers; making them is a thing somebody does at a
 * bank, where nothing here can see it. So this records that the office said it had moved
 * the money, which is why it is a row of its own rather than a figure derived from the
 * payments.
 *
 * One per session per collection day, because a day is the unit the transfers are made in:
 * a single transfer to Zenith settles every fee that day, whatever the size of the day.
 */
class Disbursement extends Model
{
    protected $fillable = [
        'academic_session_id', 'collected_on', 'disbursed_at', 'disbursed_by',
    ];

    protected function casts(): array
    {
        return [
            'collected_on' => 'date',
            'disbursed_at' => 'datetime',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /** Who said the transfers had been made. */
    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}

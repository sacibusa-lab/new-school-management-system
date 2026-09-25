<?php

namespace App\Models;

use App\Enums\SequenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NumberSequence extends Model
{
    protected $fillable = ['type', 'scope', 'prefix', 'last_number'];

    protected function casts(): array
    {
        return [
            'type' => SequenceType::class,
            'last_number' => 'integer',
        ];
    }
}

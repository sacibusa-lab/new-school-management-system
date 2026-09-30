<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of a class: A, B, C.
 *
 * Created before the classes are, because a class is named from one of these. JSS1
 * on its own is not a class anybody sits in — JSS1A is — so the school says which
 * sections it runs once, and a class name is then given as many classes as it has
 * sections.
 */
class Section extends Model
{
    protected $fillable = ['name', 'order'];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function label(): string
    {
        return $this->name;
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('name');
    }
}

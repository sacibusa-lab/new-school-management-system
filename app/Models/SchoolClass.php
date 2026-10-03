<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $fillable = ['level_id', 'section_id', 'name', 'capacity', 'form_teacher_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'capacity' => 'integer',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_id');
    }

    /**
     * The section this class is: A of JSS1.
     *
     * `name` is not derived from these two, but it is written from them when the
     * class is created, so renaming either would leave it stale — which is one
     * reason neither can be renamed yet.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The teacher in charge of this class.
     *
     * Stored as `form_teacher_id` and called a form teacher by the older screens:
     * one thing with two names in the school, the same way a "class" is a class
     * name and a section put together. The office says class teacher.
     */
    public function formTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'form_teacher_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject', 'school_class_id', 'subject_id')
            ->withPivot(['academic_session_id', 'teacher_id'])
            ->withTimestamps();
    }

    public function label(): string
    {
        return $this->name;
    }

    /**
     * The class that is this year group and this section put together.
     *
     * JSS1 and A are JSS1A, and the office names the two the way the school says them
     * rather than picking the class out of one long list. Every screen that takes the
     * pair and needs the class has to resolve it the same way, so it is resolved here
     * once — and it can come back empty, because the school may not have made that arm
     * yet. That is the caller's error to report, not this one's to guess at.
     */
    public function scopeForArm(Builder $query, SchoolLevel $level, Section $section): Builder
    {
        return $query->where('level_id', $level->id)->where('section_id', $section->id);
    }
}

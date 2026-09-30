<?php

namespace App\Services\Teachers;

use App\Models\ActivityLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Taking somebody off the teaching staff.
 *
 * A teacher is an account, and there is no second table holding the person, so this
 * is the only place on the register that destroys anything. Two consequences are
 * handled here rather than left to the page: the photograph goes with the account,
 * because a face left on the disk is a face anybody with the link can still see; and
 * the classes they were class teacher of are cleared deliberately, and reported back,
 * rather than silently blanked by the foreign key.
 */
class TeacherRegisterService
{
    /**
     * The classes this teacher is class teacher of.
     *
     * Read from the relation rather than a fresh query, so the list page — which has
     * already loaded it — can describe every row without a query per row.
     *
     * @return Collection<int,string>
     */
    public function heldClasses(User $teacher): Collection
    {
        return $teacher->taughtClasses->pluck('name')->sort()->values();
    }

    /** The same, as it reads in a sentence: "JSS1A", "JSS1A and JSS1B", "… and 4 more". */
    public function heldClassesFor(User $teacher, int $limit = 3): string
    {
        return $this->describe($this->heldClasses($teacher), $limit);
    }

    /**
     * Remove the account, and everything about it that cannot outlive it.
     *
     * The classes are freed first and explicitly: leaving it to `nullOnDelete` would
     * empty the same column without anything being written down about it.
     */
    public function delete(User $teacher): void
    {
        $name = $teacher->name;

        SchoolClass::query()
            ->where('form_teacher_id', $teacher->id)
            ->update(['form_teacher_id' => null]);

        if ($teacher->avatar_path) {
            Storage::disk('public')->delete($teacher->avatar_path);
        }

        $teacher->delete();

        ActivityLog::record('teachers.removed', null, "Removed {$name} from the teaching staff", [
            'module' => 'teachers',
        ]);
    }

    /** @param  Collection<int,string>  $names */
    public function describe(Collection $names, int $limit = 3): string
    {
        $shown = $names->take($limit);
        $rest = $names->count() - $shown->count();

        $text = $shown->count() === 1
            ? (string) $shown->first()
            : $shown->slice(0, -1)->implode(', ').' and '.$shown->last();

        return $rest > 0 ? $text.' and '.$rest.' more' : $text;
    }
}

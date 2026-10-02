<?php

namespace App\Services\Teachers;

use App\Models\ActivityLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
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
     * A password to hand over, before it has anywhere to live.
     *
     * Six digits and nothing else: it is read off a printed card and typed on a phone
     * by somebody who has never seen the screen, and a bare number is the one shape
     * nobody mistypes twice. This is the single place a handed-over password is made
     * — the bulk upload and a re-issue both come through here — so there is no second
     * shape to keep in step.
     *
     * It is no longer spent on the first sign-in, so it leans on the sign-in throttle
     * rather than on its own length: six digits is 900,000 combinations, and five
     * attempts per window is what actually bounds a guess. Lengthening it to eight
     * would cost the teacher nothing to type and multiply that space a hundredfold,
     * which is the one number worth revisiting.
     */
    public function newPassword(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Give a teacher a fresh password and hand it back to be read out once.
     *
     * The bulk upload shows each password a single time and keeps none of them, so a
     * printout lost on the way back from the office would leave the account nowhere
     * to get in from — there is no "show me the password" to fall back on, and there
     * should not be. This makes a new one instead, and the one it replaces stops
     * working. It is also what the register reaches for when one has gone missing.
     */
    public function issuePassword(User $teacher): string
    {
        $password = $this->newPassword();

        $teacher->update([
            'password' => Hash::make($password),
        ]);

        return $password;
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

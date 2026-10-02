<?php

namespace App\Services\Academics;

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\Assessment;
use App\Models\Exam;
use App\Models\FeeStructure;
use App\Models\ResultPublication;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Student;
use App\Models\TermResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shape of the school: its class names, its sections, and the classes built
 * from the two.
 *
 * The order matters and is the office's, not ours: sections first (A, B, C), then
 * the class names (JSS1, SS1), then JSS1A and JSS1B by putting the two together. A
 * class name on its own is not a class anybody sits in.
 *
 * Deleting follows the same rule as the rest of the platform: nothing that has
 * something written against it disappears, and the office is told what is in the
 * way instead of finding out afterwards. A class name is the stronger case — its
 * classes go with it — so it is refused while any of them holds anything.
 */
class AcademicStructureService
{
    /**
     * Written against a class, with the column that says so.
     *
     * The column is named per model rather than assumed: an applicant records the
     * class they applied for in `level_applied_for_id`, and asking for `level_id`
     * there is a query that fails rather than a count that is wrong.
     */
    private const CLASS_DEPENDENTS = [
        'student' => [Student::class, 'school_class_id'],
        'assessment' => [Assessment::class, 'school_class_id'],
        'term result' => [TermResult::class, 'school_class_id'],
        'published result' => [ResultPublication::class, 'school_class_id'],
    ];

    /** Written against a class name. Its own classes are counted separately. */
    private const CLASS_NAME_DEPENDENTS = [
        'student' => [Student::class, 'level_id'],
        'applicant' => [Applicant::class, 'level_applied_for_id'],
        'examination' => [Exam::class, 'level_id'],
        'fee structure' => [FeeStructure::class, 'level_id'],
    ];

    /* ------------------------------------------------------------------ */
    /* Sections */
    /* ------------------------------------------------------------------ */

    public function addSection(string $name, ?User $actor = null): Section
    {
        $section = Section::create([
            'name' => $this->tidy($name),
            'order' => (int) Section::query()->max('order') + 1,
        ]);

        $this->log($actor, 'section.created', $section, "Added section {$section->name}", [
            'module' => 'academics',
        ]);

        return $section;
    }

    /**
     * The classes using a section — what stops it being deleted, and what the page
     * counts under it.
     *
     * @return array<string,int>
     */
    public function sectionHeld(Section $section): array
    {
        $total = SchoolClass::query()->where('section_id', $section->id)->count();

        return $total > 0 ? ['class' => $total] : [];
    }

    /**
     * @throws RuntimeException
     */
    public function deleteSection(Section $section): void
    {
        if (($blocked = $this->sectionHeld($section)) !== []) {
            throw new RuntimeException(sprintf(
                'Section %s is still used by %s. Delete or move those classes first.',
                $section->name,
                $this->describe($blocked),
            ));
        }

        $section->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Class names */
    /* ------------------------------------------------------------------ */

    public function addClassName(string $name, ?int $order = null): SchoolLevel
    {
        return SchoolLevel::create([
            'name' => $this->tidy($name),
            'order' => $order ?? (int) SchoolLevel::query()->max('order') + 1,
            'is_active' => true,
        ]);
    }

    /**
     * Rename a class, and take its classes with it.
     *
     * JSS1A is JSS1 and section A put together, so renaming JSS1 to JSS2 has to
     * rename JSS1A to JSS2A — otherwise the classes of a class called JSS2 would go
     * on being called JSS1A, and every list in the school would show the old name.
     * The two happen together or not at all.
     *
     * @throws RuntimeException when the name is already taken
     */
    public function renameClassName(SchoolLevel $level, string $name, ?User $actor = null): SchoolLevel
    {
        $name = $this->tidy($name);

        if ($name === $level->name) {
            return $level;
        }

        if (SchoolLevel::query()->where('name', $name)->whereKeyNot($level->id)->exists()) {
            throw new RuntimeException("{$name} is already there.");
        }

        $was = $level->name;

        DB::transaction(function () use ($level, $name): void {
            $level->update(['name' => $name]);

            foreach ($level->classes()->with('section')->get() as $class) {
                $class->update(['name' => $name.$class->section?->name]);
            }
        });

        $this->log($actor, 'class.renamed', $level, "Renamed class {$was} to {$name}", [
            'module' => 'academics',
            'was' => $was,
        ]);

        return $level;
    }

    /**
     * Offer a year group, or take it out of the lists.
     *
     * A school does not run every year every year. JSS3 goes at the end of the junior
     * school and SS3 with the seniors, a year the school has not opened yet is not a
     * year an applicant can be offered, and a class that has left should not go on
     * being filled in. This is the switch for that, and it is the only one there is —
     * which is the point, because a year group that is not offered is not offered
     * anywhere, and where that was decided was a seeder far from the school office.
     *
     * Nothing written against the year is touched. Its classes, its fee structures,
     * its marks and its children all stay exactly where they are, so the year can be
     * offered again with everything intact. That is why this is not a delete.
     *
     * The arms of a year group go with it. JSS3A cannot be offered at a school that is
     * not running JSS3 — and a class left switched on under a year that is switched
     * off is two screens in the same platform disagreeing with each other, which is
     * how a child ends up on a register for a class nobody is teaching.
     */
    public function setClassNameActive(SchoolLevel $level, bool $isActive, ?User $actor = null): SchoolLevel
    {
        if ($level->is_active === $isActive) {
            return $level;
        }

        $level->update(['is_active' => $isActive]);
        $level->classes()->update(['is_active' => $isActive]);

        $this->log(
            $actor,
            $isActive ? 'class.offered' : 'class.withdrawn',
            $level,
            $isActive ? "Offered {$level->name}" : "Withdrew {$level->name}",
            ['module' => 'academics'],
        );

        return $level;
    }

    /**
     * What is written against a class name in its own right.
     *
     * @return array<string,int>
     */
    public function classNameHeld(SchoolLevel $level): array
    {
        $counts = [];

        foreach (self::CLASS_NAME_DEPENDENTS as $key => [$model, $column]) {
            $total = $model::query()->where($column, $level->id)->count();

            if ($total > 0) {
                $counts[$key] = $total;
            }
        }

        return $counts;
    }

    /**
     * The sections a class should have, once the Edit form has been filled in.
     *
     * Ticked sections it has not got are added; the ones it has that were unticked
     * are removed. A removal that something is written against is refused — and the
     * reason is returned rather than thrown, because the rest of the form is still
     * worth saving: renaming a class and dropping one empty section of it should not
     * be undone because a third section is holding children.
     *
     * @param  array<int,int>  $sectionIds  The sections the class should end up with.
     * @return array<int,string> Why each section that stayed, stayed.
     */
    public function syncSections(SchoolLevel $level, array $sectionIds, ?User $actor = null): array
    {
        $wanted = Section::query()->whereIn('id', $sectionIds)->pluck('id')->all();

        foreach ($wanted as $id) {
            if ($level->classes()->where('section_id', $id)->exists()) {
                continue;
            }

            if ($section = Section::query()->find($id)) {
                $this->addClass($level, $section, $actor);
            }
        }

        $refused = [];

        foreach ($level->classes()->with('section')->get() as $class) {
            if (in_array($class->section_id, $wanted, true)) {
                continue;
            }

            try {
                $this->deleteClass($class);
            } catch (RuntimeException $e) {
                $refused[] = $e->getMessage();
            }
        }

        return $refused;
    }

    /**
     * A class name with nothing written against it, and none of its classes
     * holding anything, can go — and takes its classes with it, which is what
     * deleting the name of a class means.
     *
     * @throws RuntimeException
     */
    public function deleteClassName(SchoolLevel $level): void
    {
        if (($blocked = $this->classNameHeld($level)) !== []) {
            throw new RuntimeException(sprintf(
                '%s still has %s against it. Move them before deleting the class.',
                $level->name,
                $this->describe($blocked),
            ));
        }

        // Its classes are about to go with it, so they have to be empty too.
        $held = $this->classHeld($level->classes()->get());

        if ($held !== []) {
            throw new RuntimeException(sprintf(
                '%s holds %s. Empty its classes before deleting the class name.',
                $level->name,
                $this->describe($held),
            ));
        }

        DB::transaction(function () use ($level): void {
            $level->classes()->delete();
            $level->delete();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Classes: a class name and a section */
    /* ------------------------------------------------------------------ */

    /**
     * A class name and a section, which is what the Create Class form collects.
     *
     * The officer types the class name — JSS1 — because that is how a class name
     * comes into being, and picks the section from the ones that already exist. If
     * the class name is already there this is simply its next section: JSS1 + A, then
     * JSS1 + B, is how JSS1A and JSS1B are made.
     *
     * @throws RuntimeException when that class is already there
     */
    public function addClassFrom(string $name, Section $section, ?User $actor = null): SchoolClass
    {
        $level = SchoolLevel::query()->where('name', $this->tidy($name))->first()
            ?? $this->addClassName($name);

        return $this->addClass($level, $section, $actor);
    }

    /**
     * Build the class a class name and a section make between them: JSS1 + A.
     *
     * @throws RuntimeException when that class is already there
     */
    public function addClass(SchoolLevel $level, Section $section, ?User $actor = null): SchoolClass
    {
        $name = $level->name.$section->name;

        if (SchoolClass::query()->where('level_id', $level->id)->where('section_id', $section->id)->exists()) {
            throw new RuntimeException("{$name} is already there.");
        }

        $class = SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $name,
            // A new arm of a year group the school is not running is not offered
            // either. Adding section E to a withdrawn JSS3 makes JSS3E, not a way back
            // in.
            'is_active' => $level->is_active,
        ]);

        $this->log($actor, 'class.created', $class, "Added class {$class->name}", [
            'module' => 'academics',
            'section' => $section->name,
        ]);

        return $class;
    }

    /**
     * @throws RuntimeException
     */
    public function deleteClass(SchoolClass $class): void
    {
        $blocked = $this->classHeld([$class]);

        if ($blocked !== []) {
            throw new RuntimeException(sprintf(
                '%s still holds %s. Move them before deleting the class.',
                $class->name,
                $this->describe($blocked),
            ));
        }

        $class->delete();
    }

    /**
     * Put a teacher in charge of a class, or take the one there out.
     *
     * A class teacher belongs to the class rather than the class name: JSS1A and
     * JSS1B have one each. Passing null clears it — a class can be between teachers,
     * and leaving the last one on it because nobody replaced them would be a worse
     * record than none.
     *
     * Only an account that is actually a teacher can be given a class. The office
     * picks from the teachers already on the staff rather than from every login, so
     * this is the guard behind that list, not a second way of choosing.
     *
     * The column it writes is `form_teacher_id` and the school says both words for
     * the one job; class teacher is the one the office uses and the one the screens
     * use, so that is what the method is called.
     *
     * @throws RuntimeException when the account is not a teacher
     */
    public function assignClassTeacher(SchoolClass $class, ?User $teacher, ?User $actor = null): SchoolClass
    {
        if ($teacher !== null && ! $teacher->hasRole('Teacher')) {
            throw new RuntimeException("{$teacher->name} is not a teacher. Add them on the Add Teachers page first.");
        }

        $was = $class->formTeacher?->name;

        $class->update(['form_teacher_id' => $teacher?->id]);

        if ($teacher === null) {
            $this->log($actor, 'class.teacher.cleared', $class, "Took the class teacher off {$class->name}", [
                'module' => 'academics',
                'was' => $was,
            ]);

            return $class;
        }

        $this->log($actor, 'class.teacher.set', $class, "Set {$teacher->name} as class teacher of {$class->name}", [
            'module' => 'academics',
            'was' => $was,
            'teacher_id' => $teacher->id,
        ]);

        return $class;
    }

    /* ------------------------------------------------------------------ */
    /* What is in the way */
    /* ------------------------------------------------------------------ */

    /**
     * Everything counted against a set of classes — the count the office is shown
     * before a delete is refused.
     *
     * @param  iterable<SchoolClass>  $classes
     * @return array<string,int>
     */
    public function classHeld(iterable $classes): array
    {
        $ids = collect($classes)->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach (self::CLASS_DEPENDENTS as $key => [$model, $column]) {
            $total = $model::query()->whereIn($column, $ids)->count();

            if ($total > 0) {
                $counts[$key] = $total;
            }
        }

        return $counts;
    }

    /** "12 students, 3 examinations and 40 invoices" */
    public function describe(array $blocked): string
    {
        $parts = [];

        foreach ($blocked as $key => $total) {
            $parts[] = $total.' '.($total === 1 ? $key : $key.'s');
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    /** "jss 1" and "a" are the same class name and section as "JSS 1" and "A". */
    private function tidy(string $name): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    private function log(?User $actor, string $action, Model $subject, string $description, array $properties = []): void
    {
        if ($actor === null) {
            return;
        }

        ActivityLog::record($action, $subject, $description, $properties);
    }
}

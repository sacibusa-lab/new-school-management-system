<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Class & Section Report: how full each class is.
 *
 * One line per year group, its arms listed inside it with the number in each, and what
 * the year comes to. That is the shape the office has read this in for years, and the
 * shape the question is actually asked in — "how many are in JSS1?" is one question,
 * not five.
 *
 * The figures are the register's figures rather than a second count of the same
 * children. The register does not narrow the roll by status — a child who has been
 * suspended is still on it — and neither does this, so the number here and the length
 * of the list it links to are the same number. A report that disagreed with the list
 * beside it would be believed by nobody, and rightly.
 *
 * The structure is filtered, not the children. A class the school has withdrawn is left
 * off, unless it still holds students: a withdrawn arm is not something to advertise,
 * but neither is it a place to hide a child.
 */
class ClassSectionReportController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('students.view');

        $levels = SchoolLevel::query()
            // Counted in the query rather than class by class, so six year groups cost
            // two reads instead of one per class.
            ->with(['classes' => fn ($query) => $query->withCount('students')->orderBy('name')])
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $unplaced = $this->unplacedByLevel();

        return view('admin.students-results.students.class-section-report', [
            'rows' => $levels->map(
                fn (SchoolLevel $level) => $this->row($level, (int) ($unplaced[$level->id] ?? 0)),
            ),
        ]);
    }

    /**
     * One year group: the arms of it, and what they come to.
     *
     * @return array{level:SchoolLevel,classes:Collection<int,array<string,mixed>>,unplaced:int,total:int}
     */
    private function row(SchoolLevel $level, int $unplaced): array
    {
        $classes = $level->classes
            ->filter(fn (SchoolClass $class) => $class->is_active || $class->students_count > 0)
            ->map(fn (SchoolClass $class) => [
                'section_id' => $class->section_id,
                'name' => $class->section?->name ?? $class->name,
                'count' => $class->students_count,
            ])
            ->values();

        return [
            'level' => $level,
            'classes' => $classes,
            'unplaced' => $unplaced,
            // Unplaced children sit inside the total rather than outside it, or the
            // figure would not add up to the lines printed beside it.
            'total' => $classes->sum('count') + $unplaced,
        ];
    }

    /**
     * How many children each year group holds who have been admitted but not yet placed
     * in a class.
     *
     * One grouped read for the whole page. A child in this state is on the register —
     * asking it for their year group alone finds them — so leaving them out here would
     * print a total the register itself contradicts.
     *
     * @return Collection<int,int>
     */
    private function unplacedByLevel(): Collection
    {
        return Student::query()
            ->whereNotNull('level_id')
            ->whereNull('school_class_id')
            ->groupBy('level_id')
            ->selectRaw('level_id, count(*) as total')
            ->pluck('total', 'level_id');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The student register: who is on the roll, one class at a time.
 *
 * The page opens on its two dropdowns and nothing else. A register is read a class at a
 * time — that is how the school is organised, and how the office is asked about it — so
 * an unfiltered list of every child in the school is neither what anybody came here for
 * nor something this page should spend a query on. Choose a class, a section, or both,
 * and press Filter.
 *
 * A class here is a year group plus a section: JSS1 and A are JSS1A. They are asked for
 * separately rather than making the office hunt for the combination in one long list of
 * every class the school runs. Either one is enough on its own — a class without a
 * section is every arm of that year, a section without a class is that arm of every
 * year — and both together pick out the one class.
 *
 * Each line carries the photograph, the guardian and how far the fees have got, because
 * those are the three things the office is asked about across a counter.
 */
class StudentRegisterController extends Controller
{
    /** Lines to a page: a form class or two at a time. */
    private const PER_PAGE = 25;

    public function __invoke(Request $request): View
    {
        $this->authorize('students.view');

        $levelId = $request->integer('class');
        $sectionId = $request->integer('section');

        $searched = $levelId !== 0 || $sectionId !== 0;

        return view('admin.students-results.students', [
            'students' => $searched ? $this->register($levelId, $sectionId) : null,
            'searched' => $searched,
            'levels' => SchoolLevel::query()->active()->orderBy('order')->orderBy('name')->get(),
            'sections' => Section::query()->orderBy('order')->orderBy('name')->get(),
            'filters' => ['class' => $levelId, 'section' => $sectionId],
        ]);
    }

    /** Take one student off the register. */
    public function destroy(Student $student): RedirectResponse
    {
        $this->authorize('students.manage');

        $name = $student->full_name;

        $this->remove(collect([$student]));

        return back()->with('status', "{$name} was taken off the register.");
    }

    /**
     * Take the ticked students off the register.
     *
     * Each id is re-read rather than trusted, so a tick that was tampered with can only
     * ever name a student who exists — and the count that comes back is the number
     * actually removed, not the number that was posted.
     */
    public function destroySelected(Request $request): RedirectResponse
    {
        $this->authorize('students.manage');

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer'],
        ], [
            'students.required' => 'Tick at least one student first.',
            'students.min' => 'Tick at least one student first.',
        ]);

        $students = Student::query()->whereIn('id', $validated['students'])->get();

        $removed = $this->remove($students);

        return back()->with('status', $removed === 1
            ? '1 student was taken off the register.'
            : "{$removed} students were taken off the register.");
    }

    /**
     * The register itself, for the class and section asked for.
     *
     * Kept out of the action because the page has two states and this is reached in only
     * one of them: when nothing has been asked for, the query is never built at all.
     */
    private function register(int $levelId, int $sectionId): LengthAwarePaginator
    {
        return Student::query()
            ->with(['schoolClass', 'level'])
            // Billed and paid are summed rather than read off each student, so a page of
            // twenty-five costs two queries instead of fifty. Cancelled invoices are left
            // out, which is what Student::totalBilled() does with the same figures.
            ->withSum(
                ['invoices as billed_total' => fn ($query) => $query
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)],
                'total',
            )
            ->withSum(
                ['invoices as paid_total' => fn ($query) => $query
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)],
                'amount_paid',
            )
            ->when($levelId, fn ($query) => $query->where('level_id', $levelId))
            // The section lives on the class rather than on the student, so this asks the
            // class they are in rather than the student row itself.
            ->when($sectionId, fn ($query) => $query->whereHas(
                'schoolClass',
                fn ($classes) => $classes->where('section_id', $sectionId),
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Take students off the register.
     *
     * A soft delete, and deliberately so: the record stops appearing on the register and
     * the family lose the portal, but nothing is destroyed. A child removed by mistake is
     * put back rather than reconstructed from paper — which matters more here than on the
     * teacher register, because this is a child's record.
     *
     * @param  Collection<int,Student>  $students
     */
    private function remove(Collection $students): int
    {
        $removed = 0;

        foreach ($students as $student) {
            $student->delete();
            $removed++;
        }

        if ($removed > 0) {
            ActivityLog::record(
                'students.removed',
                null,
                $removed === 1
                    ? "Took {$students->first()->full_name} off the register"
                    : "Took {$removed} students off the register",
                ['module' => 'students', 'count' => $removed],
            );
        }

        return $removed;
    }
}

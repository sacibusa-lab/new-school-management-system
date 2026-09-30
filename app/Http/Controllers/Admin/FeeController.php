<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\SchoolLevel;
use App\Models\Student;
use App\Models\Term;
use App\Services\Fees\InvoiceGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeeController extends Controller
{
    public function __construct(
        private readonly InvoiceGenerationService $invoices,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    public function categories(): View
    {
        $this->authorize('fees.manage');

        return view('admin.fees.categories', [
            'categories' => FeeCategory::query()->withCount('structureItems')->orderBy('name')->get(),
            'types' => [
                'tuition' => 'Tuition',
                'levy' => 'Levy',
                'development' => 'Development',
                'books' => 'Books',
                'uniform' => 'Uniform',
                'transport' => 'Transport',
                'hostel' => 'Boarding',
                'other' => 'Other',
            ],
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:fee_categories,code'],
            'type' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        FeeCategory::create($validated + ['is_active' => true]);

        return back()->with('status', 'Fee category created.');
    }

    public function updateCategory(Request $request, FeeCategory $category): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category->update($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Fee category updated.');
    }

    /* ------------------------------------------------------------------ */
    /* Structures                                                          */
    /* ------------------------------------------------------------------ */

    public function structures(): View
    {
        $this->authorize('fees.manage');

        return view('admin.fees.structures', [
            'structures' => FeeStructure::query()
                ->with(['academicSession', 'term', 'level'])
                ->withCount('items')
                ->orderByDesc('academic_session_id')
                ->orderBy('level_id')
                ->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'terms' => Term::query()->orderBy('position')->get(),
            'levels' => SchoolLevel::query()->active()->get(),
        ]);
    }

    public function storeStructure(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
            'level_id' => ['nullable', 'exists:school_levels,id'],
            'due_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_default_for_new_students' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $structure = FeeStructure::create($validated + [
            'is_active' => true,
            'is_default_for_new_students' => $request->boolean('is_default_for_new_students'),
        ]);

        return redirect()
            ->route('admin.fees.structures.show', $structure)
            ->with('status', 'Fee structure created. Add the fee lines and amounts below.');
    }

    public function showStructure(FeeStructure $structure): View
    {
        $this->authorize('fees.manage');

        $structure->load(['academicSession', 'term', 'level', 'items.category']);

        return view('admin.fees.structure-show', [
            'structure' => $structure,
            'categories' => FeeCategory::query()->active()->get(),
            'studentCount' => Student::query()
                ->when($structure->level_id, fn ($q) => $q->where('level_id', $structure->level_id))
                ->where('academic_session_id', $structure->academic_session_id)
                ->count(),
        ]);
    }

    public function updateStructure(Request $request, FeeStructure $structure): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'due_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'is_default_for_new_students' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $structure->update($validated + [
            'is_active' => $request->boolean('is_active'),
            'is_default_for_new_students' => $request->boolean('is_default_for_new_students'),
        ]);

        return back()->with('status', 'Fee structure updated.');
    }

    public function storeStructureItem(Request $request, FeeStructure $structure): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'fee_category_id' => ['required', 'exists:fee_categories,id'],
            'description' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'is_compulsory' => ['nullable', 'boolean'],
        ]);

        $structure->items()->updateOrCreate(
            ['fee_category_id' => $validated['fee_category_id']],
            [
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'],
                'is_compulsory' => $request->boolean('is_compulsory', true),
            ],
        );

        return back()->with('status', 'Fee line saved.');
    }

    public function destroyStructureItem(FeeStructure $structure, FeeStructureItem $item): RedirectResponse
    {
        $this->authorize('fees.manage');

        abort_unless($item->fee_structure_id === $structure->id, 404);

        $item->delete();

        return back()->with('status', 'Fee line removed.');
    }

    /**
     * Raise invoices for every active student in a level against a structure.
     */
    public function billStudents(Request $request, FeeStructure $structure): RedirectResponse
    {
        $this->authorize('fees.invoice');

        $students = Student::query()
            ->where('academic_session_id', $structure->academic_session_id)
            ->when($structure->level_id, fn ($q) => $q->where('level_id', $structure->level_id))
            ->where('status', \App\Enums\StudentStatus::Active->value)
            ->get();

        if ($students->isEmpty()) {
            return back()->with('error', 'No active students match this fee structure.');
        }

        $summary = $this->invoices->generateBulk($students, $structure->term_id, $request->user());

        return redirect()
            ->route('admin.invoices.index')
            ->with('status', "{$summary['created']} invoice(s) raised, {$summary['skipped']} student(s) already billed for this term.");
    }
}

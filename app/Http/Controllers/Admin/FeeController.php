<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\FeeCategory;
use App\Models\FeeClassOverride;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Services\Fees\InvoiceGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FeeController extends Controller
{
    public function __construct(
        private readonly InvoiceGenerationService $invoices,
    ) {}

    /**
     * The tabs on a fee's own page, in the order the office reads them.
     *
     * Details is the fee itself. The three in the middle are the ways one fee becomes a
     * set of bills: split between the school's own accounts, priced differently for a year
     * group, and read back term by term. Settings is its standing.
     *
     * Defined here rather than in the template so the keys the page links to and the keys
     * the controller accepts cannot drift apart.
     *
     * @var array<int,array{key:string,label:string,icon:string}>
     */
    public const TABS = [
        ['key' => 'details', 'label' => 'Details', 'icon' => 'clipboard-check'],
        ['key' => 'splits', 'label' => 'Internal ledger splits', 'icon' => 'columns'],
        ['key' => 'class-amounts', 'label' => 'Class amounts', 'icon' => 'tag'],
        ['key' => 'transactions', 'label' => 'Transactions', 'icon' => 'receipt'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'cog'],
    ];

    /* ------------------------------------------------------------------ */
    /* The catalogue of fees the school charges */
    /* ------------------------------------------------------------------ */

    /**
     * Everything the school charges, and how often it comes round.
     *
     * Not the same list as the fee structures, which price a term for a year group.
     * This is the catalogue those are built from: a fee is a thing that can be billed,
     * and a structure is the decision to bill it.
     */
    public function index(): View
    {
        $this->authorize('fees.manage');

        return view('admin.fees.index', [
            'fees' => Fee::query()->with('academicSession')->orderBy('title')->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'cycles' => Fee::CYCLES,
            'terms' => Fee::TERMS,
        ]);
    }

    /**
     * A new fee, from the modal on the catalogue page.
     *
     * Created active, and there is nowhere in the modal to say otherwise: switching a
     * fee off is a decision about a fee that exists, made on its own page, not something
     * to be got wrong in the moment a title is first typed in.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $fee = Fee::create($this->fields($request->validate($this->rules()), $request) + [
            'is_active' => true,
        ]);

        return redirect()
            ->route('admin.fees.index')
            ->with('status', $fee->title.' is on the fee list.');
    }

    /**
     * A fee's own page, in five tabs.
     *
     * The tab is in the url rather than held in Alpine, so the page survives a reload and
     * a validation failure comes back to the tab the form was on rather than dropping the
     * office on whichever one happens to be first.
     */
    public function edit(Request $request, Fee $fee): View
    {
        $this->authorize('fees.manage');

        return view('admin.fees.edit', [
            'fee' => $fee->load(['academicSession', 'splits.bankAccount', 'overrides.level']),
            'tab' => $this->tabFrom($request),
            'tabs' => self::TABS,
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'cycles' => Fee::CYCLES,
            'terms' => Fee::TERMS,
            'statuses' => FeeClassOverride::STATUSES,
            'bankAccounts' => BankAccount::query()->active()->get(),
            'defaultItMaintenanceFee' => Fee::DEFAULT_IT_MAINTENANCE_FEE,
            'levels' => SchoolLevel::query()->active()->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    /**
     * The Details tab, saved.
     *
     * Deliberately does not touch `is_active`: the standing of a fee is changed in
     * Settings, and a form that saved the title should not be able to switch the fee off
     * as a side effect of something nobody was looking at.
     */
    public function update(Request $request, Fee $fee): RedirectResponse
    {
        $this->authorize('fees.manage');

        $fee->update($this->fields($request->validate($this->rules()), $request));

        return redirect()
            ->route('admin.fees.edit', ['fee' => $fee, 'tab' => 'details'])
            ->with('status', $fee->title.' updated.');
    }

    /**
     * Switch a fee on or off, from the Settings tab.
     *
     * Not a delete, and there is no delete to go with it. A fee that has been billed
     * cannot be removed without deciding what happens to the bills raised from it, and
     * switching it off leaves every one of them exactly as it was.
     */
    public function toggle(Fee $fee): RedirectResponse
    {
        $this->authorize('fees.manage');

        $fee->update(['is_active' => ! $fee->is_active]);

        return redirect()
            ->route('admin.fees.edit', ['fee' => $fee, 'tab' => 'settings'])
            ->with('status', $fee->title.' is now '.($fee->is_active ? 'active' : 'off').'.');
    }

    /**
     * The Internal ledger splits tab, saved.
     *
     * Replaced whole rather than merged row by row: the form is the entire answer to "who
     * is this fee divided between", and leaving a row out is how a split is removed.
     *
     * A split may add up to less than the fee — the remainder pays into the main account —
     * but never to more. That is checked here and not only in the form, because it is a rule
     * about the money, and a rule about the money cannot live somewhere a request can skip.
     *
     * The IT maintenance fee is saved on the same form because it is the same decision: how
     * one payment of this fee is divided. It is kept back per transaction before the splits
     * are paid, so it comes off the top rather than out of any one account.
     */
    public function saveSplits(Request $request, Fee $fee): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            // Optional rather than required: removing every split is a real thing to want,
            // and it means the fee pays wholly into the main account.
            'splits' => ['nullable', 'array'],
            // Distinct, because two rows for one account would be two transfers to it.
            'splits.*.bank_account_id' => ['required', 'integer', 'distinct', 'exists:bank_accounts,id'],
            'splits.*.amount' => ['required', 'numeric', 'min:0'],
            // Empty means the platform's default, which is why it is nullable rather than
            // defaulted here: the default can change without rewriting every fee.
            'it_maintenance_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'splits.*.bank_account_id.distinct' => 'The same account is listed twice.',
            'splits.*.bank_account_id.exists' => 'One of those accounts no longer exists.',
        ]);

        $rows = $validated['splits'] ?? [];
        $total = round(array_sum(array_map(fn (array $row): float => (float) $row['amount'], $rows)), 2);

        if ($total > (float) $fee->amount) {
            $currency = Setting::get('currency_symbol', '₦');

            throw ValidationException::withMessages([
                'splits' => sprintf(
                    'Those splits add up to %s%s, which is more than the fee of %s%s.',
                    $currency, number_format($total, 2),
                    $currency, number_format((float) $fee->amount, 2),
                ),
            ]);
        }

        $fee->update(['it_maintenance_fee' => $validated['it_maintenance_fee'] ?? null]);

        $fee->splits()->delete();

        foreach (array_values($rows) as $position => $row) {
            $fee->splits()->create($row + ['position' => $position]);
        }

        return redirect()
            ->route('admin.fees.edit', ['fee' => $fee, 'tab' => 'splits'])
            ->with('status', $rows === []
                ? 'Every split removed — '.$fee->title.' pays into the main account.'
                : 'Splits updated for '.$fee->title.'.');
    }

    /**
     * The Class amounts tab, saved.
     *
     * Replaced whole, like the splits: leaving a year group out is how an override is
     * removed, and the form is the entire answer.
     */
    public function saveOverrides(Request $request, Fee $fee): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'overrides' => ['nullable', 'array'],
            // Distinct, so a year group named twice is a message rather than a constraint
            // violation the office cannot read.
            'overrides.*.level_id' => ['required', 'integer', 'distinct', 'exists:school_levels,id'],
            'overrides.*.amount' => ['required', 'numeric', 'min:0'],
            'overrides.*.status' => ['required', Rule::in(array_keys(FeeClassOverride::STATUSES))],
        ], [
            'overrides.*.level_id.distinct' => 'The same year group is listed twice.',
            'overrides.*.level_id.exists' => 'One of those year groups no longer exists.',
        ]);

        $rows = $validated['overrides'] ?? [];

        $fee->overrides()->delete();

        foreach ($rows as $row) {
            $fee->overrides()->create($row);
        }

        return redirect()
            ->route('admin.fees.edit', ['fee' => $fee, 'tab' => 'class-amounts'])
            ->with('status', $rows === []
                ? 'Every class amount removed — every year group pays the default.'
                : 'Class amounts updated for '.$fee->title.'.');
    }

    /**
     * The rules a fee is saved under, in the modal and on its own page alike. One list,
     * so the two cannot drift apart.
     *
     * @return array<string,array<int,mixed>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'revenue_code' => ['nullable', 'string', 'max:40'],
            'cycle' => ['required', Rule::in(array_keys(Fee::CYCLES))],
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            // The per-term amounts are exceptions to the default, so empty is allowed and
            // means "charge the default here".
            'first_term_amount' => ['nullable', 'numeric', 'min:0'],
            'second_term_amount' => ['nullable', 'numeric', 'min:0'],
            'third_term_amount' => ['nullable', 'numeric', 'min:0'],
            // Unchecked boxes are not submitted at all, so these have to be optional.
            'first_term_active' => ['sometimes', 'boolean'],
            'second_term_active' => ['sometimes', 'boolean'],
            'third_term_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The fee's own fields, out of a validated request.
     *
     * The terms are read off the request rather than the validated array, because an
     * unticked box is not submitted at all and a missing key has to mean "no".
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    protected function fields(array $validated, Request $request): array
    {
        return [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'revenue_code' => $validated['revenue_code'] ?? null,
            'cycle' => $validated['cycle'],
            'academic_session_id' => $validated['academic_session_id'] ?? null,
            'amount' => $validated['amount'],
            'first_term_amount' => $this->amountOrNull($validated['first_term_amount'] ?? null),
            'second_term_amount' => $this->amountOrNull($validated['second_term_amount'] ?? null),
            'third_term_amount' => $this->amountOrNull($validated['third_term_amount'] ?? null),
            'first_term_active' => $request->boolean('first_term_active'),
            'second_term_active' => $request->boolean('second_term_active'),
            'third_term_active' => $request->boolean('third_term_active'),
        ];
    }

    /**
     * An emptied amount box means "use the default", not nought. Stored as null so the two
     * can be told apart later: a term that costs nothing and a term that was never priced
     * are different answers.
     */
    protected function amountOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : round((float) $value, 2);
    }

    /** An unknown tab is not an error to shout about; it is somebody's stale bookmark. */
    protected function tabFrom(Request $request): string
    {
        $tab = (string) $request->query('tab', 'details');

        return in_array($tab, array_column(self::TABS, 'key'), true) ? $tab : 'details';
    }

    /* ------------------------------------------------------------------ */
    /* Categories */
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
    /* Structures */
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

        $structure->load(['academicSession', 'term', 'level', 'items.category', 'items.fee']);

        return view('admin.fees.structure-show', [
            'structure' => $structure,
            'categories' => FeeCategory::query()->active()->get(),
            'fees' => Fee::query()->active()->orderBy('title')->get(),
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
            // Optional: a line that names no fee is still a price the school charges, it
            // just has nothing to divide the money by.
            'fee_id' => ['nullable', 'exists:fees,id'],
            'description' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'is_compulsory' => ['nullable', 'boolean'],
        ]);

        $structure->items()->updateOrCreate(
            ['fee_category_id' => $validated['fee_category_id']],
            [
                'fee_id' => $validated['fee_id'] ?? null,
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
            ->where('status', StudentStatus::Active->value)
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

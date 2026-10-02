<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubjectController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('academics.manage');

        $search = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? '';
        $search = trim($search);
        $status = $request->input('status');

        if (! is_string($status) || ! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $subjects = Subject::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $editing = $request->integer('edit') > 0
            ? Subject::query()->find($request->integer('edit'))
            : null;

        return view('admin.students-results.academics.subjects', [
            'subjects' => $subjects,
            'search' => $search,
            'status' => $status,
            'editing' => $editing,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        Subject::create($this->validatedSubject($request) + ['is_active' => true]);

        return redirect()->route('admin.students-results.academics.subjects')
            ->with('status', 'Subject added.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $this->authorize('academics.manage');

        $subject->update($this->validatedSubject($request, $subject));

        return redirect()->route('admin.students-results.academics.subjects')
            ->with('status', 'Subject updated.');
    }

    public function updateStatus(Request $request, Subject $subject): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $subject->update(['is_active' => $validated['is_active']]);

        return back()->with('status', $subject->is_active ? 'Subject activated.' : 'Subject deactivated.');
    }

    /** @return array{name: string, code: string} */
    private function validatedSubject(Request $request, ?Subject $subject = null): array
    {
        foreach (['name', 'code'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([$field => trim($value)]);
            }
        }

        $code = $request->input('code');

        if (is_string($code)) {
            $request->merge(['code' => Str::upper($code)]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', Rule::unique('subjects', 'code')->ignore($subject)],
        ], [
            'code.unique' => 'That subject code is already in use.',
        ]);
    }
}

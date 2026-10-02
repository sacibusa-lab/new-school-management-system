<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubjectAssignmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('academics.manage');

        $academicSession = AcademicSession::current();
        $academicSessionId = $academicSession?->id;
        $currentSessionSubjects = fn (BelongsToMany $query) => $this->constrainToSession($query, $academicSessionId);

        $assignments = SchoolClass::query()
            ->with([
                'level',
                'section',
                'subjects' => function (BelongsToMany $query) use ($academicSessionId): void {
                    $this->constrainToSession($query, $academicSessionId);
                    $query->orderBy('subjects.name');
                },
            ])
            ->whereIn('id', DB::table('class_subject')
                ->select('school_class_id')
                ->when(
                    $academicSessionId === null,
                    fn (QueryBuilder $query) => $query->whereNull('academic_session_id'),
                    fn (QueryBuilder $query) => $query->where('academic_session_id', $academicSessionId),
                )
                ->distinct())
            ->orderBy('level_id')
            ->orderBy('section_id')
            ->paginate(20)
            ->withQueryString();

        $editing = $request->integer('edit') > 0
            ? SchoolClass::query()
                ->with([
                    'level',
                    'section',
                    'subjects' => $currentSessionSubjects,
                ])
                ->find($request->integer('edit'))
            : null;

        $classOptions = SchoolClass::query()
            ->with(['level:id,name,order', 'section:id,name,order'])
            ->where('is_active', true)
            ->whereHas('level', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('section')
            ->orderBy('level_id')
            ->orderBy('section_id')
            ->get();

        $levels = $classOptions->pluck('level')->unique('id')->sortBy('order')->values();
        $sectionsByLevel = $classOptions
            ->groupBy('level_id')
            ->map(fn ($classes): array => $classes
                ->map(fn (SchoolClass $schoolClass): array => [
                    'id' => (string) $schoolClass->section_id,
                    'name' => $schoolClass->section->name,
                ])
                ->values()
                ->all())
            ->all();

        $selectedSubjectIds = old('subject_ids', $editing?->subjects->pluck('id')->all() ?? []);
        $selectedTeacherIds = old('teachers', $editing?->subjects
            ->mapWithKeys(fn (Subject $subject): array => [
                $subject->id => (string) ($subject->pivot->teacher_id ?? ''),
            ])
            ->all() ?? []);

        if (! is_array($selectedSubjectIds)) {
            $selectedSubjectIds = [];
        }

        if (! is_array($selectedTeacherIds)) {
            $selectedTeacherIds = [];
        }

        $inactiveSubjects = $editing?->subjects
            ->filter(fn (Subject $subject): bool => ! $subject->is_active)
            ->values() ?? collect();
        // The list is what the page opens on: it is the register of what is already
        // assigned, and the form is reached from it. Editing still opens the form,
        // because arriving there with nothing ticked would be a step backwards.
        $tab = $editing ? 'assign' : $request->input('tab', 'list');

        if (! is_string($tab) || ! in_array($tab, ['list', 'assign'], true)) {
            $tab = 'list';
        }

        // Names for the teachers already on this page's classes, so the register can
        // name who takes each subject without a query per row.
        $teacherNames = User::query()
            ->whereIn('id', $assignments->getCollection()
                ->flatMap(fn (SchoolClass $schoolClass) => $schoolClass->subjects
                    ->map(fn (Subject $subject): ?int => $subject->pivot->teacher_id))
                ->filter()
                ->unique()
                ->values())
            ->pluck('name', 'id');

        return view('admin.students-results.academics.subject-assignments', [
            'assignments' => $assignments,
            'academicSession' => $academicSession,
            'editing' => $editing,
            'inactiveSubjects' => $inactiveSubjects,
            'levels' => $levels,
            'selectedLevelId' => (string) old('level_id', $editing?->level_id ?? ''),
            'selectedSectionId' => (string) old('section_id', $editing?->section_id ?? ''),
            'selectedSubjectIds' => array_map('strval', $selectedSubjectIds),
            'selectedTeacherIds' => $selectedTeacherIds,
            'sectionsByLevel' => $sectionsByLevel,
            'subjects' => Subject::query()->active()->get(),
            'teacherNames' => $teacherNames,
            'teachers' => User::query()->active()->role('Teacher')->orderBy('name')->get(['id', 'name']),
            'tab' => $tab,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        $classSelection = $request->validate([
            'level_id' => ['required', 'integer', 'exists:school_levels,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);
        $validated = $this->validatedAssignment($request);

        $schoolClass = SchoolClass::query()
            ->where('level_id', $classSelection['level_id'])
            ->where('section_id', $classSelection['section_id'])
            ->where('is_active', true)
            ->first();

        if (! $schoolClass) {
            return back()
                ->withErrors(['section_id' => 'That section is not available for the selected class.'])
                ->withInput();
        }

        $this->replaceSessionSubjects(
            $schoolClass,
            $validated['subjectIds'],
            $validated['teacherIdsBySubject'],
            AcademicSession::current()?->id,
        );

        return redirect()
            ->route('admin.students-results.academics.subjects.assignments', ['tab' => 'list'])
            ->with('status', "Subjects assigned to {$schoolClass->name}.");
    }

    public function update(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $this->validatedAssignment($request);
        $this->replaceSessionSubjects(
            $schoolClass,
            $validated['subjectIds'],
            $validated['teacherIdsBySubject'],
            AcademicSession::current()?->id,
        );

        return redirect()
            ->route('admin.students-results.academics.subjects.assignments', ['tab' => 'list'])
            ->with('status', "Subjects assigned to {$schoolClass->name} updated.");
    }

    public function destroy(SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorize('academics.manage');

        $removed = $this->assignmentRows($schoolClass, AcademicSession::current()?->id)->delete();

        return back()->with('status', $removed > 0
            ? "Subject assignments for {$schoolClass->name} removed from this session."
            : 'No subject assignments were found for this session.');
    }

    /** @return array{subjectIds: array<int, int>, teacherIdsBySubject: array<int, int|null>} */
    private function validatedAssignment(Request $request): array
    {
        $validated = $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('subjects', 'id')->where('is_active', true),
            ],
            'teachers' => ['nullable', 'array'],
            'teachers.*' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'subject_ids.required' => 'Select at least one active subject.',
            'subject_ids.min' => 'Select at least one active subject.',
            'subject_ids.*.exists' => 'Only active subjects can be assigned.',
        ]);

        $subjectIds = array_map('intval', $validated['subject_ids']);
        $teacherIdsBySubject = [];

        foreach ($validated['teachers'] ?? [] as $subjectId => $teacherId) {
            if ($teacherId === null || $teacherId === '') {
                continue;
            }

            $teacherIdsBySubject[(int) $subjectId] = (int) $teacherId;
        }

        $requestedTeacherIds = array_values(array_unique(array_values($teacherIdsBySubject)));
        $eligibleTeacherIds = User::query()
            ->active()
            ->role('Teacher')
            ->whereKey($requestedTeacherIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (array_diff($requestedTeacherIds, $eligibleTeacherIds) !== []) {
            throw ValidationException::withMessages([
                'teachers' => 'Choose an active staff account with the Teacher role.',
            ]);
        }

        foreach ($subjectIds as $subjectId) {
            $teacherIdsBySubject[$subjectId] ??= null;
        }

        return [
            'subjectIds' => $subjectIds,
            'teacherIdsBySubject' => $teacherIdsBySubject,
        ];
    }

    /** @param array<int, int> $subjectIds
     * @param  array<int, int|null>  $teacherIdsBySubject
     */
    private function replaceSessionSubjects(
        SchoolClass $schoolClass,
        array $subjectIds,
        array $teacherIdsBySubject,
        ?int $academicSessionId,
    ): void {
        DB::transaction(function () use ($schoolClass, $subjectIds, $teacherIdsBySubject, $academicSessionId): void {
            $currentRows = $this->assignmentRows($schoolClass, $academicSessionId);
            $currentSubjectIds = (clone $currentRows)
                ->pluck('subject_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $inactiveSubjectIds = DB::table('class_subject')
                ->join('subjects', 'subjects.id', '=', 'class_subject.subject_id')
                ->where('class_subject.school_class_id', $schoolClass->id)
                ->when(
                    $academicSessionId === null,
                    fn (QueryBuilder $query) => $query->whereNull('class_subject.academic_session_id'),
                    fn (QueryBuilder $query) => $query->where('class_subject.academic_session_id', $academicSessionId),
                )
                ->where('subjects.is_active', false)
                ->pluck('class_subject.subject_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $requestedSubjectIds = array_values(array_unique($subjectIds));
            $subjectIds = array_values(array_unique([...$requestedSubjectIds, ...$inactiveSubjectIds]));
            $removedSubjectIds = array_diff($currentSubjectIds, $subjectIds);

            if ($removedSubjectIds !== []) {
                (clone $currentRows)->whereIn('subject_id', $removedSubjectIds)->delete();
            }

            $addedSubjectIds = array_diff($subjectIds, $currentSubjectIds);
            $timestamp = now();

            foreach (array_intersect($requestedSubjectIds, $currentSubjectIds) as $subjectId) {
                (clone $currentRows)
                    ->where('subject_id', $subjectId)
                    ->update([
                        'teacher_id' => $teacherIdsBySubject[$subjectId] ?? null,
                        'updated_at' => $timestamp,
                    ]);
            }

            if ($addedSubjectIds === []) {
                return;
            }

            foreach ($addedSubjectIds as $subjectId) {
                DB::table('class_subject')->insert([
                    'school_class_id' => $schoolClass->id,
                    'subject_id' => $subjectId,
                    'academic_session_id' => $academicSessionId,
                    'teacher_id' => $teacherIdsBySubject[$subjectId] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        });
    }

    private function assignmentRows(SchoolClass $schoolClass, ?int $academicSessionId): QueryBuilder
    {
        $query = DB::table('class_subject')->where('school_class_id', $schoolClass->id);

        return $academicSessionId === null
            ? $query->whereNull('academic_session_id')
            : $query->where('academic_session_id', $academicSessionId);
    }

    private function constrainToSession(BelongsToMany $subjects, ?int $academicSessionId): void
    {
        if ($academicSessionId === null) {
            $subjects->wherePivotNull('academic_session_id');

            return;
        }

        $subjects->wherePivot('academic_session_id', $academicSessionId);
    }
}

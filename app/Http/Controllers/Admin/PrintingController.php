<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdmissionDecisionStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\Score;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The paper that leaves the building, in one place.
 *
 * Every one of these documents already existed, but scattered: the merit list, the
 * letters and the waiting list hang off the cutoff desk, the admit cards off the
 * examination, and the applicant list is its own page. So "where do I print the
 * admission list" was a question about this program rather than about the school —
 * the office had to know which desk produced the paper before it could print it.
 *
 * This page is the answer. Nothing is printed from here: the links go to the pages
 * that already own the documents, and each one is shown only to somebody who may
 * open it, so the page cannot offer a link that answers 403.
 */
class PrintingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('admissions.view');

        $session = AcademicSession::current();

        $exams = Exam::query()
            ->with('level')
            ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->get();

        $exam = $request->filled('exam')
            ? $exams->firstWhere('id', $request->integer('exam'))
            : $exams->first();

        return view('admin.admissions.printing', [
            'session' => $session,
            'exams' => $exams,
            'exam' => $exam,
            'applicants' => Applicant::query()->count(),
            'counts' => $exam ? $this->counts($exam) : null,
        ]);
    }

    /**
     * How much paper each document comes to, so the office knows before it prints
     * whether it is four letters or four hundred.
     *
     * @return array{admitted:int,deferred:int,sat:int}
     */
    private function counts(Exam $exam): array
    {
        $decisions = AdmissionDecision::query()
            ->where('exam_id', $exam->id)
            ->selectRaw('decision, COUNT(*) as total')
            ->groupBy('decision')
            ->pluck('total', 'decision');

        return [
            'admitted' => (int) $decisions->get(AdmissionDecisionStatus::Admitted->value, 0),
            'deferred' => (int) $decisions->get(AdmissionDecisionStatus::Deferred->value, 0),
            // The candidates the admit cards are for: whoever has a mark on this paper.
            'sat' => Score::query()->where('exam_id', $exam->id)->distinct()->count('applicant_id'),
        ];
    }
}

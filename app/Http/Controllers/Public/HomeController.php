<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AdmissionSetting;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Models\Subject;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $session = AcademicSession::current();

        $levels = SchoolLevel::query()->active()->get();

        $cutoffs = $session
            ? AdmissionSetting::query()
                ->with('level')
                ->where('academic_session_id', $session->id)
                ->where('is_active', true)
                ->get()
                ->sortBy(fn ($setting) => $setting->level?->order)
            : collect();

        $exam = Exam::query()
            ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
            ->whereIn('status', ['scheduled', 'ongoing', 'draft', 'marking'])
            ->orderBy('exam_date')
            ->first();

        return view('public.home', [
            'session' => $session,
            'levels' => $levels,
            'cutoffs' => $cutoffs,
            'exam' => $exam,
            'subjectCount' => Subject::query()->where('is_active', true)->count(),
            // `registrationOpen` is supplied by the global view composer, so the
            // public copy and the switch can never drift apart.
        ]);
    }
}

<?php

namespace App\Services\Scores;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\GradeScale;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writing marks onto a script — the one place that decides what a typed value
 * means and who is allowed to change what.
 *
 * Both the single-subject screen and the grid go through here, so a score cannot
 * be treated differently depending on which screen it was typed on.
 */
class ScoreEntryService
{
    /**
     * Shorthand a clerk can type instead of hunting for a checkbox.
     *
     * Office staff key marks straight off a paper sheet, where an absent
     * candidate is usually a dash or an "X".
     */
    public const ABSENT_MARKERS = ['A', 'ABS', 'AB', 'X', '-', '--', 'N/A', 'NA'];

    /**
     * Read one typed cell.
     *
     * @return array{ok:bool,value:float|null,absent:bool,message:string|null}
     */
    public function parse(mixed $raw, float $max): array
    {
        $text = strtoupper(trim((string) $raw));

        // Blank means "not marked yet" — different from absent, and different
        // from a zero, so it is never treated as a mark.
        if ($text === '') {
            return ['ok' => true, 'value' => null, 'absent' => false, 'message' => null];
        }

        if (in_array($text, self::ABSENT_MARKERS, true)) {
            return ['ok' => true, 'value' => null, 'absent' => true, 'message' => null];
        }

        if (! is_numeric($text)) {
            return [
                'ok' => false,
                'value' => null,
                'absent' => false,
                'message' => 'Enter a mark, or A for absent.',
            ];
        }

        $value = (float) $text;

        if ($value < 0) {
            return ['ok' => false, 'value' => null, 'absent' => false, 'message' => 'A mark cannot be negative.'];
        }

        if ($value > $max) {
            return [
                'ok' => false,
                'value' => null,
                'absent' => false,
                'message' => 'The highest mark for this paper is ' . $this->trim($max) . '.',
            ];
        }

        return ['ok' => true, 'value' => $value, 'absent' => false, 'message' => null];
    }

    /**
     * Save a batch of typed cells.
     *
     * @param  array<int|string,mixed>  $cells  score row id => what was typed
     * @return array{saved:int,unchanged:int,errors:array<string,string>}
     */
    public function save(Exam $exam, array $cells, User $user, bool $canOverride): array
    {
        $subjects = $exam->examSubjects()->get()->keyBy('id');

        $rows = Score::query()
            ->where('exam_id', $exam->id)
            ->whereIn('id', array_map('intval', array_keys($cells)))
            ->get()
            ->keyBy('id');

        $errors = [];
        $writes = [];
        $unchanged = 0;

        // A score is trusted on sight if the person typing it is also allowed to
        // verify. A data-entry clerk's work waits for the exam officer instead.
        $mayVerify = $user->can('scores.verify');

        foreach ($cells as $id => $raw) {
            $score = $rows->get((int) $id);
            $subject = $score ? $subjects->get($score->exam_subject_id) : null;

            if (! $score || ! $subject) {
                continue;
            }

            $key = 'scores.' . $score->id;

            $parsed = $this->parse($raw, (float) $subject->total_marks);

            if (! $parsed['ok']) {
                $errors[$key] = $parsed['message'];

                continue;
            }

            if (! $this->differs($score, $parsed)) {
                $unchanged++;

                continue;
            }

            // Changing a mark somebody has already signed off is a correction,
            // not data entry, and needs the override permission.
            if ($score->isVerified() && ! $canOverride) {
                $errors[$key] = 'Already verified by '
                    . ($score->verifiedBy?->name ?? 'the exam officer')
                    . ' — you do not have permission to change it.';

                continue;
            }

            $writes[] = ['score' => $score, 'subject' => $subject, 'parsed' => $parsed];
        }

        if ($errors !== []) {
            return ['saved' => 0, 'unchanged' => 0, 'errors' => $errors];
        }

        if ($writes === []) {
            return ['saved' => 0, 'unchanged' => $unchanged, 'errors' => []];
        }

        DB::transaction(function () use ($writes, $exam, $user, $mayVerify) {
            foreach ($writes as $write) {
                /** @var Score $score */
                $score = $write['score'];
                /** @var ExamSubject $subject */
                $subject = $write['subject'];
                $parsed = $write['parsed'];

                $score->forceFill([
                    'score' => $parsed['absent'] ? null : $parsed['value'],
                    'is_absent' => $parsed['absent'],
                    'grade' => $parsed['absent'] || $parsed['value'] === null
                        ? null
                        : GradeScale::gradeLetterFor($this->percentage($parsed['value'], (float) $subject->total_marks)),
                    'source' => ScoreSource::Manual,
                    'entered_by' => $user->id,
                    'verified_by' => $mayVerify ? $user->id : null,
                    'verified_at' => $mayVerify ? now() : null,
                ])->save();
            }

            $this->advanceStatuses($exam);
        });

        return ['saved' => count($writes), 'unchanged' => $unchanged, 'errors' => []];
    }

    /** Marks recorded against a paper mean the candidate has now sat it. */
    protected function advanceStatuses(Exam $exam): void
    {
        $applicantIds = Score::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('score')
            ->pluck('applicant_id')
            ->unique();

        if ($applicantIds->isNotEmpty()) {
            Applicant::query()
                ->whereIn('id', $applicantIds)
                ->whereIn('status', [
                    ApplicantStatus::Registered->value,
                    ApplicantStatus::ExamScheduled->value,
                ])
                ->update(['status' => ApplicantStatus::ExamCompleted->value]);
        }

        if (in_array($exam->status, [ExamStatus::Scheduled, ExamStatus::Ongoing], true)) {
            $exam->update(['status' => ExamStatus::Marking]);
        }
    }

    /**
     * Has anything actually changed? Keeps an untouched row from being stamped
     * with a new "entered by" every time somebody saves the whole class.
     *
     * @param  array{ok:bool,value:float|null,absent:bool,message:string|null}  $parsed
     */
    protected function differs(Score $score, array $parsed): bool
    {
        if ($parsed['absent'] !== (bool) $score->is_absent) {
            return true;
        }

        if ($parsed['absent']) {
            return false;
        }

        if ($parsed['value'] === null) {
            return $score->score !== null || $score->is_absent;
        }

        return $score->score === null || (float) $score->score !== $parsed['value'];
    }

    public function percentage(?float $value, float $max): float
    {
        return $max > 0 ? round((((float) $value) / $max) * 100, 2) : 0.0;
    }

    /**
     * Marks that still need a human signature on this examination.
     *
     * A row nobody has marked yet is deliberately excluded: "not marked" is not
     * the same as "verified as blank", and signing off an empty row would let a
     * missing mark look checked.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Score>
     */
    protected function awaitingQuery(Exam $exam)
    {
        return Score::query()
            ->where('exam_id', $exam->id)
            ->whereNull('verified_at')
            ->where(fn ($query) => $query->whereNotNull('score')->orWhere('is_absent', true));
    }

    /** @return \Illuminate\Support\Collection<int,Score> */
    public function awaitingVerification(Exam $exam)
    {
        return $this->awaitingQuery($exam)
            ->with(['applicant.levelAppliedFor', 'examSubject.subject', 'enteredBy'])
            ->get()
            ->sortBy(fn (Score $score) => sprintf(
                '%s-%s',
                str_pad((string) ($score->examSubject?->subject?->name ?? ''), 40),
                $score->applicant?->registration_number ?? '',
            ))
            ->values();
    }

    public function awaitingCount(Exam $exam): int
    {
        return $this->awaitingQuery($exam)->count();
    }

    /**
     * Sign off marks, which is how a machine-read sheet stops being a guess.
     *
     * @param  array<int,int|string>  $scoreIds
     * @return int  how many rows were actually signed off
     */
    public function verify(Exam $exam, array $scoreIds, User $user): int
    {
        $ids = array_values(array_unique(array_map('intval', $scoreIds)));

        if ($ids === []) {
            return 0;
        }

        return $this->awaitingQuery($exam)
            ->whereIn('id', $ids)
            ->update([
                'verified_by' => $user->id,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Take the verification back off a mark.
     *
     * Needed because a verification is only ever wrong in one direction: somebody
     * accepted a mis-read number too quickly, and the mark has to go back into the
     * queue rather than be quietly re-typed under a signature that no longer means
     * anything.
     */
    public function unverify(Exam $exam, array $scoreIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $scoreIds)));

        if ($ids === []) {
            return 0;
        }

        return Score::query()
            ->where('exam_id', $exam->id)
            ->whereIn('id', $ids)
            ->whereNotNull('verified_at')
            ->update([
                'verified_by' => null,
                'verified_at' => null,
                'updated_at' => now(),
            ]);
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}

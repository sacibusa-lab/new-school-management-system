<?php

namespace App\Services\Import;

use App\Contracts\ScoresheetExtractor;
use App\Enums\ImportDriver;
use App\Enums\ScoreImportRowStatus;
use App\Enums\ScoreImportStatus;
use App\Enums\ScoreSource;
use App\Exceptions\ScoresheetExtractionException;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\GradeScale;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ScoreImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an uploaded scoresheet into reviewed, committed scores.
 *
 * Three deliberate stages:
 *   1. store()   — keep the file and create a ScoreImport record
 *   2. process() — extract rows, match them to applicants, flag anything unsure
 *   3. commit()  — write only the rows a human has accepted
 *
 * Nothing is written to `scores` before stage 3.
 */
class ScoreImportService
{
    public function __construct(
        private readonly SpreadsheetScoresheetExtractor $spreadsheet,
        private readonly AiVisionScoresheetExtractor $ai,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Stage 1 — accept the upload                                         */
    /* ------------------------------------------------------------------ */

    public function store(Exam $exam, ?ExamSubject $examSubject, UploadedFile $file, User $user): ScoreImport
    {
        $path = $file->store(config('saci.uploads.scoresheets'), 'local');

        return ScoreImport::create([
            'exam_id' => $exam->id,
            'exam_subject_id' => $examSubject?->id,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'driver' => $this->driverFor($file)->value,
            'status' => ScoreImportStatus::Pending,
            'uploaded_by' => $user->id,
        ]);
    }

    public function driverFor(UploadedFile $file): ImportDriver
    {
        $extension = $file->getClientOriginalExtension();
        $mime = (string) $file->getClientMimeType();

        if ($this->spreadsheet->supports($mime, $extension)) {
            return ImportDriver::Spreadsheet;
        }

        if ($this->ai->supports($mime, $extension)) {
            return ImportDriver::AiVision;
        }

        // An image with no AI key configured still gets recorded, so the officer
        // can see it and fall back to manual entry.
        return ImportDriver::ManualGrid;
    }

    /* ------------------------------------------------------------------ */
    /* Stage 2 — read and match                                            */
    /* ------------------------------------------------------------------ */

    public function process(ScoreImport $import): ScoreImport
    {
        $import->loadMissing(['exam.examSubjects.subject', 'examSubject.subject', 'exam']);

        if ($import->status === ScoreImportStatus::Committed) {
            throw new \RuntimeException('This import has already been committed.');
        }

        $import->update(['status' => ScoreImportStatus::Parsing, 'error' => null]);

        $extractor = $this->extractorFor($import);

        $context = [
            'subject' => $import->examSubject?->subject?->name,
            'total_marks' => (float) ($import->examSubject?->total_marks ?? 100),
            'known_identifiers' => $this->knownIdentifiers($import->exam),
        ];

        try {
            $rows = $extractor->extract($this->absolutePath($import), $context);
        } catch (ScoresheetExtractionException $e) {
            $import->update(['status' => ScoreImportStatus::Failed, 'error' => $e->getMessage()]);

            return $import->refresh();
        }

        if ($rows === []) {
            $import->update([
                'status' => ScoreImportStatus::Failed,
                'error' => 'No student rows could be read from this file. Check that it has a header row naming the student and the score.',
            ]);

            return $import->refresh();
        }

        DB::transaction(function () use ($import, $rows, $extractor) {
            // Re-processing replaces earlier attempts.
            $import->rows()->delete();

            $matcher = $this->buildMatcher($import);

            foreach ($rows as $row) {
                $created = $import->rows()->create([
                    'row_number' => $row['row'],
                    'raw' => $row['raw'] ?? null,
                    'raw_identifier' => $row['identifier'] ?? null,
                    'raw_name' => $row['name'] ?? null,
                    'raw_subject' => $row['subject'] ?? null,
                    'raw_score' => $row['score'] ?? null,
                    'confidence' => $row['confidence'] ?? null,
                ]);

                $this->resolve($created, $matcher);
            }

            $import->forceFill([
                'status' => ScoreImportStatus::NeedsReview,
                'meta' => array_merge($import->meta ?? [], [
                    'driver' => $extractor->driver()->value,
                    'description' => $extractor->describe(),
                    'processed_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $import->refreshCounters();
        });

        return $import->refresh();
    }

    protected function extractorFor(ScoreImport $import): ScoresheetExtractor
    {
        $extension = pathinfo($import->original_name, PATHINFO_EXTENSION);
        $mime = (string) $import->mime_type;

        if ($import->driver === ImportDriver::AiVision) {
            if (! $this->ai->isConfigured()) {
                throw ScoresheetExtractionException::notConfigured();
            }

            return $this->ai;
        }

        if ($this->spreadsheet->supports($mime, $extension)) {
            return $this->spreadsheet;
        }

        if ($this->ai->supports($mime, $extension)) {
            return $this->ai;
        }

        throw new ScoresheetExtractionException(
            'This file type cannot be read automatically yet. Please upload an Excel/CSV file, or type the scores in by hand.'
        );
    }

    protected function absolutePath(ScoreImport $import): string
    {
        $path = Storage::disk('local')->path($import->file_path);

        if (! is_file($path)) {
            throw new ScoresheetExtractionException('The uploaded file is no longer available on the server.');
        }

        return $path;
    }

    /* ------------------------------------------------------------------ */
    /* Matching                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Pre-load the candidate list once and build lookup maps, so matching
     * hundreds of rows stays a single query.
     *
     * @return array{byIdentifier:array<string,Applicant>,byName:array<string,Applicant>,candidates:array<int,Applicant>,names:array<string,int>}
     */
    protected function buildMatcher(ScoreImport $import): array
    {
        $candidates = Applicant::query()
            ->where('academic_session_id', $import->exam->academic_session_id)
            ->when($import->exam->level_id, fn ($q) => $q->where('level_applied_for_id', $import->exam->level_id))
            ->get();

        $byIdentifier = [];
        $byName = [];
        $names = [];

        foreach ($candidates as $candidate) {
            $byIdentifier[$this->normaliseIdentifier($candidate->registration_number)] = $candidate;

            $nameKey = $this->normaliseName($candidate->full_name);

            if ($nameKey !== '') {
                $byName[$nameKey] = $candidate;
                $names[$nameKey] = $candidate->id;
            }
        }

        return [
            'byIdentifier' => $byIdentifier,
            'byName' => $byName,
            'candidates' => $candidates->keyBy('id')->all(),
            'names' => $names,
        ];
    }

    /**
     * Decide what a single parsed row is, and record why.
     *
     * @param  array<string,mixed>  $matcher
     */
    public function resolve(ScoreImportRow $row, array $matcher): ScoreImportRow
    {
        $import = $row->import;

        // ---- 1. the score itself must make sense -------------------------
        $examSubject = $row->exam_subject_id
            ? ExamSubject::find($row->exam_subject_id)
            : $import->examSubject;

        if (! $examSubject && $row->raw_subject) {
            $examSubject = $this->subjectFromLabel($import->exam, (string) $row->raw_subject);
        }

        $row->exam_subject_id = $examSubject?->id;

        $max = (float) ($examSubject?->total_marks ?? 100);

        if ($row->raw_score !== null && ((float) $row->raw_score < 0 || (float) $row->raw_score > $max)) {
            return $this->flag($row, ScoreImportRowStatus::Invalid, "Score {$row->raw_score} is outside the allowed range of 0–{$max}.");
        }

        // ---- 2. who is this? ---------------------------------------------
        $applicant = null;
        $confidence = $row->confidence;
        $message = null;

        if ($row->raw_identifier) {
            $applicant = $matcher['byIdentifier'][$this->normaliseIdentifier($row->raw_identifier)] ?? null;

            if ($applicant) {
                $confidence ??= 1.0;
                $message = 'Matched on registration number.';
            }
        }

        if (! $applicant && $row->raw_name) {
            $nameKey = $this->normaliseName($row->raw_name);
            $applicant = $matcher['byName'][$nameKey] ?? null;

            if ($applicant) {
                $confidence ??= 0.95;
                $message = 'Matched on full name.';
            } else {
                [$best, $score] = $this->bestFuzzyMatch($nameKey, $matcher['names'], $matcher['candidates']);

                if ($best && $score >= 0.86) {
                    $applicant = $best;
                    $confidence = min($confidence ?? 0.86, 0.86);
                    $message = 'Matched on a close spelling of the name — please confirm.';
                } elseif ($best && $score >= 0.62) {
                    return $this->flag(
                        $row,
                        ScoreImportRowStatus::Ambiguous,
                        sprintf(
                            'Possible match with %s (%s) at %d%% similarity — confirm or pick another student.',
                            $best->full_name,
                            $best->registration_number,
                            (int) round($score * 100),
                        ),
                        ['matched_applicant_id' => $best->id, 'match_confidence' => round($score, 2)],
                    );
                }
            }
        }

        if (! $applicant) {
            return $this->flag(
                $row,
                ScoreImportRowStatus::Unmatched,
                'No student in this examination matches this row. Search for the student manually.',
            );
        }

        // ---- 3. has this already been captured? ---------------------------
        $existing = $examSubject
            ? Score::query()
                ->where('exam_subject_id', $examSubject->id)
                ->where('applicant_id', $applicant->id)
                ->first()
            : null;

        if ($existing && $existing->score !== null) {
            return $this->flag(
                $row,
                ScoreImportRowStatus::Duplicate,
                sprintf(
                    'A score of %s already exists for this student (entered from %s). Review before replacing.',
                    $existing->score,
                    $existing->source->label(),
                ),
                [
                    'matched_applicant_id' => $applicant->id,
                    'match_confidence' => $confidence,
                ],
            );
        }

        return $this->flag(
            $row,
            ScoreImportRowStatus::Matched,
            $message ?? 'Matched.',
            [
                'matched_applicant_id' => $applicant->id,
                'match_confidence' => $confidence,
            ],
        );
    }

    /**
     * Record the outcome of matching a row, without writing any score.
     *
     * @param  array<string,mixed>  $extra
     */
    protected function flag(ScoreImportRow $row, ScoreImportRowStatus $status, string $message, array $extra = []): ScoreImportRow
    {
        $row->forceFill($extra + [
            'status' => $status,
            'message' => $message,
        ])->save();

        return $row;
    }

    /**
     * @param  array<string,int>  $names
     * @param  array<int,Applicant>  $candidates
     * @return array{0:?Applicant,1:float}
     */
    protected function bestFuzzyMatch(string $needle, array $names, array $candidates): array
    {
        if ($needle === '') {
            return [null, 0.0];
        }

        $bestId = null;
        $bestScore = 0.0;

        foreach ($names as $candidateName => $candidateId) {
            similar_text($needle, $candidateName, $percent);

            $score = $percent / 100;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $candidateId;

                if ($score >= 0.99) {
                    break;
                }
            }
        }

        return [$bestId ? ($candidates[$bestId] ?? null) : null, $bestScore];
    }

    protected function subjectFromLabel(Exam $exam, string $label): ?ExamSubject
    {
        $needle = strtolower(trim($label));

        return $exam->examSubjects()
            ->with('subject')
            ->get()
            ->first(function (ExamSubject $examSubject) use ($needle) {
                $name = strtolower($examSubject->subject?->name ?? '');
                $code = strtolower($examSubject->subject?->code ?? '');

                return $name === $needle
                    || $code === $needle
                    || ($name !== '' && str_contains($name, $needle))
                    || ($needle !== '' && str_contains($needle, $name));
            });
    }

    protected function normaliseIdentifier(?string $value): string
    {
        return strtoupper(str_replace([' ', '-', '/', '_'], '', (string) $value));
    }

    protected function normaliseName(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z ]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        // Drop common titles so "Mr Adewale John" matches "Adewale John".
        foreach (['mr ', 'mrs ', 'miss ', 'master ', 'dr ', 'chief ', 'alhaji ', 'hajia '] as $title) {
            $value = str_replace($title, '', $value);
        }

        return trim($value);
    }

    /**
     * Every registration number in the exam, handed to the AI so it can correct
     * misread digits against the real roster.
     *
     * @return array<int,string>
     */
    protected function knownIdentifiers(Exam $exam): array
    {
        return Applicant::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->when($exam->level_id, fn ($q) => $q->where('level_applied_for_id', $exam->level_id))
            ->orderBy('registration_number')
            ->pluck('registration_number')
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Stage 3 — commit                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Write every accepted row into `scores`. Rows the reviewer skipped are
     * left untouched.
     *
     * @return array{written:int,skipped:int}
     */
    public function commit(ScoreImport $import, User $user): array
    {
        $import->loadMissing('exam');

        $written = 0;
        $skipped = 0;

        DB::transaction(function () use ($import, $user, &$written, &$skipped) {
            $rows = $import->rows()
                ->with('examSubject')
                ->whereIn('status', [
                    ScoreImportRowStatus::Matched->value,
                    ScoreImportRowStatus::Ambiguous->value,
                ])
                ->get();

            foreach ($rows as $row) {
                if (! $row->matched_applicant_id || ! $row->exam_subject_id) {
                    $skipped++;

                    continue;
                }

                $examSubject = $row->examSubject;
                $max = (float) ($examSubject?->total_marks ?? 100);
                $score = $row->raw_score === null ? null : (float) $row->raw_score;

                Score::updateOrCreate(
                    [
                        'exam_subject_id' => $row->exam_subject_id,
                        'applicant_id' => $row->matched_applicant_id,
                    ],
                    [
                        'exam_id' => $row->import->exam_id,
                        'score' => $score,
                        'is_absent' => $score === null,
                        'grade' => $score === null
                            ? null
                            : GradeScale::gradeLetterFor(round(($score / max($max, 1)) * 100, 2)),
                        'source' => $import->driver->scoreSource(),
                        'confidence' => $row->confidence,
                        'entered_by' => $user->id,
                        // Committing IS the human verification step.
                        'verified_by' => $user->id,
                        'verified_at' => now(),
                        'notes' => 'Imported from ' . $import->original_name,
                    ],
                );

                $row->update(['status' => ScoreImportRowStatus::Committed]);
                $written++;
            }

            $import->forceFill([
                'status' => ScoreImportStatus::Committed,
                'committed_by' => $user->id,
                'committed_at' => now(),
            ])->save();

            $import->refreshCounters();

            // Anyone with a committed score has sat the paper.
            $applicantIds = Score::query()
                ->where('exam_id', $import->exam_id)
                ->whereNotNull('score')
                ->distinct()
                ->pluck('applicant_id');

            Applicant::query()
                ->whereIn('id', $applicantIds)
                ->whereIn('status', [
                    \App\Enums\ApplicantStatus::Registered->value,
                    \App\Enums\ApplicantStatus::ExamScheduled->value,
                ])
                ->update(['status' => \App\Enums\ApplicantStatus::ExamCompleted->value]);

            if (in_array($import->exam->status, [\App\Enums\ExamStatus::Scheduled, \App\Enums\ExamStatus::Ongoing], true)) {
                $import->exam->update(['status' => \App\Enums\ExamStatus::Marking]);
            }
        });

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * A hand-corrected row from the review screen.
     *
     * @param  array<string,mixed>  $changes
     */
    public function applyCorrection(ScoreImportRow $row, array $changes): ScoreImportRow
    {
        $row->fill(array_intersect_key($changes, array_flip([
            'raw_name', 'raw_identifier', 'raw_score', 'matched_applicant_id', 'exam_subject_id',
        ])));

        // A manual correction is trusted, so clear the machine's doubt.
        $row->status = ScoreImportRowStatus::Matched;
        $row->message = 'Corrected by hand.';
        $row->confidence = 1.0;
        $row->match_confidence = 1.0;
        $row->save();

        return $row;
    }

    public function ignore(ScoreImportRow $row, string $reason = 'Skipped by the exam officer.'): ScoreImportRow
    {
        $row->forceFill([
            'status' => ScoreImportRowStatus::Ignored,
            'message' => $reason,
        ])->save();

        return $row;
    }

    public function summary(ScoreImport $import): array
    {
        $counts = $import->rows()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'ready' => (int) ($counts[ScoreImportRowStatus::Matched->value] ?? 0),
            'ambiguous' => (int) ($counts[ScoreImportRowStatus::Ambiguous->value] ?? 0),
            'unmatched' => (int) ($counts[ScoreImportRowStatus::Unmatched->value] ?? 0),
            'duplicate' => (int) ($counts[ScoreImportRowStatus::Duplicate->value] ?? 0),
            'invalid' => (int) ($counts[ScoreImportRowStatus::Invalid->value] ?? 0),
            'ignored' => (int) ($counts[ScoreImportRowStatus::Ignored->value] ?? 0),
            'committed' => (int) ($counts[ScoreImportRowStatus::Committed->value] ?? 0),
        ];
    }
}

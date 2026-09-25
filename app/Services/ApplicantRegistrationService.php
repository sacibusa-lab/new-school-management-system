<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\PipelineEvent;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stage 1 of the pipeline: turning a public form submission into a registered
 * applicant holding a permanent `SAC-00001` style number.
 */
class ApplicantRegistrationService
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly SmsNotifier $sms,
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,UploadedFile|null>  $files
     * @param  bool  $notify  false for bulk work — sending from a loop would hold
     *                        the request open for one HTTP call per row.
     */
    public function register(array $data, array $files = [], bool $notify = true): Applicant
    {
        $applicant = DB::transaction(function () use ($data, $files) {
            $session = $this->resolveSession();

            $applicant = new Applicant($this->onlyFillable($data));

            $applicant->academic_session_id = $session->id;
            $applicant->registration_number = $this->sequences->nextAdmissionRegistrationNumber();
            $applicant->status = ApplicantStatus::Registered;
            $applicant->submitted_at = now();

            if ($photo = $files['photo'] ?? null) {
                $applicant->photo_path = $photo->store(
                    config('saci.uploads.photos') . '/applicants',
                    'public',
                );
            }

            $applicant->documents = $this->storeDocuments($files['documents'] ?? []);

            $applicant->save();

            PipelineEvent::record('applicant.registered', $applicant, [
                'registration_number' => $applicant->registration_number,
                'session' => $session->name,
                'level' => $applicant->levelAppliedFor?->name,
            ]);

            ActivityLog::record(
                'applicant.registered',
                $applicant,
                "Registered applicant {$applicant->registration_number}",
                ['module' => 'admissions'],
            );

            return $applicant;
        });

        // Sent after the transaction commits: an HTTP call must never hold a
        // database lock, and a dead gateway must never fail a registration.
        if ($notify) {
            $this->sms->applicantRegistered($applicant);
        }

        return $applicant;
    }

    /**
     * The session admissions is currently open for. Falls back to the current
     * session so registration never dead-ends on a fresh install.
     */
    protected function resolveSession(): AcademicSession
    {
        $session = AcademicSession::query()->where('is_admission_open', true)->first()
            ?? AcademicSession::current();

        if (! $session) {
            throw new \RuntimeException('No academic session exists. Run `php artisan db:seed` first.');
        }

        return $session;
    }

    /**
     * @param  array<int,UploadedFile>  $documents
     * @return array<int,array<string,string>>|null
     */
    protected function storeDocuments(array $documents): ?array
    {
        $stored = [];

        foreach ($documents as $document) {
            if (! $document instanceof UploadedFile) {
                continue;
            }

            $stored[] = [
                'name' => $document->getClientOriginalName(),
                'path' => $document->store(config('saci.uploads.documents') . '/applicants', 'public'),
                'size' => (string) $document->getSize(),
            ];
        }

        return $stored ?: null;
    }

    /**
     * Only let known applicant fields through — never trust the request shape.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function onlyFillable(array $data): array
    {
        $allowed = [
            'first_name', 'middle_name', 'last_name',
            'gender', 'date_of_birth', 'nationality',
            'email', 'phone', 'address', 'city', 'state', 'lga',
            'previous_school', 'level_applied_for_id',
            'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email', 'guardian_address',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * Optionally attach a login to an existing applicant record.
     */
    public function attachAccount(Applicant $applicant, User $user): Applicant
    {
        $applicant->forceFill(['user_id' => $user->id])->save();

        return $applicant;
    }
}

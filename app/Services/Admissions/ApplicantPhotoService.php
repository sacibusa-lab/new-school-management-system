<?php

namespace App\Services\Admissions;

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\Setting;
use App\Models\User;
use App\Support\RegistrationNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photographs of applicants.
 *
 * Names arrive in a spreadsheet, but photographs do not — so they have to be
 * added afterwards, and a school with three hundred candidates will not do that
 * one at a time. The batch path matches each file to a child by the number in
 * its file name ("SAC-00001.jpg"), shows what it worked out BEFORE anything is
 * attached, and only then writes it.
 *
 * Matching a photograph to the wrong child is the failure that matters here: an
 * exam-day identity check would be against the wrong face. So an unmatched file
 * is reported, never guessed at.
 */
class ApplicantPhotoService
{
    /** A batch bigger than this is a mistake, not a term's intake. */
    public const MAX_FILES = 300;

    /** 5 MB, which is generous for a passport photograph. */
    public const MAX_KB = 5120;

    public const EXTENSIONS = 'jpg,jpeg,png,webp';

    /** Where a batch waits between the preview and the commit. */
    private const STAGING = 'photo-staging';

    public const SESSION_KEY = 'applicant_photos';

    /**
     * Attach one photograph, replacing whatever was there.
     *
     * The old file is deleted rather than left behind: a replaced photograph is
     * usually a replaced photograph because somebody objected to the first one.
     */
    public function store(Applicant $applicant, UploadedFile $photo): Applicant
    {
        $previous = $applicant->photo_path;

        $applicant->forceFill([
            'photo_path' => $photo->store($this->folder(), 'public'),
        ])->save();

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        ActivityLog::record(
            'applicant.photo',
            $applicant,
            "Added a photograph for {$applicant->registration_number}",
            ['module' => 'admissions'],
        );

        return $applicant;
    }

    /** Take a photograph off an applicant, and off the disk. */
    public function remove(Applicant $applicant): Applicant
    {
        if ($applicant->photo_path) {
            Storage::disk('public')->delete($applicant->photo_path);
        }

        $applicant->forceFill(['photo_path' => null])->save();

        return $applicant;
    }

    /**
     * Read a batch of files and work out whose each one is.
     *
     * Nothing is attached here. The files are parked where they can be shown back
     * to the office, and the mapping is described so it can be reviewed first.
     *
     * @param  array<int,UploadedFile>  $files
     * @param  string  $batch  name of the parking folder for this upload
     * @return array<int,array<string,mixed>>
     */
    public function stage(array $files, string $batch): array
    {
        $index = $this->applicantIndex();
        $staged = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $name = $file->getClientOriginalName();
            $path = $file->store(self::STAGING . '/' . $batch, 'local');

            $applicant = $this->match($name, $index);

            $staged[] = [
                'path' => $path,
                'name' => $name,
                'applicant_id' => $applicant?->id,
                'registration_number' => $applicant?->registration_number,
                'applicant_name' => $applicant?->full_name,
                'had_photo' => (bool) $applicant?->photo_path,
                'problem' => $applicant
                    ? null
                    : 'No applicant has a registration number like "' . pathinfo($name, PATHINFO_FILENAME) . '".',
            ];
        }

        return $staged;
    }

    /**
     * Attach the staged photographs the office left ticked.
     *
     * @param  array<int,array<string,mixed>>  $staged
     * @param  array<int,int|string>  $chosen  indexes into $staged
     * @return array{attached:int,skipped:int,failed:array<int,string>}
     */
    public function commit(array $staged, array $chosen, ?User $actor = null): array
    {
        $attached = 0;
        $skipped = 0;
        $failed = [];

        foreach ($staged as $index => $row) {
            if (! in_array($index, array_map('intval', $chosen), true)) {
                $skipped++;

                continue;
            }

            if ($row['problem'] !== null || ! $row['applicant_id']) {
                $skipped++;

                continue;
            }

            $applicant = Applicant::find($row['applicant_id']);

            if (! $applicant) {
                $failed[] = $row['name'] . ' — that applicant no longer exists.';
                $this->forget($row['path']);

                continue;
            }

            if (! Storage::disk('local')->exists($row['path'])) {
                $failed[] = $row['name'] . ' — the upload expired. Please choose the files again.';

                continue;
            }

            $previous = $applicant->photo_path;

            $applicant->forceFill([
                'photo_path' => $this->publish($row['path'], $row['name']),
            ])->save();

            if ($previous) {
                Storage::disk('public')->delete($previous);
            }

            $this->forget($row['path']);
            $attached++;
        }

        // Whatever was not attached is not kept: a staged photograph nobody
        // claimed is a file with a child's face in it sitting on the server.
        foreach ($staged as $row) {
            $this->forget($row['path']);
        }

        if ($attached > 0) {
            ActivityLog::record(
                'applicant.photos.uploaded',
                null,
                "Attached {$attached} applicant photograph(s)" . ($actor ? " by {$actor->name}" : ''),
                ['module' => 'admissions', 'count' => $attached],
            );
        }

        return ['attached' => $attached, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** Move a parked file onto the public disk under a fresh name. */
    protected function publish(string $stagedPath, string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg');
        $target = $this->folder() . '/' . Str::random(40) . '.' . $extension;

        Storage::disk('public')->put($target, Storage::disk('local')->get($stagedPath));

        return $target;
    }

    protected function forget(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    protected function folder(): string
    {
        return config('saci.uploads.photos') . '/applicants';
    }

    /**
     * The raw bytes of a staged photograph, for the review thumbnails.
     *
     * Read from the staged path held in the session rather than from anything the
     * browser sends, so a crafted request cannot read an arbitrary file.
     */
    public function stagedContents(array $staged, int $index): ?string
    {
        $path = $staged[$index]['path'] ?? null;

        return $path && Storage::disk('local')->exists($path)
            ? Storage::disk('local')->get($path)
            : null;
    }

    /**
     * The file name without its extension, matched the same way a parent's typed
     * number is: "SAC-1", "sac00001" and "SAC/2026/1" all find their child.
     *
     * @param  array<string,Applicant>  $index
     */
    protected function match(string $fileName, array $index): ?Applicant
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);

        $padding = (int) (Setting::get('admission_number_padding') ?: 5);

        foreach (RegistrationNumber::variants($stem, $padding) as $variant) {
            if (isset($index[$variant])) {
                return $index[$variant];
            }
        }

        return null;
    }

    /**
     * Every applicant, keyed by every spelling of their number.
     *
     * @return array<string,Applicant>
     */
    protected function applicantIndex(): array
    {
        $padding = (int) (Setting::get('admission_number_padding') ?: 5);

        $index = [];

        // Newest first, so the most recent applicant wins if two numbers ever
        // normalise to the same thing.
        foreach (Applicant::query()->orderByDesc('id')->get() as $applicant) {
            foreach (RegistrationNumber::variants($applicant->registration_number, $padding) as $variant) {
                $index[$variant] ??= $applicant;
            }
        }

        return $index;
    }
}
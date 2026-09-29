<?php

namespace App\Services\Admissions;

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The papers a family brings to the office.
 *
 * A birth certificate, a baptismal card, a primary-school testimonial — these
 * arrive as paper and have to be attached to the applicant record, because
 * without them the office cannot tell a completed file from an incomplete one.
 *
 * They are kept in the applicant's own `documents` column rather than a table of
 * their own: there are a handful per child, nothing joins on them, and the
 * column was already there and already being displayed — it was simply never
 * written to, so every school would have had an empty list for ever.
 *
 * The one thing to be careful about is deletion. The path comes back from the
 * browser, so a file is only ever removed from the disk after it has been found
 * in THIS applicant's list — otherwise a crafted request could name any file on
 * the public disk and have it deleted.
 */
class ApplicantDocumentService
{
    /**
     * A scanned certificate is a bigger thing than a passport photograph, so the
     * ceiling is higher than the one for pictures.
     */
    public const MAX_KB = 10240;

    /** PDFs are the point of this: a scanned document is usually a PDF. */
    public const EXTENSIONS = 'pdf,jpg,jpeg,png,webp';

    /** Nobody brings more than a handful of papers, and a file is not a filing cabinet. */
    public const MAX_DOCUMENTS = 12;

    /** How many may be handed over in one go. */
    public const MAX_PER_UPLOAD = 5;

    /**
     * Attach one document.
     *
     * @return array<string,mixed> the entry that was added
     */
    public function store(Applicant $applicant, UploadedFile $file, ?User $actor = null): array
    {
        $documents = $this->documents($applicant);

        if (count($documents) >= self::MAX_DOCUMENTS) {
            throw new RuntimeException(sprintf(
                'This applicant already has %d documents, which is as many as this system keeps. Remove one first.',
                self::MAX_DOCUMENTS,
            ));
        }

        $document = [
            // The office recognises its own file names — "birth certificate.pdf"
            // means more than any label we could invent for it.
            'name' => $file->getClientOriginalName(),
            'path' => $file->store($this->folder(), 'public'),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_by' => $actor?->name,
        ];

        $documents[] = $document;

        $applicant->forceFill(['documents' => $documents])->save();

        ActivityLog::record(
            'applicant.document',
            $applicant,
            "Attached {$document['name']} to {$applicant->registration_number}",
            ['module' => 'admissions'],
        );

        return $document;
    }

    /**
     * Take one document off an applicant, and off the disk.
     *
     * Returns whether anything was removed. A path this applicant does not hold
     * is refused rather than ignored, so a request that names somebody else's
     * file leaves the file where it is.
     */
    public function remove(Applicant $applicant, string $path, ?User $actor = null): bool
    {
        $documents = $this->documents($applicant);
        $kept = [];
        $removed = null;

        foreach ($documents as $document) {
            if ($removed === null && ($document['path'] ?? null) === $path) {
                $removed = $document;

                continue;
            }

            $kept[] = $document;
        }

        if ($removed === null) {
            return false;
        }

        $applicant->forceFill(['documents' => $kept])->save();

        // Only now, having found the file in this applicant's own list, is it safe
        // to delete anything: the path came from the browser.
        Storage::disk('public')->delete($path);

        ActivityLog::record(
            'applicant.document',
            $applicant,
            "Removed {$removed['name']} from {$applicant->registration_number}",
            ['module' => 'admissions'],
        );

        return true;
    }

    /**
     * Everything held against this applicant, newest last.
     *
     * Anything unexpected in the column is discarded rather than trusted, so a
     * hand-edited or half-written value cannot break the page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function documents(Applicant $applicant): array
    {
        $raw = $applicant->documents;

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($document) => is_array($document) && ! empty($document['path'])));
    }

    /** Human-readable size, for the list beside the file name. */
    public function sizeFor(array $document): ?string
    {
        $bytes = (int) ($document['size'] ?? 0);

        if ($bytes <= 0) {
            return null;
        }

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** Whether the file is actually still on the disk. */
    public function exists(array $document): bool
    {
        return Storage::disk('public')->exists((string) ($document['path'] ?? ''));
    }

    protected function folder(): string
    {
        return config('saci.uploads.documents') . '/applicants';
    }
}

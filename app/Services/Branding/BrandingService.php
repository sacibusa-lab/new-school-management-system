<?php

namespace App\Services\Branding;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The school's logo and favicon — the two settings that hold a file rather than
 * typed text.
 *
 * Everything goes through here so that replacing an image always deletes the one
 * it replaced. An upload that is never cleaned up would leave the old logo sitting
 * on the server for ever, and neither the office nor anybody else would ever see
 * it to know it was there.
 */
class BrandingService
{
    public const MAX_KB = 2048;

    /** ICO because a favicon usually is one; SVG because logos usually are. */
    public const EXTENSIONS = 'png,jpg,jpeg,webp,svg,ico';

    /** Space-separated, for an `accept` attribute. */
    public const ACCEPT = '.png,.jpg,.jpeg,.webp,.svg,.ico';

    public function directory(): string
    {
        return (string) config('saci.uploads.branding', 'branding');
    }

    /**
     * Store a new image against a setting and drop the one it replaces.
     */
    public function replace(Setting $setting, UploadedFile $file): Setting
    {
        $previous = $setting->value;

        $path = $file->store($this->directory(), 'public');

        $setting->update(['value' => $path]);
        Setting::forget($setting->key);

        $this->deleteFile($previous);

        ActivityLog::record(
            'settings.branding.uploaded',
            $setting,
            ($setting->label ?? $setting->key).' replaced',
            ['module' => 'settings', 'path' => $path],
        );

        return $setting;
    }

    /**
     * Throw the image away: both the record of it and the file itself.
     */
    public function clear(Setting $setting): Setting
    {
        $previous = $setting->value;

        $setting->update(['value' => null]);
        Setting::forget($setting->key);

        $this->deleteFile($previous);

        ActivityLog::record(
            'settings.branding.removed',
            $setting,
            ($setting->label ?? $setting->key).' removed',
            ['module' => 'settings'],
        );

        return $setting;
    }

    /**
     * Delete a file this service stored.
     *
     * Guarded to our own directory: the value comes from us, but a path that
     * could be made to point elsewhere is not a thing to hand to unlink().
     */
    protected function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (! str_starts_with($path, $this->directory().'/')) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * The two letters a tile shows when there is no picture to show.
     *
     * A school's monogram and a child's are the same rule — the first letter of the first two
     * words — so they are the same method. Used by the brand mark in the sidebar and on the
     * admit cards, by the admit card's photograph fallback, and by the printed payment slip:
     * one rule means a school called St Augustine's College cannot come out as SA in the
     * sidebar and St on a slip.
     */
    public static function monogram(?string $name = null): string
    {
        $name ??= (string) Setting::get('school_name', config('saci.school_name'));

        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'SA';
    }

    /** The public URL of a stored branding image, or null when there is none. */
    public function url(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }

    /**
     * A stored image as the PDF renderer can draw it.
     *
     * A data URI, because DomPDF does not fetch images over HTTP: an `<img>` pointing at the
     * site comes out as a broken icon. Public because the admission letter needs the same
     * thing for its signature and letterhead — one place that knows how to inline an upload.
     */
    public function dataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return 'data:'.($disk->mimeType($path) ?: 'image/png').';base64,'
            .base64_encode((string) $disk->get($path));
    }

    /**
     * The school's crest, sized for the head of a payment slip.
     *
     * The proportions come from the image itself rather than from the box it is asked to fit:
     * given only a width, DomPDF will happily stretch a crest into a banner. A picture whose
     * dimensions PHP will not read — an SVG — is handed over unstyled and left to DomPDF.
     *
     * @return array{data:string,width:?string,height:?string}|null
     */
    public function logoForPdf(float $maxWidthMm = 20.0, float $maxHeightMm = 20.0): ?array
    {
        $path = Setting::get('school_logo');
        $data = $this->dataUri($path);

        if ($data === null || ! $path) {
            return null;
        }

        $size = @getimagesize(Storage::disk('public')->path($path));

        if (! $size || $size[0] < 1 || $size[1] < 1) {
            return ['data' => $data, 'width' => null, 'height' => null];
        }

        $width = $maxWidthMm;
        $height = $width * ($size[1] / $size[0]);

        if ($height > $maxHeightMm) {
            $width *= $maxHeightMm / $height;
            $height = $maxHeightMm;
        }

        return [
            'data' => $data,
            'width' => round($width, 1).'mm',
            'height' => round($height, 1).'mm',
        ];
    }
}

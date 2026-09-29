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
            ($setting->label ?? $setting->key) . ' replaced',
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
            ($setting->label ?? $setting->key) . ' removed',
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

        if (! str_starts_with($path, $this->directory() . '/')) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /** The public URL of a stored branding image, or null when there is none. */
    public function url(?string $path): ?string
    {
        return $path ? asset('storage/' . $path) : null;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Branding\BrandingService;
use App\Services\NumberSequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(NumberSequenceService $sequences): View
    {
        $this->authorize('settings.manage');

        $settings = Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group');

        return view('admin.settings.index', [
            'settings' => $settings,
            'groups' => [
                'branding' => 'School branding',
                'numbering' => 'Numbering',
                'admissions' => 'Admissions',
                'letters' => 'Admission letters',
                'messaging' => 'Text messages (SMS)',
                'fees' => 'Fees',
                'results' => 'Results',
                'general' => 'General',
            ],
            'previews' => [
                'admission' => $sequences->preview(\App\Enums\SequenceType::AdmissionRegistration),
                'student' => $sequences->preview(\App\Enums\SequenceType::StudentNumber),
                'invoice' => $sequences->preview(\App\Enums\SequenceType::Invoice),
                'receipt' => $sequences->preview(\App\Enums\SequenceType::Receipt),
            ],
        ]);
    }

    public function update(Request $request, BrandingService $branding): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.value' => ['nullable', 'string', 'max:2000'],
            'settings.*.file' => ['nullable', 'file', 'mimes:' . BrandingService::EXTENSIONS, 'max:' . BrandingService::MAX_KB],
            'settings.*.remove' => ['nullable', 'boolean'],
        ], [
            'settings.*.file.mimes' => 'That file is not an image the browser can show. Use PNG, JPG, WEBP, SVG or ICO.',
            'settings.*.file.max' => 'That image is larger than ' . round(BrandingService::MAX_KB / 1024) . ' MB.',
        ]);

        foreach ($validated['settings'] as $key => $payload) {
            $setting = Setting::query()->where('key', $key)->first();

            if (! $setting) {
                continue;
            }

            // An image setting holds the path of a stored file. A new upload
            // replaces it and the remove box clears it — and saving the rest of
            // the page without touching it must leave it exactly as it was, not
            // blank it because the form carried no text value for it.
            if ($setting->type === 'image') {
                $file = $payload['file'] ?? null;

                if ($file instanceof UploadedFile) {
                    $branding->replace($setting, $file);
                } elseif (! empty($payload['remove'])) {
                    $branding->clear($setting);
                }

                continue;
            }

            $value = $payload['value'] ?? null;

            // Checkboxes arrive as "on" only when ticked.
            if ($setting->type === 'bool') {
                $value = $value ? '1' : '0';
            }

            // JSON settings are edited as a comma-separated list for convenience.
            if ($setting->type === 'json') {
                $decoded = json_decode((string) $value, true);

                if (! is_array($decoded)) {
                    $items = array_values(array_filter(
                        array_map('trim', explode(',', (string) $value)),
                        fn ($item) => $item !== '',
                    ));

                    $value = json_encode($items);
                }
            }

            $setting->update(['value' => $value]);
        }

        Setting::flush();

        return back()->with('status', 'Settings saved.');
    }

    /**
     * Move a number series forward, e.g. when migrating from an old system.
     */
    public function updateSequence(Request $request, NumberSequenceService $sequences): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'type' => ['required', 'in:admission_registration,student_number,invoice,receipt'],
            'scope' => ['required', 'string', 'max:20'],
            'last_number' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        $sequences->setLastNumber(
            \App\Enums\SequenceType::from($validated['type']),
            $validated['scope'],
            $validated['last_number'],
        );

        return back()->with('status', 'Number series updated.');
    }
}

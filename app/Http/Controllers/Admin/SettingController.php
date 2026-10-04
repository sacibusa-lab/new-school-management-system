<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SequenceType;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Setting;
use App\Models\Term;
use App\Services\Academics\AcademicCalendarService;
use App\Services\Branding\BrandingService;
use App\Services\NumberSequenceService;
use App\Services\Payment\PaystackProvider;
use App\Support\SettingLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(NumberSequenceService $sequences, AcademicCalendarService $academic): View
    {
        $this->authorize('settings.manage');

        // Grouped only; the order it is drawn in comes from SettingLayout, because
        // ordering by group then key is alphabetical twice over and put the
        // school's Address above its own Name. Admissions and the letters are set
        // up on a page of their own — see SettingLayout::PAGES.
        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');

        return view('admin.settings.index', [
            'groups' => SettingLayout::arrange($settings, 'general'),
            // The calendar card behind the selector: what exists to choose from,
            // what is in the way of deleting any of it. Terms are shared by every
            // session, so they are listed once, with their dates for the current one.
            'academic' => $academic,
            'suggestedSession' => $academic->suggestNextSessionName(),
            'terms' => Term::query()->orderBy('position')->get(),
            'sessions' => AcademicSession::query()
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get(),
            'currentSession' => AcademicSession::current(),
            'currentTerm' => Term::current(),
            'previews' => [
                'admission' => $sequences->preview(SequenceType::AdmissionRegistration),
                'student' => $sequences->preview(SequenceType::StudentNumber),
                'invoice' => $sequences->preview(SequenceType::Invoice),
                'receipt' => $sequences->preview(SequenceType::Receipt),
            ],
        ]);
    }

    /**
     * The admissions settings, on their own page.
     *
     * The same form as the general page and the same route to save it: a setting is
     * a setting, and which page it was drawn on makes no difference to how it is
     * written. Only the groups differ, and which those are is SettingLayout's to
     * say.
     */
    public function admissions(NumberSequenceService $sequences): View
    {
        $this->authorize('settings.manage');

        return view('admin.settings.admissions', [
            'groups' => SettingLayout::arrange(
                Setting::query()->orderBy('key')->get()->groupBy('group'),
                'admissions',
            ),
            // Used by the numbering fields' hints; the groups here have none, but the
            // fields are drawn by a shared partial that knows no better.
            'previews' => [
                'admission' => $sequences->preview(SequenceType::AdmissionRegistration),
                'student' => $sequences->preview(SequenceType::StudentNumber),
                'invoice' => $sequences->preview(SequenceType::Invoice),
                'receipt' => $sequences->preview(SequenceType::Receipt),
            ],
        ]);
    }

    /**
     * The school's accounts with other people, on a page of their own.
     *
     * Paystack to collect fees, Termii to send a text message, an AI provider to read a
     * scoresheet that has been photographed. Three cards, one page, and the same form and
     * the same route that saves every other setting: a setting is a setting, and which
     * page it was drawn on makes no difference to how it is written. Which groups are
     * drawn here is SettingLayout's to say.
     *
     * Each card posts on its own, which is the one way this page is not like the others.
     * They are three unrelated accounts: a form that carried all of them would submit the
     * Paystack key every time somebody corrected a Termii sender ID, and the office would
     * be one browser autofill away from writing a key back to a field it had emptied.
     */
    public function api(NumberSequenceService $sequences, PaystackProvider $paystack): View
    {
        $this->authorize('settings.manage');

        return view('admin.settings.api', [
            'groups' => SettingLayout::arrange(
                Setting::query()->orderBy('key')->get()->groupBy('group'),
                'api',
            ),
            // The bank that issues a virtual account number is not a name to be typed:
            // only some of them will open a dedicated account, and Paystack is the one
            // that knows which. Read from them where a key allows it, and from the short
            // list they publish where it does not.
            'choices' => [
                'paystack_dva_bank' => $paystack->getVirtualAccountBanks(),
            ],
            // The numbering fields' hints are drawn by the same shared partial, so
            // it is handed the previews even though none of these groups has one.
            'previews' => [
                'admission' => $sequences->preview(SequenceType::AdmissionRegistration),
                'student' => $sequences->preview(SequenceType::StudentNumber),
                'invoice' => $sequences->preview(SequenceType::Invoice),
                'receipt' => $sequences->preview(SequenceType::Receipt),
            ],
        ]);
    }

    public function update(Request $request, BrandingService $branding): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            // Which card this came from, where the form was one card rather than a whole
            // page. It is used for one thing: saying which of them was saved.
            '_group' => ['nullable', 'string', 'max:40'],
            'settings.*.value' => ['nullable', 'string', 'max:2000'],
            'settings.*.file' => ['nullable', 'file', 'mimes:'.BrandingService::EXTENSIONS, 'max:'.BrandingService::MAX_KB],
            'settings.*.remove' => ['nullable', 'boolean'],
        ], [
            'settings.*.file.mimes' => 'That file is not an image the browser can show. Use PNG, JPG, WEBP, SVG or ICO.',
            'settings.*.file.max' => 'That image is larger than '.round(BrandingService::MAX_KB / 1024).' MB.',
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

        // "Settings saved." on a page where three unrelated accounts are saved one at a
        // time does not say which. The card names itself where it knows how to.
        $brief = SettingLayout::briefName($validated['_group'] ?? '');

        return back()->with('status', $brief ? "{$brief} settings saved." : 'Settings saved.');
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
            SequenceType::from($validated['type']),
            $validated['scope'],
            $validated['last_number'],
        );

        return back()->with('status', 'Number series updated.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Term;
use App\Services\Branding\BrandingService;
use App\Services\NumberSequenceService;
use App\Support\SettingLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * The terms a session runs, in order.
     *
     * Used only to lay them down for a session that has none — never to correct
     * an existing one, since a school that has renamed or reordered its terms has
     * said something and should not have it overwritten.
     */
    private const STANDARD_TERMS = [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'];

    public function index(NumberSequenceService $sequences): View
    {
        $this->authorize('settings.manage');

        // Grouped only; the order it is drawn in comes from SettingLayout, because
        // ordering by group then key is alphabetical twice over and put the
        // school's Address above its own Name.
        $settings = Setting::query()->orderBy('key')->get()->groupBy('group');

        return view('admin.settings.index', [
            'groups' => SettingLayout::arrange($settings),
            'sessions' => AcademicSession::query()
                ->with('terms')
                ->orderByDesc('starts_on')
                ->orderByDesc('id')
                ->get(),
            'currentSession' => AcademicSession::current(),
            'currentTerm' => Term::current(),
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
     * Move the school to a different session and term.
     *
     * Every figure the school reports is relative to these two answers — a fee
     * invoice, a result, an attendance register — so the rules that keep them
     * single-valued live here rather than in whatever screen sets them next.
     *
     * Setting a value is therefore three writes, not one: the chosen row becomes
     * current, and everything else stops being. A session left current behind us
     * is the stale answer to "which session are we in?", and a term left current
     * in the session we have just left is the same question answered twice.
     */
    public function updateAcademic(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'term_id' => [
                'nullable', 'integer', 'exists:terms,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $value) {
                        return;
                    }

                    $belongs = Term::query()
                        ->whereKey($value)
                        ->where('academic_session_id', $request->input('academic_session_id'))
                        ->exists();

                    if (! $belongs) {
                        $fail('That term belongs to a different academic session. Pick the term listed under the session you chose.');
                    }
                },
            ],
        ], [
            'academic_session_id.required' => 'Choose the academic session the school is in.',
            'academic_session_id.exists' => 'That academic session no longer exists.',
            'term_id.exists' => 'That term no longer exists.',
        ]);

        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        $created = [];

        DB::transaction(function () use ($session, $validated, &$created): void {
            AcademicSession::query()->whereKeyNot($session->id)->update(['is_current' => false]);
            $session->forceFill(['is_current' => true])->save();

            // A session with no terms cannot be "in" a term, and the page that
            // manages terms is not built yet — so the three a Nigerian school runs
            // are laid down, and only ever when the session has none at all.
            if ($session->terms()->doesntExist()) {
                foreach (self::STANDARD_TERMS as $position => $name) {
                    $session->terms()->create(['name' => $name, 'position' => $position, 'is_current' => false]);
                    $created[] = $name;
                }

                $session->load('terms');
            }

            $term = ! empty($validated['term_id'])
                ? $session->terms->firstWhere('id', (int) $validated['term_id'])
                : null;

            // No term named means "the first one of this session", which is what
            // somebody moving to a new session expects to get.
            $term ??= $session->terms->sortBy('position')->first();

            if ($term === null) {
                return;
            }

            Term::query()->whereKeyNot($term->id)->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();
        });

        $term = $session->terms()->where('is_current', true)->first();

        ActivityLog::record(
            'settings.academic',
            $session,
            sprintf(
                'Moved the school to %s, %s%s',
                $session->name,
                $term?->name ?? 'no term set',
                $created === [] ? '' : ' (' . implode(', ', $created) . ' created)',
            ),
            ['module' => 'settings'],
        );

        $message = sprintf('The school is now in %s', $session->name);

        if ($term) {
            $message .= ', ' . $term->name;
        }

        if ($created !== []) {
            $message .= '. This session had no terms, so ' . implode(', ', $created) . ' were created for it.';
        }

        return back()->with('status', $message . '.');
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

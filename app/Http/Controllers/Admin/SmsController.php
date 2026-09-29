<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\SmsStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\Student;
use App\Services\Sms\SmsNotifier;
use App\Services\Sms\SmsService;
use App\Services\Sms\TermiiSmsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The messaging desk.
 *
 * Everything here is deliberately fault-tolerant: a batch of 400 messages is
 * written to `sms_logs` as "queued" and then pushed out either on demand or at
 * the end of the request. Nothing depends on a queue worker being alive, because
 * most schools run this on a single machine.
 */
class SmsController extends Controller
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly SmsNotifier $notifier,
        private readonly TermiiSmsService $gateway,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* SMS centre                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * The SMS centre.
     *
     * Deliberately a placeholder. The messaging that works today lives on the
     * other two screens, and this one is reserved for the hub that will sit on top
     * of them — so rather than invent it now, the page says so and shows the one
     * thing worth knowing before anything is built here: whether the Termii
     * credentials in Settings can actually send.
     *
     * The connection details are read from Settings, not from .env, so the school
     * can change them without anyone touching the server.
     */
    public function center(): View
    {
        $this->authorize('sms.view');

        return view('admin.sms.center', [
            'configured' => $this->gateway->isConfigured(),
            'enabled' => $this->gateway->isEnabled(),
            'senderId' => (string) Setting::get('termii_sender_id', ''),
            'channel' => (string) Setting::get('termii_channel', ''),
            'provider' => TermiiSmsService::PROVIDER,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Message log                                                         */
    /* ------------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $this->authorize('sms.view');

        $logs = SmsLog::query()
            ->with(['user'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';

                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('recipient', 'like', $term)
                        ->orWhere('body', 'like', $term)
                        ->orWhere('template_key', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('template'), fn (Builder $q) => $q->where('template_key', $request->string('template')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.sms.index', [
            'logs' => $logs,
            'statuses' => SmsStatus::options(),
            'templates' => SmsTemplate::query()->orderBy('name')->get(),
            'stats' => [
                'queued' => SmsLog::query()->where('status', SmsStatus::Pending->value)->count(),
                'sent_today' => SmsLog::query()->delivered()->whereDate('sent_at', today())->count(),
                'failed' => SmsLog::query()->where('status', SmsStatus::Failed->value)->count(),
                'total' => SmsLog::query()->count(),
            ],
            'enabled' => (bool) Setting::get('sms_enabled', true),
            'configured' => $this->gateway->isConfigured(),
            'senderId' => Setting::get('sms_sender_id'),
        ]);
    }

    /** Push everything sitting in the outbox right now. */
    public function flush(): RedirectResponse
    {
        $this->authorize('sms.send');

        $result = $this->sms->flushQueued(200);

        return back()->with('status', sprintf(
            'Outbox processed — %d sent, %d failed, %d still queued.',
            $result['sent'],
            $result['failed'],
            $result['remaining'],
        ));
    }

    /** Try a failed message again. */
    public function resend(SmsLog $log): RedirectResponse
    {
        $this->authorize('sms.send');

        if ($log->status->isDelivered()) {
            return back()->with('error', 'That message was already delivered.');
        }

        $log = $this->sms->deliver($log);

        return back()->with(
            $log->status->isDelivered() ? 'status' : 'error',
            $log->status->isDelivered()
                ? "Message to {$log->internationalRecipient()} {$log->status->label()}."
                : "Still could not send: " . ($log->error ?: 'no reason given by the gateway'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Broadcast                                                           */
    /* ------------------------------------------------------------------ */

    public function batch(): View
    {
        $this->authorize('sms.send');

        $templates = SmsTemplate::query()->orderBy('name')->get();

        return view('admin.sms.batch', [
            'templates' => $templates,
            'audiences' => $this->audiences(),
            'audienceSizes' => $this->audienceSizes(),
            'levels' => SchoolLevel::query()->where('is_active', true)->orderBy('order')->get(),
            'classes' => SchoolClass::query()->where('is_active', true)->with('level')->orderBy('name')->get(),
            'enabled' => (bool) Setting::get('sms_enabled', true),
            'placeholders' => SmsTemplate::query()->pluck('key')->flatMap(
                fn (string $key) => \App\Support\SmsTemplateKey::placeholders($key),
            )->unique()->sort()->values(),
            // Let the page warn *before* anything is queued: broadcasting
            // "Payment received" to a whole class would print a literal
            // {amount} to every parent.
            'templatePlaceholders' => $templates->mapWithKeys(
                fn (SmsTemplate $template) => [$template->key => $this->placeholdersIn($template->body)],
            ),
            'audiencePlaceholders' => collect(array_keys($this->audiences()))
                ->mapWithKeys(fn (string $audience) => [
                    $audience => array_keys($this->sampleValuesFor($audience)),
                ]),
        ]);
    }

    public function storeBatch(Request $request): RedirectResponse
    {
        $this->authorize('sms.send');

        if (! (bool) Setting::get('sms_enabled', true)) {
            return back()->with('error', 'SMS is switched off in Settings. Switch it on before broadcasting.');
        }

        $validated = $request->validate([
            'audience' => ['required', 'in:' . implode(',', array_keys($this->audiences()))],
            'level_id' => ['nullable', 'integer', 'exists:school_levels,id'],
            'school_class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'template_key' => ['required', 'string', 'max:80'],
            'body_override' => ['nullable', 'string', 'max:920'],
        ], [
            'audience.required' => 'Choose who should receive this message.',
            'template_key.required' => 'Choose a message template.',
        ]);

        if ($validated['audience'] === 'applicants_level' && empty($validated['level_id'])) {
            return back()->withErrors(['level_id' => 'Choose the class applied for.'])->withInput();
        }

        if ($validated['audience'] === 'students_class' && empty($validated['school_class_id'])) {
            return back()->withErrors(['school_class_id' => 'Choose the class.'])->withInput();
        }

        $recipients = $this->recipients($validated);

        if ($recipients === []) {
            return back()->with('error', 'Nobody matches that audience, so nothing was sent.')->withInput();
        }

        // Refuse to send a message that would print braces at parents. An
        // audience cannot fill "{amount}" — only a real payment can.
        if (empty($validated['body_override'])) {
            $missing = $this->unresolvedPlaceholders($validated['template_key'], $recipients);

            if ($missing !== []) {
                return back()->withErrors([
                    'template_key' => 'That message uses '
                        . implode(', ', array_map(fn (string $p) => '{' . $p . '}', $missing))
                        . ', which this audience cannot fill in. Write your own message instead, or pick a different message type.',
                ])->withInput();
            }
        }

        $result = $this->sms->queueTemplate(
            $validated['template_key'],
            $recipients,
            $request->user(),
            $validated['body_override'] ?? null,
        );

        $message = $result['queued'] . ' message(s) queued';
        $message .= $result['skipped'] > 0
            ? ", {$result['skipped']} skipped for having no usable mobile number."
            : '.';

        // Default to sending straight away: most deployments have no worker.
        if ($request->boolean('send_now')) {
            $flush = $this->sms->flushQueued(200);

            $message .= sprintf(
                ' Sent %d, failed %d, %d still queued.',
                $flush['sent'],
                $flush['failed'],
                $flush['remaining'],
            );
        }

        return redirect()->route('admin.sms.index')->with('status', $message);
    }

    /* ------------------------------------------------------------------ */
    /* Template wording                                                    */
    /* ------------------------------------------------------------------ */

    public function templates(): View
    {
        $this->authorize('sms.templates');

        return view('admin.sms.templates', [
            'templates' => SmsTemplate::query()->orderBy('name')->get(),
            'defaults' => \App\Support\SmsTemplateKey::defaults(),
        ]);
    }

    public function updateTemplate(Request $request, SmsTemplate $smsTemplate): RedirectResponse
    {
        $this->authorize('sms.templates');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'body.required' => 'A message cannot be empty.',
        ]);

        $smsTemplate->update([
            'name' => $validated['name'],
            'body' => $validated['body'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', "Template \"{$smsTemplate->name}\" saved.");
    }

    /** Restore the wording the platform shipped with. */
    public function resetTemplate(SmsTemplate $smsTemplate): RedirectResponse
    {
        $this->authorize('sms.templates');

        $default = \App\Support\SmsTemplateKey::defaults()[$smsTemplate->key] ?? null;

        if (! $default) {
            return back()->with('error', 'No default wording is stored for this template.');
        }

        $smsTemplate->update([
            'name' => $default['name'],
            'description' => $default['description'],
            'body' => $default['body'],
            'is_active' => true,
        ]);

        return back()->with('status', "Template \"{$default['name']}\" restored to the default wording.");
    }

    /* ------------------------------------------------------------------ */
    /* Audiences                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,string>
     */
    public function audiences(): array
    {
        return [
            'applicants_all' => 'All applicants',
            'applicants_registered' => 'Applicants still to sit the examination',
            'applicants_admitted' => 'Applicants who were admitted',
            'applicants_rejected' => 'Applicants who were not admitted',
            'applicants_level' => 'Applicants for one class',
            'students_all' => 'All students',
            'students_owing' => 'Students owing fees',
            'students_class' => 'Students in one class',
        ];
    }

    /**
     * How many people each audience currently covers, so the office can see the
     * size of the bill before committing to it.
     *
     * @return array<string,int>
     */
    protected function audienceSizes(): array
    {
        $sizes = [];

        foreach (array_keys($this->audiences()) as $audience) {
            // The two "one class" audiences need a picker value, so count the
            // whole population as an indicative maximum.
            $filters = match ($audience) {
                'applicants_level' => ['audience' => 'applicants_all'],
                'students_class' => ['audience' => 'students_all'],
                default => ['audience' => $audience],
            };

            $sizes[$audience] = $this->audienceQuery($filters)->count();
        }

        return $sizes;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Builder<Model>
     */
    protected function audienceQuery(array $filters): Builder
    {
        $audience = $filters['audience'] ?? 'applicants_all';

        if (str_starts_with($audience, 'students')) {
            $query = Student::query()->where('status', StudentStatus::Active->value);

            $query = match ($audience) {
                'students_owing' => $query->whereHas('invoices', fn (Builder $q) => $q->where('balance', '>', 0)),
                'students_class' => $query->where('school_class_id', $filters['school_class_id'] ?? 0),
                default => $query,
            };

            // Eager-loaded up front: the placeholder builder touches all of
            // these, and a broadcast of 500 must not fire 500 extra lookups.
            // Harmless on the count() calls in the audience summary.
            return $query->with(['level', 'schoolClass', 'academicSession']);
        }

        $query = Applicant::query();

        $query = match ($audience) {
            'applicants_registered' => $query->whereIn('status', [
                ApplicantStatus::Registered->value,
                ApplicantStatus::ExamScheduled->value,
                ApplicantStatus::ExamCompleted->value,
                ApplicantStatus::Shortlisted->value,
            ]),
            'applicants_admitted' => $query->where('status', ApplicantStatus::Admitted->value),
            'applicants_rejected' => $query->where('status', ApplicantStatus::Rejected->value),
            // Column is `level_applied_for_id` on applicants, not `level_id`.
            'applicants_level' => $query->where('level_applied_for_id', $filters['level_id'] ?? 0),
            default => $query,
        };

        return $query->with(['levelAppliedFor', 'academicSession', 'student', 'decisions']);
    }

    /**
     * Turn the chosen audience into concrete recipients with their placeholder
     * values already filled in.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array{recipient:?string,subject:?Model,values:array<string,mixed>}>
     */
    protected function recipients(array $filters): array
    {
        return $this->audienceQuery($filters)->get()
            ->map(fn (Model $model) => [
                'recipient' => $model->guardian_phone ?: $model->phone,
                'subject' => $model,
                'values' => $this->notifier->valuesFor($model),
            ])
            ->all();
    }

    /** Every `{placeholder}` a piece of text refers to. */
    protected function placeholdersIn(string $text): array
    {
        preg_match_all('/\{\{?\s*([a-z0-9_]+)\s*\}?\}/i', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Placeholders a template uses that the recipients cannot supply.
     *
     * @param  array<int,array{values:array<string,mixed>}>  $recipients
     * @return array<int,string>
     */
    protected function unresolvedPlaceholders(string $templateKey, array $recipients): array
    {
        $template = SmsTemplate::query()->where('key', $templateKey)->first();

        if (! $template) {
            return [];
        }

        $available = array_keys($recipients[0]['values']);

        return array_values(array_diff($this->placeholdersIn($template->body), $available));
    }

    /**
     * The placeholder names an audience can fill, taken from one real record so
     * the UI warning matches what the send would actually do.
     *
     * @return array<string,mixed>
     */
    protected function sampleValuesFor(string $audience): array
    {
        $models = $this->audienceQuery(['audience' => $audience])->limit(1)->get();

        return $models->isEmpty() ? [] : $this->notifier->valuesFor($models->first());
    }
}

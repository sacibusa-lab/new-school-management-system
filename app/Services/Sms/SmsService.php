<?php

namespace App\Services\Sms;

use App\Enums\SmsStatus;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The front door for sending a text message.
 *
 * Design rule: **a failed SMS must never break the action that triggered it.**
 * Registering an applicant or admitting a student must still succeed even if the
 * gateway is down or the phone number is nonsense. Failures are recorded on the
 * log row and in the application log, and surfaced on the SMS screen.
 */
class SmsService
{
    public function __construct(
        private readonly TermiiSmsService $gateway,
    ) {
    }

    /**
     * Send a template by key. Returns null when the template is missing or
     * switched off, so callers can stay one-liners.
     *
     * @param  array<string,string|int|float|null>  $values
     */
    public function sendTemplate(
        string $templateKey,
        ?string $recipient,
        array $values = [],
        ?Model $subject = null,
        ?User $user = null,
    ): ?SmsLog {
        // Globally switched off: do not even create a log row.
        if (! (bool) Setting::get('sms_enabled', true)) {
            return null;
        }

        $template = SmsTemplate::find($templateKey);

        if (! $template) {
            Log::info('SMS template missing or inactive — nothing sent', ['template' => $templateKey]);

            return null;
        }

        if (! $this->hasUsableNumber($recipient)) {
            Log::info('SMS skipped — no usable mobile number', [
                'template' => $templateKey,
                'recipient' => $recipient,
            ]);

            return null;
        }

        return $this->send(
            (string) $recipient,
            $template->render($values),
            $templateKey,
            $subject,
            $user,
        );
    }

    /**
     * Send raw text. Always writes a log row, even if the send throws.
     */
    public function send(
        string $recipient,
        string $body,
        ?string $templateKey = null,
        ?Model $subject = null,
        ?User $user = null,
    ): SmsLog {
        $log = SmsLog::create([
            'status' => SmsStatus::Pending,
            'provider' => TermiiSmsService::PROVIDER,
            'recipient' => $recipient,
            'body' => $body,
            'template_key' => $templateKey,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'user_id' => $user?->id ?? auth()->id(),
        ]);

        return $this->deliver($log);
    }

    /**
     * Attempt delivery for an existing log row. Used both by send() and by the
     * "send queued now" action on the SMS screen.
     */
    public function deliver(SmsLog $log): SmsLog
    {
        try {
            $result = $this->gateway->send($log->recipient, $log->body);
        } catch (\Throwable $e) {
            // Deliberately broad: this must never bubble into the caller's action.
            Log::warning('SMS delivery threw', ['sms_log_id' => $log->id, 'error' => $e->getMessage()]);

            $result = [
                'ok' => false,
                'mocked' => false,
                'reference' => null,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }

        $log->forceFill([
            'status' => $result['ok']
                ? ($result['mocked'] ? SmsStatus::Mocked : SmsStatus::Sent)
                : SmsStatus::Failed,
            'provider_reference' => $result['reference'],
            'response' => $result['response'],
            'error' => $result['error'],
            'sent_at' => $result['ok'] ? now() : null,
        ])->save();

        return $log;
    }

    /**
     * Queue a batch of template messages — one row per recipient, status
     * "queued". Nothing hits the network until the queue is processed.
     *
     * @param  array<int,array{recipient:?string,subject:?Model,values:array<string,mixed>}>  $recipients
     * @return array{queued:int,skipped:int}
     */
    public function queueTemplate(
        string $templateKey,
        array $recipients,
        ?User $user = null,
        ?string $bodyOverride = null,
    ): array {
        if (! (bool) Setting::get('sms_enabled', true)) {
            return ['queued' => 0, 'skipped' => count($recipients)];
        }

        $template = SmsTemplate::query()
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return ['queued' => 0, 'skipped' => count($recipients)];
        }

        $override = $bodyOverride !== null && trim($bodyOverride) !== '' ? $bodyOverride : null;

        $queued = 0;
        $skipped = 0;

        foreach ($recipients as $entry) {
            if (! $this->hasUsableNumber($entry['recipient'] ?? null)) {
                $skipped++;

                continue;
            }

            SmsLog::create([
                'status' => SmsStatus::Pending,
                'provider' => TermiiSmsService::PROVIDER,
                'recipient' => $entry['recipient'],
                'body' => $override !== null
                    ? SmsTemplate::renderText($override, $entry['values'] ?? [])
                    : $template->render($entry['values'] ?? []),
                'template_key' => $templateKey,
                'subject_type' => isset($entry['subject']) ? $entry['subject']?->getMorphClass() : null,
                'subject_id' => isset($entry['subject']) ? $entry['subject']?->getKey() : null,
                'user_id' => $user?->id,
            ]);

            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Push every queued message out, in order, stopping at $limit so a single
     * request cannot hang. Returns how many remain.
     *
     * @return array{sent:int,failed:int,remaining:int}
     */
    public function flushQueued(int $limit = 50): array
    {
        $pending = SmsLog::query()
            ->where('status', SmsStatus::Pending->value)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($pending as $log) {
            $this->deliver($log);

            $log->refresh()->status->isDelivered() ? $sent++ : $failed++;
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => SmsLog::query()->where('status', SmsStatus::Pending->value)->count(),
        ];
    }

    public function queuedCount(): int
    {
        return SmsLog::query()->where('status', SmsStatus::Pending->value)->count();
    }

    protected function hasUsableNumber(?string $recipient): bool
    {
        return $this->gateway->looksLikeNigerianMobile($recipient);
    }
}

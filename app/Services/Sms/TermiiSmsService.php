<?php

namespace App\Services\Sms;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Talks to Termii, the Nigerian SMS gateway.
 *
 * If no API key is configured the service runs in simulation mode: it reports
 * success without calling the network, so the whole platform (and every test)
 * works offline. Every attempt is logged either way.
 */
class TermiiSmsService
{
    public const PROVIDER = 'termii';

    /**
     * @return array{ok:bool,mocked:bool,reference:?string,response:?array,error:?string}
     */
    public function send(string $recipient, string $body): array
    {
        $apiKey = (string) Setting::get('termii_api_key', '');

        if (! $this->isEnabled() || $apiKey === '') {
            return [
                'ok' => true,
                'mocked' => true,
                'reference' => 'MOCK-' . Str::upper(Str::random(8)),
                'response' => ['mocked' => true, 'reason' => $this->isEnabled() ? 'No Termii API key configured.' : 'SMS is switched off.'],
                'error' => null,
            ];
        }

        $payload = [
            'api_key' => $apiKey,
            'to' => $this->normalisePhone($recipient),
            'from' => (string) Setting::get('termii_sender_id', 'SACISCH'),
            'sms' => $body,
            'type' => 'plain',
            'channel' => (string) Setting::get('termii_channel', 'generic'),
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post('https://api.ng.termii.com/api/sms/send', $payload);
        } catch (\Throwable $e) {
            Log::warning('Termii SMS request failed', ['error' => $e->getMessage()]);

            return [
                'ok' => false,
                'mocked' => false,
                'reference' => null,
                'response' => null,
                'error' => 'Could not reach the SMS gateway: ' . $e->getMessage(),
            ];
        }

        $json = $response->json();

        // Termii returns HTTP 200 even for some failures, so trust the body too.
        $failed = $response->failed()
            || (is_array($json) && strtolower((string) ($json['message'] ?? '')) === 'error');

        if ($failed) {
            return [
                'ok' => false,
                'mocked' => false,
                'reference' => null,
                'response' => is_array($json) ? $json : null,
                'error' => is_array($json)
                    ? (string) ($json['message'] ?? 'The gateway rejected the message.')
                    : "HTTP {$response->status()}",
            ];
        }

        return [
            'ok' => true,
            'mocked' => false,
            'reference' => is_array($json) ? ($json['message_id'] ?? $json['messageId'] ?? null) : null,
            'response' => is_array($json) ? $json : null,
            'error' => null,
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('sms_enabled', true);
    }

    public function isConfigured(): bool
    {
        return filled(Setting::get('termii_api_key', ''));
    }

    /**
     * Turn however the office typed a number into the 2348031234567 form Termii wants.
     */
    public function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '234')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '234' . substr($digits, 1);
        }

        // Bare local number without the leading zero (e.g. 8031234567).
        if (strlen($digits) === 10 && preg_match('/^[789]/', $digits)) {
            return '234' . $digits;
        }

        return $digits;
    }

    public function looksLikeNigerianMobile(?string $phone): bool
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '234')) {
            $digits = '0' . substr($digits, 3);
        }

        return (bool) preg_match('/^0[789][01]\d{8}$/', $digits);
    }
}

<?php

namespace App\Services\Import;

use App\Contracts\ScoresheetExtractor;
use App\Enums\ImportDriver;
use App\Exceptions\ScoresheetExtractionException;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a photographed or scanned, hand-marked scoresheet using a vision model.
 *
 * Provider-agnostic on purpose: the rest of the pipeline only knows about the
 * `ScoresheetExtractor` contract, so switching from Gemini to OpenAI (or adding
 * another provider later) is a config change, not a code change.
 *
 * Everything it returns is a *proposal*. A human reviews and commits it.
 */
class AiVisionScoresheetExtractor implements ScoresheetExtractor
{
    public function driver(): ImportDriver
    {
        return ImportDriver::AiVision;
    }

    public function describe(): string
    {
        return 'Read by AI from a photograph or scan of the marked sheet.';
    }

    /**
     * The AI reads whole rows, not columns, so there is no column it failed to
     * place — anything it could not read simply does not come back as a row.
     */
    public function unreadHeadings(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== null && filled($this->apiKey());
    }

    public function supports(string $mimeType, string $extension): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $extension = strtolower($extension);

        return str_starts_with($mimeType, 'image/')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true);
    }

    public function extract(string $absolutePath, array $context = []): array
    {
        if (! $this->isConfigured()) {
            throw ScoresheetExtractionException::notConfigured();
        }

        $mimeType = mime_content_type($absolutePath) ?: 'image/jpeg';
        $base64 = base64_encode((string) file_get_contents($absolutePath));
        $prompt = $this->buildPrompt($context);

        $payload = match ($this->provider()) {
            'gemini' => $this->callGemini($base64, $mimeType, $prompt),
            'openai' => $this->callOpenAi($base64, $mimeType, $prompt),
            'deepseek' => $this->callDeepSeek($base64, $mimeType, $prompt),
            default => throw ScoresheetExtractionException::notConfigured(),
        };

        return $this->normalise($payload, $context);
    }

    protected function provider(): ?string
    {
        $provider = strtolower(trim((string) $this->setting('ai_provider', 'provider', 'null')));

        return in_array($provider, ['gemini', 'openai', 'deepseek'], true) ? $provider : null;
    }

    /**
     * The provider → key → model the school set on Settings → API, falling back
     * to the environment.
     *
     * These lived only in `.env`, which meant the office could see the scoresheet
     * reader and had no way to switch it on — that took somebody who could edit a
     * file on the server. The page is what the school sets now; `.env` still works
     * for an installation that was configured that way, and a field left empty on
     * the page falls back rather than meaning "use no key".
     */
    protected function setting(string $settingKey, string $configKey, mixed $fallback): mixed
    {
        $value = Setting::get($settingKey);

        return filled($value) ? $value : config("saci.ai.{$configKey}", $fallback);
    }

    protected function apiKey(): string
    {
        return (string) $this->setting('ai_api_key', 'key', '');
    }

    protected function model(string $default): string
    {
        return (string) $this->setting('ai_model', 'model', '') ?: $default;
    }

    /* ------------------------------------------------------------------ */
    /* Prompting */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,mixed>  $context
     */
    protected function buildPrompt(array $context): string
    {
        $subject = $context['subject'] ?? 'unknown';
        $total = $context['total_marks'] ?? 100;
        $registrations = $context['known_identifiers'] ?? [];

        $roster = '';

        if (! empty($registrations)) {
            $list = implode(', ', array_slice($registrations, 0, 200));
            $roster = "\n\nThese are the valid registration numbers for this examination. "
                ."Use them to correct any misread digits:\n{$list}";
        }

        return <<<PROMPT
        You are reading a hand-marked examination scoresheet for a school in Nigeria.

        Subject: {$subject}
        Maximum obtainable mark: {$total}

        Return ONLY a JSON object of this exact shape, with no commentary:
        {
          "rows": [
            {
              "identifier": "the student's registration number exactly as printed, or null",
              "name": "the student's full name exactly as written, or null",
              "score": 72.5,
              "confidence": 0.93
            }
          ]
        }

        Rules:
        - One object per student row on the sheet, in the order they appear.
        - `score` must be a number. If the mark is crossed out, unreadable, or the
          student was absent, use null.
        - `confidence` is your certainty in THAT row, from 0 to 1. Be honest:
          use a low number when the handwriting is ambiguous.
        - Never invent a student who is not on the sheet.
        - If a mark looks like a fraction such as 72/100, return 72.
        - Read the handwriting carefully; Nigerian exam sheets often use ticks and
          totals in the right-hand margin.{$roster}
        PROMPT;
    }

    /* ------------------------------------------------------------------ */
    /* Providers */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>
     */
    protected function callGemini(string $base64, string $mimeType, string $prompt): array
    {
        $model = $this->model('gemini-2.5-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::timeout((int) config('saci.ai.timeout', 120))
                ->withQueryParameters(['key' => $this->apiKey()])
                ->post($endpoint, [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw ScoresheetExtractionException::providerFailed('Gemini', 'the request timed out.');
        }

        if ($response->failed()) {
            Log::warning('Gemini scoresheet extraction failed', ['body' => $response->body()]);

            throw ScoresheetExtractionException::providerFailed(
                'Gemini',
                $response->json('error.message') ?? "HTTP {$response->status()}",
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw ScoresheetExtractionException::unreadableResponse('Gemini');
        }

        return $this->decodeJson($text, 'Gemini');
    }

    /**
     * OpenAI, over its own endpoint.
     *
     * @return array<string,mixed>
     */
    protected function callOpenAi(string $base64, string $mimeType, string $prompt): array
    {
        return $this->callOpenAiCompatible(
            'OpenAI',
            'https://api.openai.com/v1/chat/completions',
            'gpt-4.1-mini',
            $base64,
            $mimeType,
            $prompt,
        );
    }

    /**
     * DeepSeek, over the same shape of request as OpenAI.
     *
     * One thing to know before switching to it: DeepSeek's published chat models
     * are text-only, so a photographed sheet may be refused outright. Whatever the
     * provider says about that is what comes back here and what the office is
     * shown — a refusal is never quietly turned into "no rows found".
     *
     * @return array<string,mixed>
     */
    protected function callDeepSeek(string $base64, string $mimeType, string $prompt): array
    {
        return $this->callOpenAiCompatible(
            'DeepSeek',
            'https://api.deepseek.com/chat/completions',
            'deepseek-chat',
            $base64,
            $mimeType,
            $prompt,
        );
    }

    /**
     * The request both of the above send: a chat completion carrying the picture,
     * which is the shape OpenAI and DeepSeek both accept.
     *
     * @return array<string,mixed>
     */
    protected function callOpenAiCompatible(
        string $provider,
        string $endpoint,
        string $defaultModel,
        string $base64,
        string $mimeType,
        string $prompt,
    ): array {
        try {
            $response = Http::withToken($this->apiKey())
                ->timeout((int) config('saci.ai.timeout', 120))
                ->post($endpoint, [
                    'model' => $this->model($defaultModel),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64}",
                            ]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw ScoresheetExtractionException::providerFailed($provider, 'the request timed out.');
        }

        if ($response->failed()) {
            Log::warning("{$provider} scoresheet extraction failed", ['body' => $response->body()]);

            throw ScoresheetExtractionException::providerFailed(
                $provider,
                $response->json('error.message') ?? "HTTP {$response->status()}",
            );
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw ScoresheetExtractionException::unreadableResponse($provider);
        }

        return $this->decodeJson($text, $provider);
    }

    /* ------------------------------------------------------------------ */
    /* Parsing */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>
     */
    protected function decodeJson(string $text, string $provider): array
    {
        $text = trim($text);

        // Models sometimes wrap JSON in markdown fences despite instructions.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        // Grab the outermost object if there is any preamble left.
        if (! str_starts_with($text, '{') && preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw ScoresheetExtractionException::unreadableResponse($provider);
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    protected function normalise(array $payload, array $context): array
    {
        $rows = $payload['rows'] ?? $payload;

        if (! is_array($rows)) {
            throw ScoresheetExtractionException::unreadableResponse('AI');
        }

        $out = [];
        $rowNumber = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowNumber++;

            $identifier = $row['identifier'] ?? null;
            $identifier = is_string($identifier) && trim($identifier) !== ''
                ? strtoupper(str_replace(' ', '', trim($identifier)))
                : null;

            $name = $row['name'] ?? null;
            $name = is_string($name) && trim($name) !== ''
                ? trim(preg_replace('/\s+/', ' ', $name) ?? '')
                : null;

            $out[] = [
                'row' => $rowNumber,
                'identifier' => $identifier,
                'name' => $name,
                'subject' => $row['subject'] ?? ($context['subject'] ?? null),
                'score' => is_numeric($row['score'] ?? null) ? round((float) $row['score'], 2) : null,
                'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null,
                'absent' => ($row['score'] ?? null) === null,
                'raw' => $row,
            ];
        }

        return $out;
    }
}

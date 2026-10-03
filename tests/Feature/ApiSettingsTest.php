<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Import\AiVisionScoresheetExtractor;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The school's accounts with other people, at Settings → API.
 *
 * These keys used to live only in `.env`, which meant the office could see the
 * scoresheet reader and had no way to switch it on — that took somebody who could
 * edit a file on the server. So the tests that matter here are not only about the
 * page drawing: they are about the page being what the reader actually reads.
 */
class ApiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_it_draws_one_card_for_each_provider(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.api'))->assertOk()->getContent();

        preg_match_all('/<h2 class="text-base font-semibold[^"]*">\s*([^<]+?)\s*<\/h2>/s', $html, $matches);

        $this->assertSame([
            'Paystack — fees collection',
            'Termii — text messages',
            'DeepSeek — reading scoresheets',
        ], array_values(array_filter(array_map('trim', $matches[1]))));
    }

    /**
     * A key is dots until it is asked for. The value has to be in the page for that
     * to mean anything — the only way to check that the key in the provider's own
     * dashboard is the one pasted here is to look at it.
     */
    public function test_a_key_is_hidden_until_the_eye_beside_it_is_clicked(): void
    {
        // put() infers a type from the value it is given, so the row's own type is
        // named here: without it the key would be drawn as a plain text field, and
        // this test would be asserting something the page never does.
        Setting::put('termii_api_key', 'live-termii-key', ['type' => 'secret', 'group' => 'api_termii']);
        Setting::flush();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.api'))->assertOk()->getContent();

        foreach (['settings[paystack_secret_key][value]', 'settings[termii_api_key][value]', 'settings[ai_api_key][value]'] as $name) {
            $this->assertMatchesRegularExpression(
                '/<input [^>]*type="password"[^>]*name="'.preg_quote($name, '/').'"/s',
                $html,
                "{$name} is not drawn as dots.",
            );
        }
        $this->assertStringContainsString('value="live-termii-key"', $html);
        $this->assertStringContainsString('name="settings[termii_api_key][value]"', $html);

        // The click that shows it, and the two icons that say which way it is. An
        // icon's own name is a prop and never reaches the page, so what is checked
        // is the pair of conditions that swap one icon for the other.
        $this->assertStringContainsString('shown = ! shown', $html);
        $this->assertStringContainsString('x-show="shown"', $html);
        $this->assertStringContainsString('x-show="! shown"', $html);
    }

    public function test_a_key_can_be_saved_and_comes_back_hidden(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'settings' => [
                'termii_api_key' => ['value' => 'saved-from-the-page'],
                'ai_provider' => ['value' => 'deepseek'],
            ],
        ])->assertRedirect();

        $this->assertSame('saved-from-the-page', Setting::get('termii_api_key'));
        $this->assertSame('deepseek', Setting::get('ai_provider'));

        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.api'))->assertOk()->getContent();

        $this->assertStringContainsString('value="saved-from-the-page"', $html);
    }

    public function test_somebody_who_cannot_manage_settings_cannot_open_the_page(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)->get(route('admin.settings.api'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* What reads them */
    /* ------------------------------------------------------------------ */

    /**
     * The point of the page: the reader takes its account from it rather than from
     * a file only a developer can edit.
     */
    public function test_the_reader_takes_its_account_from_the_page(): void
    {
        $this->settings([
            'ai_provider' => 'openai',
            'ai_api_key' => 'key-from-the-page',
            'ai_model' => 'gpt-4.1-mini',
        ]);

        $this->assertTrue($this->extractor()->isConfigured());
    }

    /**
     * An installation that was set up through `.env` keeps working, and one that has
     * been set up through neither is honestly switched off.
     */
    public function test_the_reader_still_falls_back_to_the_environment(): void
    {
        config(['saci.ai.provider' => 'gemini', 'saci.ai.key' => 'key-from-env']);

        $this->settings(['ai_provider' => '', 'ai_api_key' => '']);

        $this->assertTrue($this->extractor()->isConfigured());

        config(['saci.ai.provider' => 'null', 'saci.ai.key' => null]);

        $this->assertFalse($this->extractor()->isConfigured());
    }

    /**
     * A provider the reader does not know is not a provider, however it is spelled.
     */
    public function test_an_unknown_provider_is_treated_as_switched_off(): void
    {
        $this->settings(['ai_provider' => 'SOME-NEW-API', 'ai_api_key' => 'a-key']);

        $this->assertFalse($this->extractor()->isConfigured());
    }

    /**
     * DeepSeek speaks OpenAI's shape over a different host, so the sheet goes to
     * api.deepseek.com carrying the key from the page — not OpenAI's address and not
     * the key from `.env`.
     */
    public function test_the_sheet_is_sent_to_the_provider_the_page_names(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"rows": []}']]],
            ]),
        ]);

        config(['saci.ai.key' => 'key-from-env', 'saci.ai.provider' => 'null']);

        $this->settings([
            'ai_provider' => 'deepseek',
            'ai_api_key' => 'key-from-the-page',
            'ai_model' => 'deepseek-chat',
        ]);

        // A real file on disk: the reader measures it and reads it before it posts
        // anything. A faked upload has been moved away by the time it is asked for.
        $sheet = tempnam(sys_get_temp_dir(), 'scoresheet');
        file_put_contents($sheet, 'a photograph of a sheet, honestly');

        $this->extractor()->extract($sheet, ['subject' => 'Mathematics', 'total_marks' => 100]);

        unlink($sheet);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deepseek.com')
            && $request->hasHeader('Authorization', 'Bearer key-from-the-page')
            && $request['model'] === 'deepseek-chat');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    /* ------------------------------------------------------------------ */

    private function extractor(): AiVisionScoresheetExtractor
    {
        return app(AiVisionScoresheetExtractor::class);
    }

    /**
     * @param  array<string,string>  $values
     */
    private function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::put($key, $value);
        }

        Setting::flush();
    }
}

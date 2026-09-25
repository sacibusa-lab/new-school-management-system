<?php

namespace Tests\Feature;

use App\Enums\ApplicantStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\AdmissionLetterService;
use App\Services\Sms\SmsNotifier;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office edits every message in Settings, so the one thing that must never
 * break is placeholder substitution: a parent receiving "Dear {guardian_name}"
 * is worse than receiving nothing.
 */
class SmsAndLetterPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private Applicant $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(SmsTemplateSeeder::class);
        Setting::flush();

        $session = AcademicSession::create([
            'name' => '2025/2026',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        $level = \App\Models\SchoolLevel::create(['name' => 'JSS 1', 'order' => 1]);

        $this->applicant = Applicant::create([
            'registration_number' => 'SAC-00007',
            'first_name' => 'Chiamaka',
            'last_name' => 'Adeyemi',
            'gender' => 'female',
            'phone' => '08031234567',
            'guardian_name' => 'Mrs. Adeyemi',
            'guardian_phone' => '08031234567',
            'level_applied_for_id' => $level->id,
            'academic_session_id' => $session->id,
            'status' => ApplicantStatus::Registered,
        ]);
    }

    public function test_a_registration_log_is_written_with_every_placeholder_filled_in(): void
    {
        app(SmsNotifier::class)->applicantRegistered($this->applicant);

        $log = SmsLog::query()->latest('id')->first();

        $this->assertNotNull($log, 'a log row should exist even in simulated mode');
        $this->assertSame('08031234567', $log->recipient);
        $this->assertStringContainsString('Chiamaka Adeyemi', $log->body);
        $this->assertStringContainsString('SAC-00007', $log->body);
        $this->assertStringContainsString('Mrs. Adeyemi', $log->body);

        $this->assertDoesNotMatchRegularExpression(
            '/\{[a-z_]+\}/',
            $log->body,
            'no placeholder should survive rendering, or the parent reads braces',
        );
    }

    public function test_spaced_and_unspaced_placeholders_both_resolve(): void
    {
        $template = SmsTemplate::query()->where('key', 'applicant_registered')->first();

        $rendered = $template->render(['full_name' => 'Ada', 'registration_number' => 'SAC-00009']);

        $this->assertStringContainsString('Ada', $rendered);
        $this->assertStringContainsString('SAC-00009', $rendered);
    }

    public function test_messages_are_simulated_rather_than_failed_when_no_gateway_key_is_set(): void
    {
        app(SmsNotifier::class)->applicantRegistered($this->applicant);

        $this->assertSame(
            \App\Enums\SmsStatus::Mocked,
            SmsLog::query()->latest('id')->first()->status,
            'with no API key the message is recorded as simulated so the office sees what would go out',
        );
    }

    public function test_nothing_is_logged_when_messaging_is_switched_off(): void
    {
        Setting::put('sms_enabled', false, ['type' => 'bool']);

        app(SmsNotifier::class)->applicantRegistered($this->applicant);

        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_a_nonsense_phone_number_is_skipped_without_throwing(): void
    {
        $this->applicant->forceFill(['phone' => 'not-a-number', 'guardian_phone' => 'n/a'])->save();

        app(SmsNotifier::class)->applicantRegistered($this->applicant->fresh());

        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_the_admission_letter_fills_in_every_placeholder(): void
    {
        // The seeded letter body uses the same names as the SMS templates.
        $letter = app(AdmissionLetterService::class)->render($this->applicant);

        $this->assertSame('Letter of Admission', $letter['letter']['title']);
        $this->assertStringContainsString('Chiamaka Adeyemi', $letter['letter']['body']);
        $this->assertStringContainsString('SAC-00007', $letter['letter']['body']);

        $this->assertDoesNotMatchRegularExpression(
            '/\{[a-z_]+\}/',
            $letter['letter']['body'],
            'the seeded wording uses {full_name}/{class}/{guardian_name} and every one must resolve',
        );
    }

    public function test_an_unknown_placeholder_is_left_visible_instead_of_blanked(): void
    {
        Setting::put('admission_letter_body', 'Hello {nonsense_field} and {full_name}.');

        $letter = app(AdmissionLetterService::class)->render($this->applicant);

        $this->assertStringContainsString('{nonsense_field}', $letter['letter']['body']);
        $this->assertStringContainsString('Chiamaka Adeyemi', $letter['letter']['body']);
    }

    public function test_every_applicant_message_sends_clean_text(): void
    {
        $notifier = app(SmsNotifier::class);

        $notifier->applicantRegistered($this->applicant);
        $notifier->applicantAdmitted($this->applicant);
        $notifier->applicantRejected($this->applicant);

        $bodies = SmsLog::query()->pluck('body');

        $this->assertCount(3, $bodies, 'one log row per message type');

        foreach ($bodies as $body) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{[a-z0-9_]+\}/i',
                $body,
                "A parent would have read a raw placeholder in: {$body}",
            );
        }
    }

    /**
     * The letter must survive actually being rendered.
     *
     * A global view composer shares a `$school` object with every view, which
     * silently overwrote the letter's own school details. Only rendering the
     * real page catches that, so this test does exactly that.
     */
    public function test_the_admission_letter_page_renders_for_an_admitted_applicant(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->applicant->forceFill(['status' => ApplicantStatus::Admitted])->save();

        $response = $this->actingAs($admin)->get(route('admin.applicants.letter', $this->applicant));

        $response->assertOk();

        $html = (string) $response->getContent();

        // Asserted one fact at a time so a failure names the culprit.
        $this->assertStringContainsString('Saci Schools', $html, 'the letterhead should carry the school name');
        $this->assertStringContainsString('Chiamaka Adeyemi', $html, 'the applicant name should appear');
        $this->assertStringContainsString('SAC-00007', $html, 'the registration number should appear');
        $this->assertStringContainsString('JSS 1', $html, 'the class applied for should appear');
        $this->assertStringContainsString(
            'Dear Mrs. Adeyemi',
            $html,
            'the seeded wording should be substituted, not printed raw',
        );
    }

    public function test_the_admission_letter_is_refused_for_someone_not_admitted(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        // Still only "Registered" — no letter may be issued.
        $this->actingAs($admin)
            ->get(route('admin.applicants.letter', $this->applicant))
            ->assertNotFound();
    }

    public function test_the_admission_letter_downloads_as_a_pdf(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->applicant->forceFill(['status' => ApplicantStatus::Admitted])->save();

        $response = $this->actingAs($admin)->get(route('admin.applicants.letter.pdf', $this->applicant));

        // DomPDF throws on a broken PDF view, so a clean 200 proves it built.
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
    }

    /* ------------------------------------------------------------------ */
    /* Broadcasting                                                        */
    /* ------------------------------------------------------------------ */

    private function admin(): \App\Models\User
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Super Admin');

        return $admin;
    }

    public function test_a_broadcast_that_needs_a_real_payment_is_refused_before_anything_is_queued(): void
    {
        // "Payment received" needs {amount} and {receipt_number}, which only a
        // real payment has. Broadcasting it would print braces at every parent.
        $this->actingAs($this->admin())
            ->post(route('admin.sms.batch.store'), [
                'audience' => 'applicants_all',
                'template_key' => 'payment_received',
            ])
            ->assertSessionHasErrors('template_key');

        $this->assertSame(0, SmsLog::query()->count(), 'nothing may reach the outbox');
    }

    public function test_a_broadcast_the_audience_can_fill_is_queued(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.sms.batch.store'), [
                'audience' => 'applicants_all',
                'template_key' => 'applicant_registered',
            ])
            ->assertRedirect(route('admin.sms.index'));

        $log = SmsLog::query()->sole();

        $this->assertStringContainsString('SAC-00007', $log->body);
        $this->assertStringContainsString('Mrs. Adeyemi', $log->body);
        $this->assertDoesNotMatchRegularExpression('/\{[a-z0-9_]+\}/i', $log->body);
    }

    /**
     * The screen counts every audience and samples its placeholder values, so
     * simply loading it exercises every audience query. A wrong column name in
     * one of those was a 500 in the browser while the tests stayed green.
     */
    public function test_the_broadcast_screen_loads_with_every_audience(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.sms.batch'))
            ->assertOk()
            ->assertSee('All applicants')
            ->assertSee('Students owing fees');
    }

    public function test_every_audience_can_be_broadcast_to(): void
    {
        $admin = $this->admin();

        $audiences = [
            'applicants_all',
            'applicants_registered',
            'applicants_admitted',
            'applicants_rejected',
            'students_all',
            'students_owing',
        ];

        foreach ($audiences as $audience) {
            $this->actingAs($admin)
                ->post(route('admin.sms.batch.store'), [
                    'audience' => $audience,
                    'template_key' => 'applicant_registered',
                ])
                ->assertSessionHasNoErrors();
        }
    }
}

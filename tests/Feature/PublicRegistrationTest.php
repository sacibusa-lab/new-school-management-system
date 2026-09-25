<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admission registration is done by the school office, not by applicants.
 *
 * The switch is `registration_open` AND the academic session being open for
 * admissions — both, not either. The office side must never be affected by it.
 */
class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        Setting::flush();

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
    }

    private function setRegistration(bool $open): void
    {
        Setting::put('registration_open', $open, ['type' => 'bool']);
        Setting::flush();
    }

    /* ------------------------------------------------------------------ */
    /* The public form                                                     */
    /* ------------------------------------------------------------------ */

    public function test_the_public_form_is_closed_by_default(): void
    {
        // The seeder ships with it on, so the default that matters is what the
        // application assumes when the setting is missing entirely.
        Setting::query()->where('key', 'registration_open')->delete();
        Setting::flush();

        $this->get('/admissions/apply')
            ->assertRedirect(route('public.status'))
            ->assertSessionHas('error');
    }

    public function test_the_public_form_is_closed_when_the_switch_is_off(): void
    {
        $this->setRegistration(false);

        $this->get('/admissions/apply')
            ->assertRedirect(route('public.status'))
            ->assertSessionHas('error');
    }

    /** The setting alone is not enough — admissions must be open for the session. */
    public function test_the_public_form_is_closed_when_the_session_is_not_accepting_admissions(): void
    {
        $this->setRegistration(true);

        $this->session->forceFill(['is_admission_open' => false])->save();

        $this->get('/admissions/apply')
            ->assertRedirect(route('public.status'))
            ->assertSessionHas('error');
    }

    public function test_the_form_comes_back_when_both_the_switch_and_the_session_are_open(): void
    {
        $this->setRegistration(true);

        // Asserted on the form's own action, which only the real form has.
        $this->get('/admissions/apply')
            ->assertOk()
            ->assertSee(route('public.register.store'), false);
    }

    public function test_posting_to_the_closed_form_registers_nobody(): void
    {
        $this->setRegistration(false);

        // The request is rejected one way or another — either the closed-form
        // redirect or validation. Either way no record may be created.
        $this->from('/admissions/apply')->post('/admissions/apply', [
            'first_name' => 'Sneaky',
            'last_name' => 'Poster',
            'gender' => 'male',
            'date_of_birth' => '2013-01-01',
            'phone' => '08031234567',
            'address' => '1 Test Road',
            'state' => 'Lagos',
            'level_applied_for_id' => SchoolLevel::query()->first()->id,
            'guardian_name' => 'Mr. Poster',
            'guardian_relationship' => 'Father',
            'guardian_phone' => '08031234567',
            'declaration' => '1',
        ]);

        $this->assertSame(0, Applicant::query()->count(), 'a closed form must register nobody');
        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* The public site must not advertise it                               */
    /* ------------------------------------------------------------------ */

    public function test_the_landing_page_does_not_offer_online_application_when_closed(): void
    {
        $this->setRegistration(false);

        $response = $this->get('/');

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString(route('public.register'), $html, 'no link to the closed form');
        $this->assertStringNotContainsString('Apply for admission', $html);
        $this->assertStringNotContainsString('Start your application', $html);
        $this->assertStringNotContainsString('Register online', $html);

        // ...and it should tell them where to go instead.
        $this->assertStringContainsString('school office', $html);
    }

    public function test_the_landing_page_offers_online_application_again_when_reopened(): void
    {
        $this->setRegistration(true);

        $html = (string) $this->get('/')->getContent();

        $this->assertStringContainsString(route('public.register'), $html);
        $this->assertStringContainsString('Apply for admission', $html);
    }

    public function test_the_public_footer_does_not_link_to_the_closed_form(): void
    {
        $this->setRegistration(false);

        $html = (string) $this->get('/')->getContent();

        // The footer is on every public page, including ones that never pass the flag.
        $footer = substr($html, (int) strpos($html, '<footer'));

        $this->assertStringNotContainsString(route('public.register'), $footer);
    }

    /* ------------------------------------------------------------------ */
    /* The office is unaffected                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_office_can_still_register_while_the_public_form_is_closed(): void
    {
        $this->setRegistration(false);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->get(route('admin.applicants.create'))
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.applicants.store'), [
                'first_name' => 'Chidera',
                'last_name' => 'Okafor',
                'level_applied_for_id' => SchoolLevel::query()->first()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Applicant::query()->count());
    }

    public function test_the_bulk_upload_is_unaffected_too(): void
    {
        $this->setRegistration(false);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)->get(route('admin.applicants.import'))->assertOk();
    }
}

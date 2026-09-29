<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\User;
use App\Services\Admissions\DuplicateApplicantService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registering the same child twice.
 *
 * The office types children in one at a time, and a sheet arrives from the staff
 * room on paper — so the same name can reach the register twice without anybody
 * meaning it. Catching it is worth a warning; refusing to register a real child
 * would not be, because the system cannot tell a duplicate from a twin.
 *
 * The rule is name AND phone. A parent's number on its own proves nothing:
 * brothers and sisters share it.
 */
class DuplicateApplicantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        AcademicSession::create([
            'name' => '2026/2027',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'level_applied_for_id' => SchoolLevel::query()->sole()->id,
            'guardian_name' => 'Mrs. Ngozi Okafor',
            'guardian_relationship' => 'Mother',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'ngozi@example.com',
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload($overrides));
    }

    private function onFile(string $first, string $last, string $phone): Applicant
    {
        return Applicant::create([
            'registration_number' => 'SAC-' . str_pad((string) (Applicant::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'guardian_phone' => $phone,
            'guardian_email' => 'parent@example.com',
            'level_applied_for_id' => SchoolLevel::query()->sole()->id,
            'academic_session_id' => AcademicSession::query()->sole()->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The matching rule                                                   */
    /* ------------------------------------------------------------------ */

    public function test_the_same_name_and_number_is_a_duplicate(): void
    {
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $this->assertCount(1, app(DuplicateApplicantService::class)->find('Chidera', 'Okafor', '08031234567'));
    }

    public function test_a_sibling_sharing_the_parent_number_is_not_a_duplicate(): void
    {
        // Same parent, different child. Warning here would make the office ignore
        // the warning, because every family with two children would set it off.
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $this->assertCount(
            0,
            app(DuplicateApplicantService::class)->find('Ngozi', 'Okafor', '08031234567'),
            'a brother or sister is not a duplicate',
        );
    }

    public function test_the_same_name_with_a_different_number_is_not_a_duplicate(): void
    {
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $this->assertCount(0, app(DuplicateApplicantService::class)->find('Chidera', 'Okafor', '08099999999'));
    }

    public function test_capitals_and_the_international_form_of_the_number_still_match(): void
    {
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $service = app(DuplicateApplicantService::class);

        $this->assertCount(1, $service->find('CHIDERA', 'okafor', '08031234567'));
        $this->assertCount(1, $service->find('chidera', 'OKAFOR', '0803 123 4567'));
        $this->assertCount(1, $service->find('Chidera', 'Okafor', '+2348031234567'));
    }

    /* ------------------------------------------------------------------ */
    /* Through the registration screen                                     */
    /* ------------------------------------------------------------------ */

    public function test_registering_the_same_child_twice_warns_but_still_registers(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $this->register()->assertSessionHasNoErrors();

        $this->assertSame(2, Applicant::query()->count(), 'a warning must not refuse a real child');

        // The second attempt is answered with the warning, and the first is not.
        $this->register()
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'SAC-00001'));
    }

    public function test_the_first_registration_of_a_name_says_nothing(): void
    {
        $this->register()->assertSessionMissing('warning');
    }

    public function test_a_sibling_registers_with_no_warning(): void
    {
        $this->register()->assertSessionMissing('warning');

        $this->register(['first_name' => 'Ngozi'])->assertSessionMissing('warning');

        $this->assertSame(2, Applicant::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Through the bulk importer                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_import_preview_flags_a_row_that_is_already_on_file(): void
    {
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'candidates.csv',
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . "Okafor,Ngozi,JSS1,08031234567,ngozi@example.com\n",
            ),
        ])->assertRedirect();

        $rows = session('applicant_import')['rows'];

        $this->assertCount(2, $rows);

        // Chidera is already there; the sibling is not.
        $this->assertNotEmpty($rows[0]['duplicates']);
        $this->assertStringContainsString('SAC-00001', $rows[0]['duplicates'][0]);
        $this->assertSame([], $rows[1]['duplicates']);
    }

    /** A sheet full of mistakes must not be reported as a sheet of duplicates. */
    public function test_a_row_that_already_has_errors_is_not_also_called_a_duplicate(): void
    {
        $this->onFile('Chidera', 'Okafor', '08031234567');

        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'candidates.csv',
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,,\n",
            ),
        ])->assertRedirect();

        $rows = session('applicant_import')['rows'];

        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertArrayNotHasKey('duplicates', $rows[0]);
    }
}

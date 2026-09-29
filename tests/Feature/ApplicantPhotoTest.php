<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\User;
use App\Services\Admissions\ApplicantPhotoService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attaching a photograph to the wrong child is the failure that matters here: an
 * exam-day identity check would be against the wrong face. So these tests are
 * mostly about the matching refusing to guess.
 */
class ApplicantPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        Setting::flush();

        Storage::fake('local');
        Storage::fake('public');

        $this->officer = User::factory()->create();
        $this->officer->assignRole('Admission Officer');

        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        foreach ([['SAC-00001', 'Chidera', 'Okafor'], ['SAC-00002', 'Aisha', 'Bello']] as [$number, $first, $last]) {
            Applicant::create([
                'registration_number' => $number,
                'first_name' => $first,
                'last_name' => $last,
                'guardian_phone' => '08031234567',
                'guardian_email' => strtolower($first) . '@example.com',
                'level_applied_for_id' => $level->id,
                'academic_session_id' => $session->id,
            ]);
        }
    }

    private function photo(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 300, 400);
    }

    private function applicant(string $number): Applicant
    {
        return Applicant::query()->where('registration_number', $number)->sole();
    }

    public function test_photographs_are_matched_to_candidates_by_the_name_of_the_file(): void
    {
        $this->actingAs($this->officer)
            ->post(route('admin.applicants.photos.preview'), [
                'photos' => [$this->photo('SAC-00001.jpg'), $this->photo('SAC-00002.png')],
            ])
            ->assertRedirect(route('admin.applicants.photos'));

        $staged = session(ApplicantPhotoService::SESSION_KEY);

        $this->assertCount(2, $staged['rows']);
        $this->assertSame('Chidera Okafor', $staged['rows'][0]['applicant_name']);
        $this->assertSame('Aisha Bello', $staged['rows'][1]['applicant_name']);
        $this->assertNull($staged['rows'][0]['problem']);
    }

    public function test_the_number_is_matched_however_the_file_is_named(): void
    {
        // The same child, written four ways. This is the same rule the public
        // lookup uses, so a school's filing habit does not have to change.
        foreach (['SAC-1.jpg', 'sac00001.png', 'SAC 00001.webp', 'sac_00001.jpg'] as $name) {
            $this->actingAs($this->officer)->post(route('admin.applicants.photos.preview'), [
                'photos' => [$this->photo($name)],
            ]);

            $staged = session(ApplicantPhotoService::SESSION_KEY);

            $this->assertSame(
                'SAC-00001',
                $staged['rows'][0]['registration_number'],
                "“{$name}” should have found SAC-00001",
            );
        }
    }

    public function test_a_photograph_that_matches_nobody_is_reported_rather_than_guessed(): void
    {
        $this->actingAs($this->officer)->post(route('admin.applicants.photos.preview'), [
            'photos' => [$this->photo('SAC-00099.jpg')],
        ]);

        $row = session(ApplicantPhotoService::SESSION_KEY)['rows'][0];

        $this->assertNull($row['applicant_id']);
        $this->assertNotNull($row['problem']);
        $this->assertStringContainsString('No applicant', $row['problem']);
    }

    public function test_reading_the_photographs_attaches_nothing_yet(): void
    {
        $this->actingAs($this->officer)->post(route('admin.applicants.photos.preview'), [
            'photos' => [$this->photo('SAC-00001.jpg')],
        ]);

        // The whole point of the review step.
        $this->assertNull($this->applicant('SAC-00001')->photo_path);
        Storage::disk('public')->assertDirectoryEmpty(config('saci.uploads.photos') . '/applicants');
    }

    public function test_committing_attaches_only_the_ticked_photographs(): void
    {
        $this->actingAs($this->officer)->post(route('admin.applicants.photos.preview'), [
            'photos' => [$this->photo('SAC-00001.jpg'), $this->photo('SAC-00002.jpg')],
        ]);

        // Untick the second one.
        $this->actingAs($this->officer)
            ->post(route('admin.applicants.photos.commit'), ['rows' => [0]])
            ->assertRedirect(route('admin.applicants.index'));

        $first = $this->applicant('SAC-00001');
        $second = $this->applicant('SAC-00002');

        $this->assertNotNull($first->photo_path);
        Storage::disk('public')->assertExists($first->photo_path);

        $this->assertNull($second->photo_path, 'the unticked photograph must not be attached');
    }

    public function test_committing_clears_the_staged_files_away(): void
    {
        $this->actingAs($this->officer)->post(route('admin.applicants.photos.preview'), [
            'photos' => [$this->photo('SAC-00001.jpg')],
        ]);

        $staged = session(ApplicantPhotoService::SESSION_KEY)['rows'][0]['path'];
        Storage::disk('local')->assertExists($staged);

        $this->actingAs($this->officer)->post(route('admin.applicants.photos.commit'), ['rows' => [0]]);

        // A photograph nobody claimed is a file with a child's face in it sitting
        // on the server, so the batch is emptied either way.
        Storage::disk('local')->assertMissing($staged);
        $this->assertNull(session(ApplicantPhotoService::SESSION_KEY));
    }

    public function test_a_photograph_can_be_replaced_and_the_old_file_goes(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->actingAs($this->officer)->post(route('admin.applicants.photo.update', $applicant), [
            'photo' => $this->photo('first.jpg'),
        ]);

        $first = $applicant->refresh()->photo_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->officer)->post(route('admin.applicants.photo.update', $applicant), [
            'photo' => $this->photo('second.jpg'),
        ]);

        $second = $applicant->refresh()->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_a_photograph_can_be_taken_off_an_applicant(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->actingAs($this->officer)->post(route('admin.applicants.photo.update', $applicant), [
            'photo' => $this->photo('face.jpg'),
        ]);

        $path = $applicant->refresh()->photo_path;

        $this->actingAs($this->officer)
            ->delete(route('admin.applicants.photo.destroy', $applicant))
            ->assertRedirect();

        $this->assertNull($applicant->refresh()->photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_something_that_is_not_a_photograph_is_refused(): void
    {
        $this->actingAs($this->officer)
            ->post(route('admin.applicants.photos.preview'), [
                'photos' => [UploadedFile::fake()->create('SAC-00001.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrors('photos.0');

        $this->assertNull(session(ApplicantPhotoService::SESSION_KEY));
    }

    public function test_the_screen_counts_how_many_candidates_still_have_no_photograph(): void
    {
        $this->actingAs($this->officer)
            ->get(route('admin.applicants.photos'))
            ->assertOk()
            ->assertSee('No photo yet')
            ->assertSee('SAC-00001.jpg');
    }
}

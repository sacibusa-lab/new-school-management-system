<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\User;
use App\Services\Admissions\ApplicantDocumentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The papers a family brings to the office.
 *
 * The column was already there and already being displayed, so every applicant
 * page showed an empty list with no way to fill it — the office could see that
 * documents belonged against a child, but could not attach one.
 *
 * The thing worth guarding is deletion. The path a document is removed by comes
 * back from the browser, so a request that names somebody else's file, or the
 * school's logo, must not be able to delete it.
 */
class ApplicantDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        Setting::flush();

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

    private function applicant(string $number): Applicant
    {
        return Applicant::query()->where('registration_number', $number)->sole();
    }

    private function attach(Applicant $applicant, array $files)
    {
        return $this->actingAs($this->officer)
            ->post(route('admin.applicants.documents.store', $applicant), ['documents' => $files]);
    }

    public function test_a_document_can_be_attached_to_an_applicant(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->attach($applicant, [UploadedFile::fake()->create('birth certificate.pdf', 300, 'application/pdf')])
            ->assertRedirect()
            ->assertSessionHas('status');

        $documents = $applicant->refresh()->documents;

        $this->assertCount(1, $documents);
        // The office's own file name is kept: "birth certificate" says more than
        // any label this system could invent.
        $this->assertSame('birth certificate.pdf', $documents[0]['name']);

        Storage::disk('public')->assertExists($documents[0]['path']);
        $this->assertStringStartsWith('documents/applicants/', $documents[0]['path']);
    }

    public function test_several_papers_can_be_handed_over_at_once(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->attach($applicant, [
            UploadedFile::fake()->create('testimonial.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('photograph.jpg'),
        ]);

        $documents = $applicant->refresh()->documents;

        $this->assertCount(2, $documents);
        $this->assertSame(['testimonial.pdf', 'photograph.jpg'], array_column($documents, 'name'));
    }

    public function test_a_document_that_is_not_a_paper_or_a_picture_is_refused(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->attach($applicant, [UploadedFile::fake()->create('virus.exe', 10)])
            ->assertSessionHasErrors('documents.0');

        $this->assertNull($applicant->refresh()->documents);
    }

    public function test_a_document_bigger_than_the_limit_is_refused(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $over = (int) (ApplicantDocumentService::MAX_KB / 1024) + 1;

        $this->attach($applicant, [
            UploadedFile::fake()->create('baptismal card.pdf', $over * 1024, 'application/pdf'),
        ])->assertSessionHasErrors('documents.0');

        $this->assertNull($applicant->refresh()->documents);
    }

    public function test_a_document_can_be_removed_and_the_file_goes_with_it(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->attach($applicant, [UploadedFile::fake()->create('birth certificate.pdf', 100, 'application/pdf')]);

        $path = $applicant->refresh()->documents[0]['path'];

        $this->actingAs($this->officer)
            ->delete(route('admin.applicants.documents.destroy', $applicant), ['path' => $path])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame([], $applicant->refresh()->documents);
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * The path comes back from the browser. If it did not have to belong to this
     * applicant first, any signed-in user could name the school's logo — or the
     * next applicant's birth certificate — and have it deleted.
     */
    public function test_a_document_held_by_somebody_else_cannot_be_deleted_through_this_applicant(): void
    {
        $mine = $this->applicant('SAC-00001');
        $theirs = $this->applicant('SAC-00002');

        $this->attach($theirs, [UploadedFile::fake()->create('birth certificate.pdf', 100, 'application/pdf')]);

        $theirPath = $theirs->refresh()->documents[0]['path'];

        $this->actingAs($this->officer)
            ->delete(route('admin.applicants.documents.destroy', $mine), ['path' => $theirPath])
            ->assertSessionHas('error');

        // Both the record and the file are untouched.
        $this->assertNull($mine->refresh()->documents);
        $this->assertCount(1, $theirs->refresh()->documents);
        Storage::disk('public')->assertExists($theirPath);
    }

    public function test_a_file_that_is_not_a_document_at_all_cannot_be_deleted(): void
    {
        $applicant = $this->applicant('SAC-00001');

        Storage::disk('public')->put('branding/logo.png', 'pretend logo');

        $this->actingAs($this->officer)
            ->delete(route('admin.applicants.documents.destroy', $applicant), ['path' => 'branding/logo.png'])
            ->assertSessionHas('error');

        Storage::disk('public')->assertExists('branding/logo.png');
    }

    public function test_an_applicant_page_can_attach_a_document_and_lists_what_is_held(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->actingAs($this->officer)
            ->get(route('admin.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('Attach documents')
            ->assertSee(route('admin.applicants.documents.store', $applicant), false);

        $this->attach($applicant, [UploadedFile::fake()->create('testimonial.pdf', 100, 'application/pdf')]);

        $this->actingAs($this->officer)
            ->get(route('admin.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('testimonial.pdf')
            ->assertSee('1 document(s)');
    }

    public function test_somebody_who_cannot_edit_applicants_cannot_attach_or_remove_documents(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $this->attach($applicant, [UploadedFile::fake()->create('birth certificate.pdf', 100, 'application/pdf')]);
        $path = $applicant->refresh()->documents[0]['path'];

        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->post(route('admin.applicants.documents.store', $applicant), [
                'documents' => [UploadedFile::fake()->create('sneaky.pdf', 100, 'application/pdf')],
            ])
            ->assertForbidden();

        $this->actingAs($teacher)
            ->delete(route('admin.applicants.documents.destroy', $applicant), ['path' => $path])
            ->assertForbidden();

        $this->assertCount(1, $applicant->refresh()->documents);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_list_refuses_to_keep_more_papers_than_the_limit(): void
    {
        $applicant = $this->applicant('SAC-00001');

        $service = app(ApplicantDocumentService::class);

        for ($i = 0; $i < ApplicantDocumentService::MAX_DOCUMENTS; $i++) {
            $service->store($applicant, UploadedFile::fake()->create("paper {$i}.pdf", 10, 'application/pdf'));
        }

        $this->expectException(\RuntimeException::class);

        $service->store($applicant->refresh(), UploadedFile::fake()->create('one too many.pdf', 10, 'application/pdf'));
    }
}

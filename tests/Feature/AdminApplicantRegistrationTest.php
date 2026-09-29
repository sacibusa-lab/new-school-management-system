<?php

namespace Tests\Feature;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admissions\ApplicantImportService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Registration is done by the office, so it must be quick to type and safe to
 * do in bulk. The bulk path in particular must never create a half-understood
 * row — the office reviews the parsed sheet before anything is written.
 */
class AdminApplicantRegistrationTest extends TestCase
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
        SchoolLevel::create(['name' => 'SS1', 'order' => 2]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /** A CSV upload the way the browser would send it. */
    private function csv(string $contents, string $name = 'candidates.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /**
     * The least the office has to type: a name, a class, and the parent's phone
     * and email, which are needed to open the fee account.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'level_applied_for_id' => SchoolLevel::query()->orderBy('order')->first()->id,
            'guardian_name' => 'Mrs. Ngozi Okafor',
            'guardian_relationship' => 'Mother',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'ngozi@example.com',
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* Typing one applicant in                                             */
    /* ------------------------------------------------------------------ */

    public function test_an_officer_can_register_an_applicant_from_the_office(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), $this->payload())
            ->assertRedirect();

        $applicant = Applicant::query()->sole();

        $this->assertSame('SAC-00001', $applicant->registration_number);
        $this->assertSame('Chidera Okafor', $applicant->full_name);
        $this->assertSame(ApplicantStatus::Registered, $applicant->status);
    }

    public function test_the_parent_contact_details_are_stored_for_the_fee_account(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), $this->payload([
                'guardian_name' => 'Mr. Paul Okafor',
                'guardian_relationship' => 'Father',
                'guardian_phone' => '08039876543',
                'guardian_email' => 'paul@example.com',
            ]))
            ->assertSessionHasNoErrors();

        $applicant = Applicant::query()->sole();

        $this->assertSame('Mr. Paul Okafor', $applicant->guardian_name);
        $this->assertSame('Father', $applicant->guardian_relationship);
        $this->assertSame('08039876543', $applicant->guardian_phone);
        $this->assertSame('paul@example.com', $applicant->guardian_email);
    }

    public function test_the_parent_phone_and_email_are_required(): void
    {
        // They are the details the fee account is opened in, so a record without
        // them cannot be taken any further.
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), [
                'first_name' => 'Chidera',
                'last_name' => 'Okafor',
                'level_applied_for_id' => SchoolLevel::query()->first()->id,
            ])
            ->assertSessionHasErrors(['guardian_phone', 'guardian_email']);

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_a_bad_parent_email_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), $this->payload(['guardian_email' => 'not-an-email']))
            ->assertSessionHasErrors('guardian_email');

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_the_applicants_own_contact_details_are_not_collected(): void
    {
        // The parent is the account holder; the child has no phone or email of
        // their own on the form.
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), $this->payload([
                'phone' => '08030000000',
                'email' => 'child@example.com',
            ]))
            ->assertSessionHasNoErrors();

        $applicant = Applicant::query()->sole();

        $this->assertNull($applicant->phone);
        $this->assertNull($applicant->email);
    }

    public function test_only_the_name_class_and_parent_contact_are_needed(): void
    {
        // A paper form often arrives without the address; the record must still open.
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull(Applicant::query()->sole()->address);
    }

    public function test_a_missing_class_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), [
                'first_name' => 'Aisha',
                'last_name' => 'Bello',
            ])
            ->assertSessionHasErrors('level_applied_for_id');

        $this->assertSame(0, Applicant::query()->count());
    }

    /** A bursar may view applicants but must not open records. */
    public function test_somebody_without_the_permission_cannot_reach_the_registration_page(): void
    {
        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($bursar)->get(route('admin.applicants.create'))->assertForbidden();
        $this->actingAs($bursar)->get(route('admin.applicants.import'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Classes the school does not offer                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_class_the_school_does_not_offer_cannot_be_chosen(): void
    {
        // The school does not run JSS3, so it is switched off rather than deleted.
        $jss3 = SchoolLevel::create(['name' => 'JSS3', 'order' => 3, 'is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.create'))
            ->assertOk()
            ->assertDontSee('JSS3');

        // ...and it must be refused even if somebody posts the id by hand.
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.store'), [
                'first_name' => 'Noah',
                'last_name' => 'Way',
                'level_applied_for_id' => $jss3->id,
            ])
            ->assertSessionHasErrors('level_applied_for_id');

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_a_bulk_row_for_a_class_the_school_does_not_offer_is_flagged(): void
    {
        SchoolLevel::create(['name' => 'JSS3', 'order' => 3, 'is_active' => false]);

        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv("Surname,First name,Class\nWay,Noah,JSS3\n"),
        ]);

        $row = session('applicant_import')['rows'][0];

        $this->assertStringContainsString('does not match any class', implode(' ', $row['errors']));
    }

    /* ------------------------------------------------------------------ */
    /* The registration slip                                               */
    /* ------------------------------------------------------------------ */

    /** The flash message after registering promises a slip; this proves it exists. */
    public function test_the_registration_slip_shows_the_number_and_the_class(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
            'gender' => 'female',
        ]));

        $applicant = Applicant::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.slip', $applicant))
            ->assertOk()
            ->assertSee('Registration slip')
            ->assertSee('SAC-00001')
            ->assertSee('Chidera Okafor')
            ->assertSee('JSS1');
    }

    public function test_the_slip_says_so_when_the_candidate_has_no_examination_yet(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
            'first_name' => 'Aisha',
            'last_name' => 'Bello',
        ]));

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.slip', Applicant::query()->sole()))
            ->assertOk()
            ->assertSee('has not yet been entered for an entrance examination');
    }

    public function test_the_slip_carries_the_examination_date_and_venue_once_entered(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload());

        $applicant = Applicant::query()->sole();

        // Register the candidate for a sitting the way the exam screen does.
        $exam = Exam::create([
            'title' => 'Entrance Examination 2026/2027',
            'academic_session_id' => $applicant->academic_session_id,
            'level_id' => $applicant->level_applied_for_id,
            'exam_date' => '2026-11-14',
            'starts_at' => '08:00',
            'venue' => 'Main Hall',
            'status' => ExamStatus::Scheduled,
        ]);

        $paper = ExamSubject::create([
            'exam_id' => $exam->id,
            'subject_id' => Subject::create(['name' => 'Mathematics', 'code' => 'MTH'])->id,
            'total_marks' => 100,
        ]);

        Score::create([
            'exam_id' => $exam->id,
            'exam_subject_id' => $paper->id,
            'applicant_id' => $applicant->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.slip', $applicant))
            ->assertOk()
            ->assertSee('Entrance examination')
            ->assertSee('Main Hall')
            ->assertSee('14 November 2026')
            ->assertDontSee('has not yet been entered for an entrance examination');
    }

    /* ------------------------------------------------------------------ */
    /* Export                                                              */
    /* ------------------------------------------------------------------ */

    public function test_the_applicant_list_exports_as_csv(): void
    {
        foreach ([['Chidera', 'Okafor'], ['Aisha', 'Bello']] as [$first, $last]) {
            $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
                'first_name' => $first,
                'last_name' => $last,
            ]));
        }

        $response = $this->actingAs($this->admin)->get(route('admin.applicants.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        $csv = (string) $response->getContent();

        $this->assertStringContainsString('Registration number', $csv);
        $this->assertStringContainsString('SAC-00001', $csv);
        $this->assertStringContainsString('SAC-00002', $csv);
        $this->assertStringContainsString('Okafor', $csv);
        $this->assertStringContainsString('JSS1', $csv);
    }

    /** The file must contain what the officer was looking at, not everything. */
    public function test_the_export_respects_the_active_filters(): void
    {
        $levels = SchoolLevel::query()->orderBy('order')->get();

        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
            'level_applied_for_id' => $levels[0]->id,
        ]));

        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
            'first_name' => 'Aisha',
            'last_name' => 'Bello',
            'level_applied_for_id' => $levels[1]->id,
        ]));

        $csv = (string) $this->actingAs($this->admin)
            ->get(route('admin.applicants.export', ['level' => $levels[0]->id]))
            ->getContent();

        $this->assertStringContainsString('SAC-00001', $csv);
        $this->assertStringNotContainsString('SAC-00002', $csv);
    }

    public function test_only_a_role_with_the_export_permission_can_download_the_list(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('admin.applicants.export'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Passport and documents                                              */
    /* ------------------------------------------------------------------ */

    public function test_a_passport_photograph_taken_at_the_desk_is_stored_and_shown(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload([
            'photo' => UploadedFile::fake()->image('passport.jpg'),
        ]))->assertSessionHasNoErrors();

        $applicant = Applicant::query()->sole();

        $this->assertNotNull($applicant->photo_path);
        Storage::disk('public')->assertExists($applicant->photo_path);

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('storage/' . $applicant->photo_path)
            ->assertDontSee('No photograph');
    }

    /**
     * The office form no longer collects documents, but records that already carry
     * them (from the public form, or from before) must still display.
     */
    public function test_documents_are_still_shown_when_a_record_has_any(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload());

        $applicant = Applicant::query()->sole();
        $applicant->forceFill(['documents' => [[
            'name' => 'birth-certificate.pdf',
            'path' => 'uploads/documents/applicants/birth-certificate.pdf',
            'size' => '2048',
        ]]])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('birth-certificate.pdf')
            ->assertSee('storage/uploads/documents/applicants/birth-certificate.pdf', false);
    }

    /**
     * This card used to disappear when there was neither a photograph nor a
     * document — which hid the upload control from exactly the applicants who
     * need it, because names arrive in a spreadsheet and photographs do not.
     */
    public function test_the_documents_card_still_offers_a_photograph_when_nothing_is_held(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload());

        $applicant = Applicant::query()->sole();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.applicants.show', $applicant))
            ->assertOk()
            ->assertSee('Passport and documents')
            ->assertSee('No photograph');

        // One form posts the photograph; only a record that already has one gets
        // a second form offering to take it back.
        $this->assertSame(
            1,
            substr_count($response->getContent(), route('admin.applicants.photo.update', $applicant)),
            'An applicant with no photograph should have exactly one photograph form.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Editing                                                             */
    /* ------------------------------------------------------------------ */

    /** The route and the Edit button both existed while this view did not. */
    public function test_the_edit_screen_opens(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload());

        $applicant = Applicant::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.edit', $applicant))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertSee($applicant->registration_number);
    }

    public function test_an_edited_applicant_keeps_their_registration_number(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.store'), $this->payload());

        $applicant = Applicant::query()->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.applicants.update', $applicant), [
                'first_name' => 'Chidera',
                'last_name' => 'Okonkwo',
                'level_applied_for_id' => $applicant->level_applied_for_id,
                'status' => ApplicantStatus::Registered->value,
            ])
            ->assertRedirect(route('admin.applicants.show', $applicant));

        $applicant->refresh();

        $this->assertSame('Chidera Okonkwo', $applicant->full_name);
        $this->assertSame('SAC-00001', $applicant->registration_number);
    }

    /* ------------------------------------------------------------------ */
    /* Bulk upload                                                         */
    /* ------------------------------------------------------------------ */

    public function test_a_spreadsheet_is_reviewed_before_anything_is_registered(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.import.preview'), [
                'file' => $this->csv(
                    "Surname,First name,Class,Gender,Date of birth,Parent phone,Parent email\n"
                    . "Okafor,Chidera,JSS1,Female,2013-03-12,08031234567,ngozi@example.com\n"
                    . "Bello,Aisha,SS1,M,12/03/2013,08031234568,bello@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.applicants.import'));

        // The whole point: nothing exists yet.
        $this->assertSame(0, Applicant::query()->count());

        $staged = session('applicant_import');

        $this->assertNotNull($staged);
        $this->assertSame(2, $staged['summary']['total']);
        $this->assertSame(2, $staged['summary']['ok']);
        $this->assertSame(0, $staged['summary']['errors']);
    }

    public function test_committing_the_review_registers_only_the_ticked_rows(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . "Bello,Aisha,SS1,08031234568,bello@example.com\n"
                . "Eze,Emeka,SS1,08031234569,eze@example.com\n",
            ),
        ]);

        $lines = collect(session('applicant_import')['rows'])->pluck('line')->all();

        // Untick the middle candidate.
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.import.commit'), [
                'lines' => [$lines[0], $lines[2]],
            ])
            ->assertRedirect(route('admin.applicants.index'));

        $applicants = Applicant::query()->orderBy('id')->get();

        $this->assertCount(2, $applicants);
        $this->assertSame(['Chidera Okafor', 'Emeka Eze'], $applicants->pluck('full_name')->all());

        // Numbers are issued in the order the rows appeared in the file.
        $this->assertSame(['SAC-00001', 'SAC-00002'], $applicants->pluck('registration_number')->all());

        // And the staged upload is cleared so it cannot be submitted twice.
        $this->assertNull(session('applicant_import'));
    }

    public function test_a_class_that_does_not_exist_is_flagged_rather_than_guessed(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . "Bello,Aisha,JSS9,08031234568,bello@example.com\n",
            ),
        ]);

        $staged = session('applicant_import');

        $this->assertSame(1, $staged['summary']['ok']);
        $this->assertSame(1, $staged['summary']['errors']);

        $bad = collect($staged['rows'])->firstWhere('class', 'JSS9');

        $this->assertStringContainsString('does not match any class', implode(' ', $bad['errors']));
    }

    public function test_committing_skips_rows_with_errors_even_if_they_are_ticked(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . ",,JSS1,,\n",
            ),
        ]);

        $staged = session('applicant_import');
        $allLines = collect($staged['rows'])->pluck('line')->all();

        $this->actingAs($this->admin)
            ->post(route('admin.applicants.import.commit'), ['lines' => $allLines]);

        $this->assertCount(1, Applicant::query()->get());
    }

    public function test_columns_are_matched_however_the_office_spells_them(): void
    {
        // Different order, different spellings, and a column we do not know.
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Candidate Surname,Other Names,Class Applied For,Sex,Dob,Parent Phone,Parent Email,Nickname\n"
                . "Okafor,Chidera Ada,JSS 1,F,12/03/2013,08031234567,ngozi@example.com,Chi\n",
            ),
        ]);

        $row = session('applicant_import')['rows'][0];

        $this->assertSame([], $row['errors'], 'loose headings should still parse');
        $this->assertSame('Female', $row['gender']);
        $this->assertSame('JSS1', $row['class']);
        $this->assertStringContainsString('2013', (string) $row['dob']);

        // "Parent Email" must land on the parent, not be swallowed by the
        // shorter "email" heading.
        $this->assertSame('08031234567', $row['data']['guardian_phone']);
        $this->assertSame('ngozi@example.com', $row['data']['guardian_email']);
    }

    public function test_a_gender_we_cannot_read_is_flagged(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Gender,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,Unknown,08031234567,ngozi@example.com\n",
            ),
        ]);

        $row = session('applicant_import')['rows'][0];

        $this->assertStringContainsString('not understood', implode(' ', $row['errors']));
    }

    public function test_a_file_without_recognisable_headings_is_refused_with_a_useful_message(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.import.preview'), [
                'file' => $this->csv("alpha,beta,gamma\n1,2,3\n"),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_blank_spacer_rows_are_ignored_rather_than_reported(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . ",,,\n"
                . "Bello,Aisha,SS1,08031234568,bello@example.com\n",
            ),
        ]);

        $this->assertSame(2, session('applicant_import')['summary']['total']);
        $this->assertSame(0, session('applicant_import')['summary']['errors']);
    }

    public function test_committing_without_a_staged_file_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.applicants.import.commit'), ['lines' => [2]])
            ->assertRedirect(route('admin.applicants.import'))
            ->assertSessionHas('error');

        $this->assertSame(0, Applicant::query()->count());
    }

    public function test_the_template_downloads_with_the_expected_headings(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.applicants.import.template'));

        $response->assertOk();

        $this->assertStringContainsString('Surname', $response->getContent());
        $this->assertStringContainsString('Class', $response->getContent());
        $this->assertStringContainsString('Parent phone', $response->getContent());
        $this->assertStringContainsString('Parent email', $response->getContent());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        // A ready-made example row, so the office can see the shape at a glance.
        $this->assertStringContainsString('ngozi@example.com', $response->getContent());
    }

    public function test_a_bulk_upload_does_not_try_to_send_a_text_message_per_row(): void
    {
        // Sending from a loop would hold the request open for one HTTP call per
        // candidate; the office broadcasts from the text messages screen instead.
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08031234567,ngozi@example.com\n"
                . "Bello,Aisha,SS1,08031234568,bello@example.com\n",
            ),
        ]);

        $this->actingAs($this->admin)->post(route('admin.applicants.import.commit'), [
            'lines' => collect(session('applicant_import')['rows'])->pluck('line')->all(),
        ]);

        $this->assertSame(2, Applicant::query()->count());
        $this->assertSame(0, \App\Models\SmsLog::query()->count());
    }

    public function test_the_imported_guardian_phone_is_kept_for_later_messaging(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent name,Parent phone,Relationship,Parent email\n"
                . "Okafor,Chidera,JSS1,Mrs. Ngozi Okafor,08031234567,Mother,ngozi@example.com\n",
            ),
        ]);

        $this->actingAs($this->admin)->post(route('admin.applicants.import.commit'), [
            'lines' => collect(session('applicant_import')['rows'])->pluck('line')->all(),
        ]);

        $applicant = Applicant::query()->sole();

        $this->assertSame('Mrs. Ngozi Okafor', $applicant->guardian_name);
        $this->assertSame('08031234567', $applicant->guardian_phone);
        $this->assertSame('ngozi@example.com', $applicant->guardian_email);
        $this->assertSame('Mother', $applicant->guardian_relationship);
        $this->assertSame('JSS1', $applicant->levelAppliedFor->name);
    }

    /**
     * The parent's phone and email are the details the fee account is opened in,
     * so a row without them is not registerable — the same rule as the form.
     */
    public function test_a_row_without_the_parent_contact_is_flagged(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,,\n"
                . "Bello,Aisha,SS1,08031234568,not-an-email\n",
            ),
        ]);

        $staged = session('applicant_import');

        $this->assertSame(0, $staged['summary']['ok']);
        $this->assertSame(2, $staged['summary']['errors']);

        $this->assertStringContainsString('Parent phone is missing', implode(' ', $staged['rows'][0]['errors']));
        $this->assertStringContainsString('does not look like an email', implode(' ', $staged['rows'][1]['errors']));
    }

    /**
     * The registration form stopped collecting the applicant's own phone, email
     * and previous school, so the importer must not quietly keep writing them.
     */
    public function test_columns_the_registration_form_dropped_are_no_longer_collected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.applicants.import.preview'), [
            'file' => $this->csv(
                "Surname,First name,Class,Phone,Email,Previous school,Parent phone,Parent email\n"
                . "Okafor,Chidera,JSS1,08099999999,chidera@example.com,St. Mary,08031234567,ngozi@example.com\n",
            ),
        ]);

        $staged = session('applicant_import');

        $this->assertSame([], $staged['rows'][0]['errors']);

        $this->actingAs($this->admin)->post(route('admin.applicants.import.commit'), [
            'lines' => collect($staged['rows'])->pluck('line')->all(),
        ]);

        $applicant = Applicant::query()->sole();

        $this->assertNull($applicant->phone, 'the parent is the account holder, not the child');
        $this->assertNull($applicant->email);
        $this->assertNull($applicant->previous_school);
        $this->assertSame('ngozi@example.com', $applicant->guardian_email);
    }

    /**
     * The template is only the columns that are actually required, so the office
     * can fill it in as quickly as it fills in the form.
     */
    public function test_the_template_carries_only_the_required_columns(): void
    {
        $headers = app(ApplicantImportService::class)->templateHeaders();

        $this->assertSame(['Surname', 'First name', 'Class', 'Parent phone', 'Parent email'], $headers);

        // Everything else is still understood — it is just not printed.
        $guide = collect(app(ApplicantImportService::class)->allColumns());

        $this->assertContains('Gender', $guide->pluck('label')->all());
        $this->assertContains('Date of birth', $guide->pluck('label')->all());
        $this->assertNotContains('Previous school', $guide->pluck('label')->all());
        $this->assertNotContains('Phone', $guide->pluck('label')->all());
    }
}

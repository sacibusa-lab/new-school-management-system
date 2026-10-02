<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A student's own record.
 *
 * Three screens open it — the eye on the register, View on the students list, and
 * Student record under an admitted applicant — and for a long time it had a
 * controller, a route and no view at all. That is a 500 rather than an empty page,
 * so the first thing pinned down here is simply that it opens, and the second is
 * that the links pointing at it land on something.
 */
class StudentRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $year;

    private SchoolLevel $jss1;

    private SchoolClass $jss1a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->year = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'is_current' => true,
        ]);

        $this->jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);

        $this->jss1a = SchoolClass::create([
            'level_id' => $this->jss1->id,
            'section_id' => Section::create(['name' => 'A', 'order' => 1])->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);
    }

    public function test_the_record_opens(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Ada Okonkwo')
            // The admission number is the one the office knows them by, so it has to
            // be on the page and not only in the url they arrived on.
            ->assertSee('SAC/2026/014')
            ->assertSee('JSS1A')
            ->assertSee('Mrs Okonkwo');
    }

    /**
     * The two lists that link here have to point at a page that opens. A link is not
     * a link if the other end is a 500, and that is exactly what happened: the
     * routes were written before the view was.
     */
    public function test_the_lists_that_link_here_point_at_a_page_that_opens(): void
    {
        $student = $this->student();
        $url = route('admin.students.show', $student);

        foreach ([
            route('admin.students-results.students', ['class' => $this->jss1->id]),
            route('admin.students.index'),
        ] as $list) {
            $this->actingAs($this->admin)
                ->get($list)
                ->assertOk()
                ->assertSee($url, false);
        }

        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_the_record_shows_what_the_fees_have_come_to(): void
    {
        $student = $this->student();

        $this->invoice($student, total: 100_000, paid: 25_000);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('100,000.00')
            ->assertSee('25,000.00')
            // What is left, worked out for the office rather than left to be subtracted
            // by eye across two tables.
            ->assertSee('75,000.00');
    }

    public function test_the_record_says_so_when_nothing_has_been_billed_yet(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('No fees have been raised against this student yet.')
            ->assertSee('Nothing has been paid in against this student yet.');
    }

    public function test_the_record_is_closed_to_somebody_without_the_permission(): void
    {
        $student = $this->student();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.students.show', $student))
            ->assertForbidden();
    }

    /** The one way in that does not need students.view. */
    public function test_a_student_may_open_their_own_record(): void
    {
        $account = User::factory()->create();

        $this->assertFalse($account->can('students.view'));

        $student = $this->student(['user_id' => $account->id]);

        $this->actingAs($account)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Ada Okonkwo');
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /** @param  array<string,mixed>  $extra */
    private function student(array $extra = []): Student
    {
        return Student::create($extra + [
            'student_number' => 'SAC/2026/014',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $this->jss1->id,
            'school_class_id' => $this->jss1a->id,
            'academic_session_id' => $this->year->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }

    private function invoice(Student $student, float $total, float $paid): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV/2026/001',
            'student_id' => $student->id,
            'academic_session_id' => $this->year->id,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $total - $paid,
            'status' => 'unpaid',
            'issued_at' => now(),
        ]);
    }
}

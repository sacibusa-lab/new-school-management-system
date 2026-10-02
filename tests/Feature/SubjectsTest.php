<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectsTest extends TestCase
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

    public function test_unauthenticated_visitors_are_redirected_to_sign_in(): void
    {
        $this->get(route('admin.students-results.academics.subjects'))
            ->assertRedirect(route('login'));
    }

    public function test_the_catalog_lists_subjects_and_filters_by_name_or_status(): void
    {
        Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
        Subject::create(['name' => 'Biology', 'code' => 'BIO', 'is_active' => false]);

        // Asserted as text rather than as markup. The add-a-subject form carries
        // "Mathematics" and "MTH" as example placeholders, so a page-wide search of
        // the HTML finds those words on every visit and says nothing about the list —
        // it would pass on an empty catalogue, and the "not listed" half could never
        // pass at all. Strip the tags and the claim is about what the school reads.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects', ['search' => 'math']))
            ->assertOk()
            ->assertSeeText('Mathematics')
            ->assertSeeText('MTH')
            ->assertDontSeeText('Biology');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects', ['status' => 'inactive']))
            ->assertOk()
            ->assertSeeText('Biology')
            ->assertDontSeeText('Mathematics');
    }

    public function test_active_subjects_are_listed_before_inactive_subjects(): void
    {
        Subject::create(['name' => 'Zoology', 'code' => 'ZOO', 'is_active' => true]);
        Subject::create(['name' => 'Agriculture', 'code' => 'AGR', 'is_active' => false]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects'))
            ->assertOk()
            ->getContent();

        $activePosition = strpos($html, 'Zoology');
        $inactivePosition = strpos($html, 'Agriculture');

        $this->assertNotFalse($activePosition);
        $this->assertNotFalse($inactivePosition);
        $this->assertLessThan($inactivePosition, $activePosition);
    }

    public function test_valid_subject_is_created_with_an_uppercase_code(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.subjects.store'), [
                'name' => '  Mathematics  ',
                'code' => ' mth ',
            ])
            ->assertRedirect(route('admin.students-results.academics.subjects'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('subjects', [
            'name' => 'Mathematics',
            'code' => 'MTH',
            'is_active' => true,
        ]);
    }

    public function test_subject_can_be_updated_and_deactivated_without_being_deleted(): void
    {
        $subject = Subject::create(['name' => 'Maths', 'code' => 'MTH', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.subjects.update', $subject), [
                'name' => 'Mathematics',
                'code' => 'MTH',
            ])
            ->assertRedirect(route('admin.students-results.academics.subjects'));

        $this->actingAs($this->admin)
            ->patch(route('admin.students-results.academics.subjects.status', $subject), ['is_active' => 0])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'name' => 'Mathematics',
            'code' => 'MTH',
            'is_active' => false,
        ]);
    }

    public function test_duplicate_subject_code_is_rejected_after_case_normalization(): void
    {
        Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.subjects.store'), [
                'name' => 'Further Mathematics',
                'code' => 'mth',
            ])
            ->assertSessionHasErrors(['code' => 'That subject code is already in use.']);

        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_a_user_without_academic_permission_cannot_view_or_change_subjects(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.students-results.academics.subjects'))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.students-results.academics.subjects.store'), [
                'name' => 'Mathematics',
                'code' => 'MTH',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('subjects', 0);
    }
}

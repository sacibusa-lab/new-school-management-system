<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The teaching staff: the register, and the form that fills it.
 *
 * Two things are worth pinning down. A teacher is an account with the Teacher role
 * — one kind of person, not a second table — so adding one here has to leave a
 * login that works and a role that means something. And the register is the list a
 * class is given a form teacher from, so what it says about the classes each one
 * holds has to be true of the classes themselves.
 *
 * It is not Staff & roles: that page manages logins and what each one may do. The
 * permission is separate for that reason, and is tested here.
 */
class TeachersTest extends TestCase
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
    /* The section itself */
    /* ------------------------------------------------------------------ */

    /** Teachers holds two pages, and says so rather than making one of them guess. */
    public function test_teachers_lists_the_two_pages_under_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers'))
            ->assertOk()
            ->assertSee('The people who teach')
            ->assertSee('Teachers List')
            ->assertSee('Add Teachers');
    }

    public function test_both_pages_open(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Teachers List');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.create'))
            ->assertOk()
            ->assertSee('Add a teacher')
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="avatar"', false)
            ->assertSee('name="password"', false);
    }

    /**
     * The two pages are one subject — who teaches here — so they answer to one
     * permission, and a role without it gets neither.
     */
    public function test_the_whole_section_is_closed_to_a_role_without_the_permission(): void
    {
        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        foreach (['teachers', 'teachers.list', 'teachers.create'] as $name) {
            $this->actingAs($bursar)
                ->get(route('admin.students-results.'.$name))
                ->assertForbidden();
        }

        $this->actingAs($bursar)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();

        // Nothing was written on the way past the gate.
        $this->assertDatabaseMissing('users', ['email' => 'chidera@example.com']);
    }

    /* ------------------------------------------------------------------ */
    /* Adding a teacher */
    /* ------------------------------------------------------------------ */

    public function test_a_teacher_is_added_with_the_role_and_a_password_to_change(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.students-results.teachers.list'))
            ->assertSessionHas('status');

        $teacher = $this->addedTeacher();

        $this->assertSame('Chidera Okafor', $teacher->name);
        $this->assertSame('080 1234 5678', $teacher->phone);
        $this->assertTrue($teacher->hasRole('Teacher'));
        $this->assertTrue($teacher->must_change_password);
        $this->assertTrue($teacher->is_active);
    }

    /** The role is what the page means, so it is not something the form can get wrong. */
    public function test_adding_a_teacher_does_not_hand_out_any_other_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'password' => 'password',
                'password_confirmation' => 'password',
                // A posted role is ignored: this form is only ever Teachers.
                'role' => 'Super Admin',
            ])
            ->assertSessionHas('status');

        $this->assertSame(['Teacher'], $this->addedTeacher()->getRoleNames()->all());
    }

    /** An account that exists is changed on Staff & roles, and the message says so. */
    public function test_a_teacher_cannot_be_added_on_an_email_that_is_already_used(): void
    {
        User::factory()->create(['email' => 'chidera@example.com']);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors([
                'email' => 'That email already has an account. Staff & roles is where an account that exists is changed.',
            ]);

        // One account with that address, and it is the one that was already there.
        $this->assertSame(1, User::query()->where('email', 'chidera@example.com')->count());
        $this->assertFalse(User::query()->where('email', 'chidera@example.com')->sole()->hasRole('Teacher'));
    }

    public function test_the_password_has_to_be_confirmed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'password' => 'password',
                'password_confirmation' => 'something else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'chidera@example.com']);
    }

    /**
     * The school texts teachers, so a teacher nobody can text is one the office
     * cannot reach — and the message says why rather than just refusing.
     */
    public function test_a_teacher_cannot_be_added_without_a_phone_number(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors([
                'phone' => 'A phone number is needed: the school reaches teachers by text message.',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'chidera@example.com']);
    }

    /* ------------------------------------------------------------------ */
    /* The profile image */
    /* ------------------------------------------------------------------ */

    public function test_a_teacher_can_be_added_with_a_profile_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'avatar' => UploadedFile::fake()->image('chidera.jpg'),
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHas('status');

        $teacher = $this->addedTeacher();

        $this->assertNotNull($teacher->avatar_path);
        Storage::disk('public')->assertExists($teacher->avatar_path);

        // And the register shows it, rather than holding a photograph nobody sees.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('storage/'.$teacher->avatar_path, false);
    }

    /**
     * Not required, and the register falls back to their initials rather than to a
     * broken image: a teacher is taken on before their photograph is to hand.
     */
    public function test_a_teacher_can_be_added_without_a_profile_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHas('status');

        $this->assertNull($this->addedTeacher()->avatar_path);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            // No picture, and no broken one either: their initials stand in for it.
            ->assertDontSee('storage/photos/teachers', false);
    }

    public function test_a_profile_image_has_to_be_an_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'avatar' => UploadedFile::fake()->create('curriculum-vitae.pdf', 100),
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertDatabaseMissing('users', ['email' => 'chidera@example.com']);
    }

    public function test_a_profile_image_bigger_than_two_megabytes_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3000),
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertDatabaseMissing('users', ['email' => 'chidera@example.com']);
    }

    /* ------------------------------------------------------------------ */
    /* The register */
    /* ------------------------------------------------------------------ */

    public function test_the_register_lists_the_teachers_and_the_classes_they_hold(): void
    {
        $teacher = $this->teacher('Chidera Okafor');
        $class = $this->class('JSS1A');
        $class->update(['form_teacher_id' => $teacher->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertSee($teacher->email)
            ->assertSee('JSS1A')
            ->assertSee('Active');
    }

    /** Staff who do not teach are not teachers: the register is people, not logins. */
    public function test_the_register_leaves_out_the_staff_who_do_not_teach(): void
    {
        $this->teacher('Chidera Okafor');

        $bursar = User::factory()->create(['name' => 'Ngozi the bursar']);
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertDontSee('Ngozi the bursar');
    }

    public function test_a_teacher_who_holds_no_class_says_so_rather_than_looking_empty(): void
    {
        $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Not a class teacher yet.');
    }

    public function test_the_register_says_where_to_go_when_there_are_no_teachers_yet(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('No teachers yet')
            ->assertSee('Add the first one');
    }

    public function test_a_deactivated_teacher_is_marked_as_such_rather_than_dropped(): void
    {
        $teacher = $this->teacher('Chidera Okafor');
        $teacher->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertSee('Inactive');
    }

    /** Every row on the register can be opened, which is what the pencil is for. */
    public function test_the_register_offers_a_pencil_on_each_teacher(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee('Action')
            ->assertSee(route('admin.students-results.teachers.edit', $teacher), false);
    }

    /* ------------------------------------------------------------------ */
    /* Editing a teacher */
    /* ------------------------------------------------------------------ */

    public function test_the_edit_page_opens_with_the_teacher_in_it(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.edit', $teacher))
            ->assertOk()
            ->assertSee('Edit Chidera Okafor')
            ->assertSee('value="Chidera Okafor"', false)
            ->assertSee('value="'.$teacher->email.'"', false)
            ->assertSee('value="'.$teacher->phone.'"', false)
            ->assertSee('name="avatar"', false)
            // The password is not here, and neither is a field that would set one.
            ->assertDontSee('name="password"', false);
    }

    /** A photograph cannot be taken off a teacher who has not got one. */
    public function test_the_remove_photograph_box_is_only_there_when_there_is_a_photograph(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.edit', $teacher))
            ->assertOk()
            ->assertDontSee('name="remove_avatar"', false);

        $teacher->update(['avatar_path' => 'photos/teachers/old.jpg']);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.edit', $teacher))
            ->assertOk()
            ->assertSee('name="remove_avatar"', false)
            ->assertSee('storage/photos/teachers/old.jpg', false);
    }

    public function test_a_teacher_is_edited_and_the_register_shows_the_change(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor-Eze',
                'email' => 'chidera.eze@example.com',
                'phone' => '080 9999 1111',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.students-results.teachers.list'))
            ->assertSessionHas('status', 'Chidera Okafor-Eze saved.');

        $teacher->refresh();

        $this->assertSame('Chidera Okafor-Eze', $teacher->name);
        $this->assertSame('chidera.eze@example.com', $teacher->email);
        $this->assertSame('080 9999 1111', $teacher->phone);
        $this->assertTrue($teacher->is_active);
        // Still a teacher, and still only a teacher.
        $this->assertSame(['Teacher'], $teacher->getRoleNames()->all());
    }

    /**
     * A teacher who has left is deactivated rather than deleted: the classes they
     * taught and the marks they entered are still theirs.
     */
    public function test_a_teacher_is_deactivated_rather_than_deleted(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                // No is_active: an unticked box means they no longer sign in.
            ])
            ->assertSessionHas('status');

        $this->assertFalse($teacher->refresh()->is_active);
    }

    /** Keeping their own email is not a clash with themselves. */
    public function test_a_teacher_keeps_their_own_email_and_phone(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                'is_active' => '1',
            ])
            ->assertSessionHas('status');

        $this->assertSame($teacher->email, $teacher->refresh()->email);
    }

    public function test_a_teacher_cannot_take_an_email_that_belongs_to_another_account(): void
    {
        $teacher = $this->teacher('Chidera Okafor');
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => 'taken@example.com',
                'phone' => $teacher->phone,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors(['email' => 'That email belongs to another account.']);

        $this->assertNotSame('taken@example.com', $teacher->refresh()->email);
    }

    /** The school texts teachers, so the number cannot be emptied out afterwards. */
    public function test_a_teacher_cannot_be_left_without_a_phone_number(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => $teacher->email,
                'phone' => '',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors([
                'phone' => 'A phone number is needed: the school reaches teachers by text message.',
            ]);

        $this->assertSame($teacher->phone, $teacher->refresh()->phone);
    }

    /**
     * A new photograph replaces the old one and the old one is deleted: a replaced
     * photograph is usually one somebody objected to, and leaving it on the disk
     * leaves it readable by whoever still has the link.
     */
    public function test_a_new_photograph_replaces_the_old_one_and_the_old_one_is_deleted(): void
    {
        Storage::fake('public');

        $teacher = $this->teacher('Chidera Okafor');
        $old = UploadedFile::fake()->image('old.jpg')->store('photos/teachers', 'public');
        $teacher->update(['avatar_path' => $old]);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                'avatar' => UploadedFile::fake()->image('new.jpg'),
                'is_active' => '1',
            ])
            ->assertSessionHas('status');

        $now = $teacher->refresh()->avatar_path;

        $this->assertNotNull($now);
        $this->assertNotSame($old, $now);
        Storage::disk('public')->assertExists($now);
        Storage::disk('public')->assertMissing($old);
    }

    /**
     * Keeping the photograph is what happens by default: the box is not ticked, so
     * saving a name change must not quietly take the picture with it.
     */
    public function test_changing_a_name_keeps_the_photograph_that_was_there(): void
    {
        Storage::fake('public');

        $teacher = $this->teacher('Chidera Okafor');
        $path = UploadedFile::fake()->image('chidera.jpg')->store('photos/teachers', 'public');
        $teacher->update(['avatar_path' => $path]);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor-Eze',
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                'is_active' => '1',
            ])
            ->assertSessionHas('status');

        $this->assertSame($path, $teacher->refresh()->avatar_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_photograph_can_be_taken_off_a_teacher(): void
    {
        Storage::fake('public');

        $teacher = $this->teacher('Chidera Okafor');
        $path = UploadedFile::fake()->image('chidera.jpg')->store('photos/teachers', 'public');
        $teacher->update(['avatar_path' => $path]);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Chidera Okafor',
                'email' => $teacher->email,
                'phone' => $teacher->phone,
                'remove_avatar' => '1',
                'is_active' => '1',
            ])
            ->assertSessionHas('status');

        $this->assertNull($teacher->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * This page is the teachers register, not Staff & roles: an account that does
     * not teach is not one it edits.
     */
    public function test_the_edit_page_does_not_open_for_an_account_that_is_not_a_teacher(): void
    {
        $bursar = User::factory()->create(['name' => 'Ngozi the bursar']);
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.edit', $bursar))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.teachers.update', $bursar), [
                'name' => 'Somebody else',
                'email' => $bursar->email,
                'phone' => '080 1234 5678',
            ])
            ->assertNotFound();

        $this->assertSame('Ngozi the bursar', $bursar->refresh()->name);
    }

    public function test_editing_a_teacher_is_closed_to_a_role_without_the_permission(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($bursar)
            ->get(route('admin.students-results.teachers.edit', $teacher))
            ->assertForbidden();

        $this->actingAs($bursar)
            ->put(route('admin.students-results.teachers.update', $teacher), [
                'name' => 'Somebody else',
                'email' => $teacher->email,
                'phone' => '080 1234 5678',
            ])
            ->assertForbidden();

        $this->assertSame('Chidera Okafor', $teacher->refresh()->name);
    }

    /* ------------------------------------------------------------------ */

    private function teacher(string $name): User
    {
        $teacher = User::factory()->create([
            'name' => $name,
            'phone' => '080 1234 5678',
        ]);
        $teacher->assignRole('Teacher');

        return $teacher;
    }

    /** The account the Add Teachers form just made. */
    private function addedTeacher(): User
    {
        return User::query()->where('email', 'chidera@example.com')->sole();
    }

    private function class(string $name): SchoolClass
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $section = Section::create(['name' => 'A', 'order' => 1]);

        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }
}

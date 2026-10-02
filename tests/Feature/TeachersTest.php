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
use Illuminate\Support\Facades\Hash;
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
 *
 * Removal is the one destructive thing the page does, so it is tested for what it
 * takes with it: the photograph, and the classes that were somebody's.
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
        $this->assertSame('08012345678', $teacher->phone);
        $this->assertTrue($teacher->hasRole('Teacher'));
        $this->assertTrue($teacher->is_active);
    }

    /**
     * The password an office sets is not a temporary one.
     *
     * It used to be: the account was held on the profile screen until it chose its
     * own, and a banner there said so. The gate and the banner are both gone, so what
     * a teacher is given here stands for as long as they leave it alone. Worth
     * pinning down, because a redirect back to the profile is exactly the sort of
     * thing that gets reintroduced as a safety measure by somebody who cannot tell
     * it was taken out on purpose.
     */
    public function test_a_teacher_is_not_made_to_replace_the_password_they_were_given(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.store'), [
                'name' => 'Chidera Okafor',
                'email' => 'chidera@example.com',
                'phone' => '080 1234 5678',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $teacher = $this->addedTeacher();

        // The password set for them is the one the account answers to.
        $this->assertTrue(Hash::check('password', $teacher->password));

        // And nothing sends them off to replace it before they can get anywhere.
        $this->assertFalse(
            $this->actingAs($teacher)->get(route('admin.dashboard'))->isRedirect(route('profile.edit')),
            'Nothing should hold a teacher on the profile screen.'
        );

        // Nor does the profile page still carry the banner that told them to.
        $this->actingAs($teacher)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Change your password to carry on');
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
     * The phone number is the sign-in detail now, so a teacher without one is a
     * teacher who cannot get in — and the message says why rather than just
     * refusing.
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
                'phone' => 'A phone number is needed: it is the number the teacher signs in with.',
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

    /**
     * And a bin, plus a box to tick: one teacher at a time, or the six who left at
     * the end of a session. The bin submits the form that waits outside the table,
     * because a form cannot sit inside the form the boxes belong to.
     */
    public function test_the_register_offers_a_bin_each_and_a_box_to_tick_them(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.list'))
            ->assertOk()
            ->assertSee(route('admin.students-results.teachers.destroy-selected'), false)
            ->assertSee(route('admin.students-results.teachers.destroy', $teacher), false)
            ->assertSee('form="remove-teacher-'.$teacher->id.'"', false)
            ->assertSee('name="teachers[]"', false)
            // The count rides on the button, so nobody presses it wondering what it will do.
            ->assertSee('x-text="count"', false);
    }

    /* ------------------------------------------------------------------ */
    /* Removing a teacher */
    /* ------------------------------------------------------------------ */

    public function test_a_teacher_is_removed_from_the_register(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy', $teacher))
            ->assertRedirect(route('admin.students-results.teachers.list'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'removed from the teaching staff'));

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
    }

    /**
     * The photograph goes too. A face left on the disk is a face anybody with the link
     * can still open, and the account it belonged to is gone.
     */
    public function test_removing_a_teacher_takes_their_photograph_with_them(): void
    {
        Storage::fake('public');

        $teacher = $this->teacher('Chidera Okafor');
        $teacher->update(['avatar_path' => 'photos/teachers/chidera.jpg']);
        Storage::disk('public')->put('photos/teachers/chidera.jpg', 'a photograph');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy', $teacher))
            ->assertSessionHas('status');

        Storage::disk('public')->assertMissing('photos/teachers/chidera.jpg');
    }

    /**
     * A class is left with nobody, and the office is told which one before they
     * confirm and again in the flash — a blank column weeks later is not a message.
     */
    public function test_the_classes_a_teacher_held_are_freed_and_named(): void
    {
        $teacher = $this->teacher('Chidera Okafor');
        $class = $this->class('JSS1A');
        $class->update(['form_teacher_id' => $teacher->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy', $teacher))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'JSS1A'));

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
        $this->assertNull($class->refresh()->form_teacher_id);
    }

    public function test_several_teachers_are_removed_at_once(): void
    {
        $leaving = $this->teacher('Chidera Okafor');
        $alsoLeaving = $this->teacher('Bola Adeyemi');
        $staying = $this->teacher('Zainab Yusuf');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy-selected'), [
                'teachers' => [$leaving->id, $alsoLeaving->id],
            ])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, '2 teachers removed'));

        $this->assertDatabaseMissing('users', ['id' => $leaving->id]);
        $this->assertDatabaseMissing('users', ['id' => $alsoLeaving->id]);
        $this->assertDatabaseHas('users', ['id' => $staying->id]);
    }

    /** Ticking nobody is not a request to remove everybody. */
    public function test_removing_nobody_is_refused(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy-selected'), ['teachers' => []])
            ->assertSessionHasErrors('teachers');

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
    }

    /** The register removes teachers; an id typed into the form cannot make it a people-deleter. */
    public function test_an_account_that_is_not_a_teacher_cannot_be_removed_from_here(): void
    {
        $bursar = User::factory()->create(['name' => 'Ngozi the bursar']);
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy', $bursar))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy-selected'), [
                'teachers' => [$bursar->id],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $bursar->id]);
    }

    /**
     * Deleting the account you are signed in as would take the session out from under
     * the request. The rest of a bulk removal still goes through.
     */
    public function test_the_office_cannot_remove_the_account_they_are_signed_in_with(): void
    {
        $this->admin->assignRole('Teacher');
        $other = $this->teacher('Chidera Okafor');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy', $this->admin))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.teachers.destroy-selected'), [
                'teachers' => [$this->admin->id, $other->id],
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_removing_a_teacher_is_closed_to_a_role_without_the_permission(): void
    {
        $teacher = $this->teacher('Chidera Okafor');

        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($bursar)
            ->delete(route('admin.students-results.teachers.destroy', $teacher))
            ->assertForbidden();

        $this->actingAs($bursar)
            ->delete(route('admin.students-results.teachers.destroy-selected'), [
                'teachers' => [$teacher->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
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
        $this->assertSame('08099991111', $teacher->phone);
        $this->assertTrue($teacher->is_active);
        // Still a teacher, and still only a teacher.
        $this->assertSame(['Teacher'], $teacher->getRoleNames()->all());
    }

    /**
     * The switch on the edit form takes the login away without touching the record: the
     * reversible way to retire somebody. Removal is the other way, and is tested above.
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

    /** The number is the sign-in detail, so it cannot be emptied out afterwards. */
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
                'phone' => 'A phone number is needed: it is the number the teacher signs in with.',
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
            // One number, one account: the column carries a unique index now, so a
            // test that makes three teachers needs three different numbers.
            'phone' => fake()->unique()->numerify('080########'),
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

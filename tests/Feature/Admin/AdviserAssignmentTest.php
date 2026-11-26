<?php

namespace Tests\Feature\Admin;

use App\Models\StudentRegistrationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdviserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_supports_actual_sections_and_updates_only_matching_students(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'Active']);
        $instructor = User::factory()->create(['role' => 'instructor', 'status' => 'Active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'Active', 'year' => '4th year', 'section' => '4C', 'adviser' => null]);
        $sameGroup = User::factory()->create(['role' => 'student', 'year' => ' 4TH YEAR ', 'section' => ' 4c ', 'adviser' => null]);
        $otherSection = User::factory()->create(['role' => 'student', 'year' => '4th year', 'section' => '4D', 'adviser' => 'Existing adviser']);
        $otherYear = User::factory()->create(['role' => 'student', 'year' => '3rd year', 'section' => '4C', 'adviser' => null]);
        $inactive = User::factory()->create(['role' => 'student', 'status' => 'Inactive', 'year' => '4th year', 'section' => '4C', 'adviser' => null]);

        $this->actingAs($admin)->put(route('admin.users.assign-adviser'), [
            'year' => '4th year', 'section' => '4C', 'instructor_id' => $instructor->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Adviser assigned to 2 students.');
        $this->assertSame($instructor->name, $student->fresh()->adviser);
        $this->assertSame($instructor->name, $sameGroup->fresh()->adviser);
        $this->assertSame('Existing adviser', $otherSection->fresh()->adviser);
        $this->assertNull($otherYear->fresh()->adviser);
        $this->assertNull($inactive->fresh()->adviser);
        $this->assertNull($instructor->fresh()->adviser);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $admin->id, 'subject_id' => $student->id, 'action' => 'Profile updated']);

        $this->actingAs($student->fresh())->get('/student/profile')->assertInertia(fn (Assert $page) => $page->where('student.adviser', $instructor->name));
    }

    public function test_no_matching_students_returns_error_instead_of_false_success(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'Active']);
        $instructor = User::factory()->create(['role' => 'instructor', 'status' => 'Active']);
        $this->actingAs($admin)->put(route('admin.users.assign-adviser'), [
            'year' => '4th year', 'section' => 'Unknown', 'instructor_id' => $instructor->id,
        ])->assertSessionHasErrors('section')->assertSessionMissing('success');
    }

    public function test_adviser_must_be_an_active_instructor_selected_by_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'Active']);
        $student = User::factory()->create(['role' => 'student', 'year' => '4th year', 'section' => '4C', 'adviser' => null]);
        $inactive = User::factory()->create(['role' => 'instructor', 'status' => 'Inactive']);
        foreach ([$student->id, $inactive->id, 999999] as $id) {
            $this->actingAs($admin)->put(route('admin.users.assign-adviser'), [
                'year' => '4th year', 'section' => '4C', 'instructor_id' => $id,
            ])->assertSessionHasErrors('instructor_id');
        }
        $this->assertNull($student->fresh()->adviser);
    }

    public function test_other_roles_cannot_assign_advisers(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'status' => 'Active']);
        foreach (['student', 'guard', 'instructor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'Active']);
            $this->actingAs($user)->putJson(route('admin.users.assign-adviser'), [
                'year' => '4th year', 'section' => '4C', 'instructor_id' => $instructor->id,
            ])->assertForbidden();
        }
    }

    public function test_new_signup_is_listed_and_can_be_assigned_after_academic_registration(): void
    {
        $this->post(route('register.store'), [
            'name' => 'New Student', 'email' => 'new.student@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();
        $student = User::where('email', 'new.student@example.com')->firstOrFail();
        $student->forceFill(['created_at' => now()->addMinute()])->saveQuietly();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'Active']);
        $instructor = User::factory()->create(['role' => 'instructor', 'status' => 'Active']);
        $link = StudentRegistrationLink::create([
            'instructor_id' => $instructor->id, 'token' => str_repeat('c', 64), 'is_active' => true,
        ]);

        $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users', 3)
                ->where('users.0.id', $student->id)
                ->where('users.0.role', 'student'));
        $this->put('/admin/users/assign-adviser', [
            'year' => '4th Year', 'section' => '4C', 'instructor_id' => $instructor->id,
        ])->assertSessionHasErrors('section');

        $this->actingAs($student)->post('/student/registration', [
            'token' => $link->token, 'full_name' => 'New Student', 'student_number' => '2026-0042',
            'email' => 'new.student@example.com', 'course' => 'BSIT', 'year_level' => '4th Year', 'section' => '4C',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->put('/admin/users/assign-adviser', [
            'year' => '4th Year', 'section' => '4C', 'instructor_id' => $instructor->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($instructor->id, $student->fresh()->adviser_id);
        $this->assertSame('4C', $student->fresh()->section);
    }
}

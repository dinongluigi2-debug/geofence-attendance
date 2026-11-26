<?php

namespace Tests\Feature\Instructor;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class QrAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(Date::parse('2026-11-23 09:05:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $instructor = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create([
            'role' => 'student',
            'student_id' => 'STU-1001',
            'year' => '2nd Year',
            'section' => 'BSCS-2A',
        ]);
        $schedule = Schedule::create([
            'user_id' => $instructor->id,
            'subject' => 'QR Attendance Test',
            'instructor' => $instructor->name,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'students' => 30,
            'location' => 'Room 1',
            'coordinates' => '14.5995, 120.9842',
            'radius' => '50',
            'days' => ['Monday'],
            'status' => 'Scheduled',
            'recording' => true,
        ]);
        $schedule->registeredStudents()->attach($student, [
            'student_number' => 'STU-1001',
            'year_level' => '2nd Year',
            'section' => 'BSCS-2A',
            'status' => 'active',
            'registered_at' => now(),
        ]);

        $token = str_repeat('a', 64);
        $student->forceFill([
            'student_qr_token_hash' => hash('sha256', $token),
            'student_qr_token_encrypted' => Crypt::encryptString($token),
        ])->save();

        return [$instructor, $student, $schedule, $token];
    }

    public function test_only_the_class_instructor_can_see_enrolled_student_qr_details(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();

        $this->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $token])
            ->assertUnauthorized();

        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $this->actingAs($otherInstructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $token])
            ->assertForbidden();

        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('student.name', $student->name)
            ->assertJsonPath('student.studentNumber', 'STU-1001')
            ->assertJsonPath('student.yearLevel', '2nd Year')
            ->assertJsonPath('student.section', 'BSCS-2A')
            ->assertJsonPath('canRecord', true);
    }

    public function test_manual_qr_attendance_requires_presence_confirmation_and_prevents_duplicates(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();
        $url = "/instructor/classes/{$schedule->id}/attendance/qr/record";

        $this->actingAs($instructor)->postJson($url, [
            'token' => $token,
            'confirm_presence' => false,
            'reason' => 'GPS permission denied',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirm_presence');

        $this->actingAs($instructor)->postJson($url, [
            'token' => $token,
            'confirm_presence' => true,
            'reason' => 'GPS permission denied',
        ])->assertOk()->assertJsonPath('status', 'present');

        $record = Attendance::query()->firstOrFail();
        $this->assertSame('qr_manual', $record->attendance_method);
        $this->assertSame($instructor->id, $record->recorded_by);
        $this->assertSame('GPS permission denied', $record->manual_reason);
        $this->assertNull($record->latitude);
        $this->assertNull($record->longitude);

        $this->actingAs($instructor)->postJson($url, [
            'token' => $token,
            'confirm_presence' => true,
            'reason' => 'GPS permission denied',
        ])->assertUnprocessable()->assertJsonValidationErrors('attendance');
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_manual_qr_attendance_cannot_be_used_for_an_inactive_enrollment(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();
        $schedule->registeredStudents()->updateExistingPivot($student->id, ['status' => 'inactive']);

        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/record", [
                'token' => $token,
                'confirm_presence' => true,
                'reason' => 'GPS unavailable',
            ])->assertForbidden();

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_invalid_revoked_or_inactive_student_codes_are_not_looked_up(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();
        $lookup = "/instructor/classes/{$schedule->id}/attendance/qr/lookup";

        $this->actingAs($instructor)->postJson($lookup, ['token' => str_repeat('b', 64)])
            ->assertNotFound();

        $student->forceFill(['student_qr_token_hash' => null, 'student_qr_token_encrypted' => null, 'student_qr_revoked_at' => now()])->save();
        $this->actingAs($instructor)->postJson($lookup, ['token' => $token])->assertNotFound();

        $student->forceFill([
            'student_qr_token_hash' => hash('sha256', $token),
            'student_qr_token_encrypted' => Crypt::encryptString($token),
            'student_qr_revoked_at' => null,
            'status' => 'Inactive',
        ])->save();
        $this->actingAs($instructor)->postJson($lookup, ['token' => $token])->assertNotFound();
    }

    public function test_qr_lookup_rejects_wrong_enrollment_and_unavailable_sessions(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();
        $otherClass = Schedule::create([
            'user_id' => $instructor->id,
            'subject' => 'Different class',
            'instructor' => $instructor->name,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'students' => 30,
            'location' => 'Room 2',
            'coordinates' => '14.5995, 120.9842',
            'radius' => 50,
            'days' => ['Monday'],
            'status' => 'Scheduled',
            'recording' => true,
        ]);

        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$otherClass->id}/attendance/qr/lookup", ['token' => $token])
            ->assertForbidden();

        $schedule->forceFill(['recording' => false])->save();
        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('canRecord', false);
        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/record", [
                'token' => $token,
                'confirm_presence' => true,
                'reason' => 'GPS unavailable',
            ])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_only_instructors_can_use_lookup_and_unenrolled_students_cannot_be_disclosed(): void
    {
        [$instructor, $student, $schedule, $token] = $this->fixture();
        $url = "/instructor/classes/{$schedule->id}/attendance/qr/lookup";
        $this->actingAs($student)->postJson($url, ['token' => $token])->assertForbidden();

        $schedule->registeredStudents()->detach($student->id);
        $this->actingAs($instructor)->postJson($url, ['token' => $token])->assertForbidden();
    }

    public function test_instructor_can_revoke_and_regenerate_a_student_code(): void
    {
        [$instructor, $student, $schedule, $oldToken] = $this->fixture();
        $base = "/instructor/classes/{$schedule->id}/students/{$student->id}/qr";

        $this->actingAs($instructor)->deleteJson($base)->assertOk();
        $student->refresh();
        $this->assertNotNull($student->student_qr_revoked_at);
        $this->actingAs($instructor)->postJson("{$base}/regenerate")->assertOk();

        $newToken = Crypt::decryptString($student->fresh()->student_qr_token_encrypted);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(hash('sha256', $newToken), $student->fresh()->student_qr_token_hash);

        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $oldToken])
            ->assertNotFound();
        $this->actingAs($instructor)
            ->postJson("/instructor/classes/{$schedule->id}/attendance/qr/lookup", ['token' => $newToken])
            ->assertOk();
    }
}

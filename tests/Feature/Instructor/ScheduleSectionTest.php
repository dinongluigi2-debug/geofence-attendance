<?php

namespace Tests\Feature\Instructor;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_instructor_schedule_requires_and_persists_section(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'status' => 'Active']);
        User::factory()->create(['role' => 'student', 'status' => 'Active', 'section' => '4C']);

        $this->actingAs($instructor)->get('/schedules')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('instructor/Schedules')
                ->where('sections.0', '4C'));

        $payload = [
            'subject' => 'CAP 413', 'section' => '4C', 'start_time' => '09:00', 'end_time' => '10:00',
            'students' => 30, 'location' => 'Room 1', 'coordinates' => '14.5995, 120.9842',
            'radius' => 50, 'days' => ['Friday'], 'status' => 'Scheduled',
        ];

        $this->actingAs($instructor)->post('/schedules', $payload)->assertSessionHasNoErrors();
        $schedule = Schedule::query()->where('subject', 'CAP 413')->firstOrFail();
        $this->assertSame('4C', $schedule->section);
        $this->assertSame($instructor->id, $schedule->user_id);

        unset($payload['section']);
        $this->actingAs($instructor)->post('/schedules', $payload)->assertSessionHasErrors('section');
        $this->assertDatabaseCount('schedules', 1);
    }
}

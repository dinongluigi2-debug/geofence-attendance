<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AttendanceAutoService;
use App\Services\GuardStudentStatusService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuardStudentTrackingController extends Controller
{
    public function __construct(
        protected GuardStudentStatusService $statusService
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $now = now('Asia/Manila');
        $schedules = $this->statusService->todaySchedules($now);
        $students = collect();

        if ($search !== '') {
            $matchingIds = User::query()
                ->where('role', 'student')
                ->where('status', 'Active')
                ->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('student_id', 'like', '%'.$search.'%');
                })
                ->orderBy('name')
                ->limit(20)
                ->pluck('id');

            $students = User::query()
                ->whereKey($matchingIds)
                ->orderBy('name')
                ->get()
                ->map(function (User $student) use ($schedules, $now): array {
                    $schedule = $this->statusService->getCurrentSchedule($student, $schedules, $now);

                    return [
                        'id' => $student->id,
                        'name' => $student->name,
                        'studentNumber' => $student->student_id ?: 'Not assigned',
                        'course' => $student->course ?: 'N/A',
                        'year' => $student->year ?: 'N/A',
                        'section' => $student->section ?: 'N/A',
                        'status' => $this->statusService->resolveStudentStatus($student, $schedules, $now),
                        'currentSubject' => $schedule?->subject,
                        'currentInstructor' => $schedule?->instructor,
                        'location' => $schedule?->location,
                        'startTime' => $schedule?->start_time,
                        'endTime' => $schedule?->end_time,
                    ];
                })
                ->values();

            foreach ($students as $student) {
                ActivityLog::create([
                    'actor_id' => $request->user()->id,
                    'actor_name' => $request->user()->name,
                    'role' => 'guard',
                    'action' => 'Guard student search result disclosed',
                    'subject_type' => 'User',
                    'subject_id' => $student['id'],
                ]);
            }
        }

        $summary = [
            'inClass' => 0,
            'late' => 0,
            'absent' => 0,
            'vacant' => 0,
            'done' => 0,
        ];

        foreach ($students as $student) {
            $status = strtolower((string) $student['status']);

            if ($status === 'in class') {
                $summary['inClass']++;

                continue;
            }

            if ($status === 'late') {
                $summary['late']++;

                continue;
            }

            if ($status === 'absent') {
                $summary['absent']++;

                continue;
            }

            if ($status === 'classes done') {
                $summary['done']++;

                continue;
            }

            $summary['vacant']++;
        }

        return Inertia::render('guard/StudentTracking', [
            'students' => $students,
            'summary' => $summary,
            'search' => $search,
            'schedules' => $schedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'subject' => $schedule->subject,
                'section' => $schedule->section ?: 'No section',
                'location' => $schedule->location ?: 'No location',
            ])->values()->all(),
        ]);
    }

    public function show(User $student, AttendanceAutoService $sessions): Response
    {
        abort_unless($student->role === 'student' && strtolower((string) $student->status) === 'active', 404);
        ActivityLog::create([
            'actor_id' => request()->user()->id,
            'actor_name' => request()->user()->name,
            'role' => 'guard',
            'action' => 'Guard student schedule and attendance status disclosed',
            'subject_type' => 'User',
            'subject_id' => $student->id,
        ]);
        $now = now('Asia/Manila');
        $schedules = $student->registeredSchedules()->orderBy('start_time')->get()
            ->filter(fn ($schedule): bool => blank($schedule->pivot->status) || strtolower((string) $schedule->pivot->status) === 'active')
            ->map(function ($schedule) use ($sessions, $now): array {
                $state = $schedule->status === 'Completed' ? 'Completed' : 'No upcoming session';
                $next = null;
                if ($schedule->status !== 'Completed') {
                    // Include yesterday for classes that continue past midnight.
                    for ($offset = -1; $offset <= 7; $offset++) {
                        $session = $sessions->session($schedule, $now->copy()->addDays($offset)->toDateString());
                        if (! $session || $session[1]->lt($now)) {
                            continue;
                        }
                        $state = $session[0]->lte($now) ? 'Ongoing' : 'Pending';
                        $next = ['start' => $session[0]->toIso8601String(), 'end' => $session[1]->toIso8601String()];
                        break;
                    }
                }

                return [
                    'id' => $schedule->id, 'subject' => $schedule->subject,
                    'instructor' => $schedule->instructor, 'location' => $schedule->location,
                    'section' => $schedule->section, 'days' => $schedule->days ?? [],
                    'start_time' => substr($schedule->start_time ?? '', 0, 5),
                    'end_time' => substr($schedule->end_time ?? '', 0, 5),
                    'state' => $state, 'next_session' => $next,
                    'attendance_status' => $schedule->attendances()
                        ->where('student_id', $student->id)
                        ->where(function ($query) use ($now): void {
                            $query->whereDate('attendance_date', $now->toDateString())
                                ->orWhere(function ($legacy) use ($now): void {
                                    $legacy->whereNull('attendance_date')->whereDate('created_at', $now->toDateString());
                                });
                        })
                        ->value('status') ?? 'Not recorded',
                ];
            });

        return Inertia::render('guard/StudentDetails', [
            'student' => $student->only(['id', 'name', 'student_id', 'course', 'year', 'section']),
            'schedules' => $schedules,
        ]);
    }
}

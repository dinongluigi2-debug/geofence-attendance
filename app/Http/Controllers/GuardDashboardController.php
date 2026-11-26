<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Services\GuardStudentStatusService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class GuardDashboardController extends Controller
{
    public function __construct(
        protected GuardStudentStatusService $statusService
    ) {}

    public function index(): Response
    {
        $now = now('Asia/Manila');
        $schedules = $this->statusService->todaySchedules($now);

        $classes = $schedules
            ->map(function (Schedule $schedule) use ($now): array {
                $students = $schedule->registeredStudents->sortBy('name');

                return [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject,
                    'section' => $schedule->section ?: 'No section',
                    'instructor' => $schedule->instructor ?: 'N/A',
                    'startTime' => $this->formatTime($schedule->start_time),
                    'endTime' => $this->formatTime($schedule->end_time),
                    'location' => $schedule->location ?: 'No location',
                    'status' => $this->statusService->getScheduleStatus(
                        $schedule,
                        $now
                    ),
                    'studentCount' => $students->count(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('guard/Dashboard', [
            'classes' => $classes,
        ]);
    }

    public function show(Schedule $schedule): Response
    {
        $now = now('Asia/Manila');
        $today = $now->toDateString();
        abort_unless($this->statusService->todaySchedules($now)->contains('id', $schedule->id), 404);

        $students = $schedule->registeredStudents
            ->filter(function ($student) use ($schedule): bool {
                if ($student->role !== 'student' || $student->status !== 'Active') {
                    return false;
                }

                return blank($schedule->section) ||
                    strtolower(trim((string) $student->section)) === strtolower(trim((string) $schedule->section));
            });
        $studentIds = $students->pluck('id');

        $attendanceRecords = Attendance::query()
            ->where('schedule_id', $schedule->id)
            ->whereIn('student_id', $studentIds)
            ->whereDate('attendance_date', $today)
            ->get()
            ->keyBy('student_id');
        $present = $attendanceRecords->where('status', 'Present')->count();
        $late = $attendanceRecords->where('status', 'Late')->count();
        $absent = max(0, $students->count() - $present - $late);

        return Inertia::render('guard/ClassDetails', [
            'classDetails' => [
                'id' => $schedule->id,
                'subject' => $schedule->subject,
                'section' => $schedule->section ?: 'No section',
                'instructor' => $schedule->instructor ?: 'N/A',
                'startTime' => $this->formatTime($schedule->start_time),
                'endTime' => $this->formatTime($schedule->end_time),
                'location' => $schedule->location ?: 'No location',
                'status' => $this->statusService->getScheduleStatus(
                    $schedule,
                    $now
                ),
            ],
            'statistics' => [
                'total' => $students->count(),
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
            ],
        ]);
    }

    protected function formatTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        try {
            return Carbon::parse($time)->format('h:i A');
        } catch (\Throwable) {
            return (string) $time;
        }
    }
}

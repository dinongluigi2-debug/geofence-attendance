<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceAutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class InstructorQrAttendanceController extends Controller
{
    public function lookup(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($request, $schedule);
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $student = User::query()->where('student_qr_token_hash', hash('sha256', $validated['token']))->where('role', 'student')->first();

        abort_unless($student && strtolower((string) $student->status) === 'active'
            && $student->student_qr_revoked_at === null
            && $student->student_qr_expires_at?->isFuture(), 404, 'This student QR code is invalid, expired, or has been revoked.');

        $enrollment = $schedule->registeredStudents()
            ->where('users.id', $student->id)
            ->where('users.role', 'student')
            ->where(function ($query): void {
                $query->whereNull('schedule_student.status')->orWhereRaw('LOWER(schedule_student.status) = ?', ['active']);
            })
            ->first();

        abort_unless($enrollment, 403, 'This student is not actively enrolled in the selected class.');

        $now = now(config('app.timezone'));
        $session = $this->activeSession($schedule, $now);
        $history = Attendance::query()
            ->where('schedule_id', $schedule->id)
            ->where('student_id', $student->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('time_in')
            ->limit(12)
            ->get()
            ->map(fn (Attendance $record): array => [
                'date' => $record->attendance_date?->toDateString() ?? $record->created_at?->toDateString(),
                'status' => ucfirst(strtolower((string) $record->status)),
                'time' => $record->time_in?->timezone(config('app.timezone'))->format('h:i A'),
                'method' => $record->attendance_method ?? (($record->latitude !== null || $record->longitude !== null)
                    ? 'gps'
                    : (in_array(strtolower((string) $record->status), ['present', 'late'], true) ? 'manual' : 'system')),
                'reason' => $record->manual_reason,
            ])->values();

        $todayRecord = $session ? Attendance::query()
            ->where('schedule_id', $schedule->id)
            ->where('student_id', $student->id)
            ->where(function ($query) use ($session): void {
                $query->whereDate('attendance_date', $session[0])
                    ->orWhere(function ($legacy) use ($session): void {
                        $legacy->whereNull('attendance_date')->whereDate('created_at', $session[0]);
                    });
            })
            ->first() : null;
        $attendanceExists = (bool) $todayRecord;

        return response()->json([
            'student' => [
                'name' => $student->name,
                'studentNumber' => $enrollment->pivot->student_number ?: $student->student_id ?: 'Not assigned',
                'yearLevel' => $enrollment->pivot->year_level ?: $student->year ?: 'Not assigned',
                'section' => $enrollment->pivot->section ?: $student->section ?: 'Not assigned',
            ],
            'history' => $history,
            'canRecord' => (bool) $session && ! $attendanceExists && $schedule->recording
                && in_array($schedule->status, ['Scheduled', 'Ongoing'], true),
            'recordedToday' => $attendanceExists,
            'sessionDate' => $session[0] ?? null,
            'message' => $attendanceExists ? 'An attendance record already exists for this session.'
                : (! $session ? 'Attendance can only be recorded during this class session.' : null),
        ]);
    }

    public function record(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeSchedule($request, $schedule);
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'confirm_presence' => ['accepted'],
            'reason' => ['required', 'string', 'in:GPS unavailable,GPS permission denied,GPS location inaccurate,Other'],
            'reason_details' => ['required_if:reason,Other', 'nullable', 'string', 'min:5', 'max:500'],
        ]);

        $student = User::query()->where('student_qr_token_hash', hash('sha256', $validated['token']))->where('role', 'student')->first();
        abort_unless($student && strtolower((string) $student->status) === 'active'
            && $student->student_qr_revoked_at === null
            && $student->student_qr_expires_at?->isFuture(), 404, 'This student QR code is invalid, expired, or has been revoked.');

        $isEnrolled = $schedule->registeredStudents()
            ->where('users.id', $student->id)
            ->where('users.role', 'student')
            ->where(function ($query): void {
                $query->whereNull('schedule_student.status')->orWhereRaw('LOWER(schedule_student.status) = ?', ['active']);
            })->exists();
        abort_unless($isEnrolled, 403, 'This student is not actively enrolled in the selected class.');

        $attendance = $schedule->getConnection()->transaction(function () use ($request, $schedule, $student, $validated): Attendance {
            $lockedSchedule = Schedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSchedule->recording && in_array($lockedSchedule->status, ['Scheduled', 'Ongoing'], true), 422, 'Attendance recording is not active for this class.');
            $stillEnrolled = $lockedSchedule->registeredStudents()
                ->where('users.id', $student->id)
                ->where('users.role', 'student')
                ->where(function ($query): void {
                    $query->whereNull('schedule_student.status')->orWhereRaw('LOWER(schedule_student.status) = ?', ['active']);
                })
                ->exists();
            abort_unless($stillEnrolled, 403, 'This student is no longer actively enrolled in the selected class.');

            $now = now(config('app.timezone'));
            $session = $this->activeSession($lockedSchedule, $now);
            if (! $session) {
                throw ValidationException::withMessages(['session' => 'Attendance can only be recorded during the scheduled class session.']);
            }
            [$attendanceDate, $classStart] = $session;

            $record = Attendance::query()
                ->where('schedule_id', $lockedSchedule->id)
                ->where('student_id', $student->id)
                ->where(function ($query) use ($attendanceDate): void {
                    $query->whereDate('attendance_date', $attendanceDate)
                        ->orWhere(function ($legacy) use ($attendanceDate): void {
                            $legacy->whereNull('attendance_date')->whereDate('created_at', $attendanceDate);
                        });
                })->lockForUpdate()->first();

            if ($record) {
                throw ValidationException::withMessages(['attendance' => 'An attendance record already exists for this session.']);
            }

            $status = $now->gt($classStart->copy()->addMinutes(config('attendance.grace_minutes'))) ? 'late' : 'present';
            $record = new Attendance;
            $record->fill([
                'student_id' => $student->id,
                'schedule_id' => $lockedSchedule->id,
                'attendance_date' => $attendanceDate,
                'status' => $status,
                'time_in' => $now,
                'latitude' => null,
                'longitude' => null,
                'attendance_method' => 'qr_manual',
                'recorded_by' => $request->user()->id,
                'manual_reason' => $validated['reason'] === 'Other'
                    ? 'Other: '.trim($validated['reason_details'])
                    : $validated['reason'],
            ])->save();

            return $record;
        });

        return response()->json([
            'success' => true,
            'status' => strtolower($attendance->status),
            'time' => $attendance->time_in?->timezone(config('app.timezone'))->format('h:i A'),
            'message' => 'Manual attendance recorded successfully.',
        ]);
    }

    public function regenerate(Request $request, Schedule $schedule, User $student): JsonResponse
    {
        $this->authorizeSchedule($request, $schedule);
        abort_unless($this->isActiveEnrollee($schedule, $student), 403);
        $token = $this->newToken();
        $student->forceFill([
            'student_qr_token_hash' => hash('sha256', $token),
            'student_qr_token_encrypted' => Crypt::encryptString($token),
            'student_qr_expires_at' => now(config('app.timezone'))->addYear(),
            'student_qr_revoked_at' => null,
        ])->save();

        return response()->json(['success' => true, 'message' => 'Student QR code regenerated.']);
    }

    public function revoke(Request $request, Schedule $schedule, User $student): JsonResponse
    {
        $this->authorizeSchedule($request, $schedule);
        abort_unless($this->isActiveEnrollee($schedule, $student), 403);
        $student->forceFill([
            'student_qr_token_hash' => null,
            'student_qr_token_encrypted' => null,
            'student_qr_expires_at' => null,
            'student_qr_revoked_at' => now(),
        ])->save();

        return response()->json(['success' => true, 'message' => 'Student QR code revoked.']);
    }

    private function authorizeSchedule(Request $request, Schedule $schedule): void
    {
        $id = (int) $request->user()->id;
        abort_unless((int) $schedule->user_id === $id || (int) $schedule->instructor_id === $id, 403, 'You are not allowed to manage attendance for this class.');
    }

    private function isActiveEnrollee(Schedule $schedule, User $student): bool
    {
        return $student->role === 'student' && strtolower((string) $student->status) === 'active' && $schedule->registeredStudents()
            ->where('users.id', $student->id)
            ->where(function ($query): void {
                $query->whereNull('schedule_student.status')->orWhereRaw('LOWER(schedule_student.status) = ?', ['active']);
            })->exists();
    }

    private function activeSession(Schedule $schedule, $now): ?array
    {
        if (! $schedule->recording || ! in_array($schedule->status, ['Scheduled', 'Ongoing'], true)) {
            return null;
        }

        return app(AttendanceAutoService::class)->checkInSession($schedule, $now);
    }

    private function newToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (User::query()->where('student_qr_token_hash', hash('sha256', $token))->exists());

        return $token;
    }
}

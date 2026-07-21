<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoctorScheduleRequest;
use App\Models\DoctorSchedule;
use Illuminate\Http\JsonResponse;

class DoctorScheduleController extends Controller
{
    /**
     * Display all schedules.
     */
    public function index(): JsonResponse
    {
        $schedules = DoctorSchedule::with('doctor')
            ->orderBy('doctor_id')
            ->orderByRaw("
                FIELD(
                    day,
                    'Saturday',
                    'Sunday',
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday',
                    'Friday'
                )
            ")
            ->get()
            ->map(fn (DoctorSchedule $schedule) => $this->formatSchedule($schedule));

        return response()->json($schedules);
    }

    /**
     * Display schedules for one doctor.
     */
    public function doctorSchedules(int $doctorId): JsonResponse
    {
        $schedules = DoctorSchedule::with('doctor')
            ->where('doctor_id', $doctorId)
            ->orderByRaw("
                FIELD(
                    day,
                    'Saturday',
                    'Sunday',
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday',
                    'Friday'
                )
            ")
            ->get()
            ->map(fn (DoctorSchedule $schedule) => $this->formatSchedule($schedule));

        return response()->json($schedules);
    }

    /**
     * Store new schedule.
     */
    public function store(DoctorScheduleRequest $request): JsonResponse
    {
        $schedule = DoctorSchedule::create($request->validated());

        return response()->json(
            $this->formatSchedule(
                $schedule->load('doctor')
            ),
            201
        );
    }

    /**
     * Display single schedule.
     */
    public function show(DoctorSchedule $schedule): JsonResponse
    {
        return response()->json(
            $this->formatSchedule(
                $schedule->load('doctor')
            )
        );
    }

    /**
     * Update schedule.
     */
    public function update(
        DoctorScheduleRequest $request,
        DoctorSchedule $schedule
    ): JsonResponse {

        $schedule->update($request->validated());

        return response()->json(
            $this->formatSchedule(
                $schedule->refresh()->load('doctor')
            )
        );
    }

    /**
     * Delete schedule.
     */
    public function destroy(DoctorSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json([
            'message' => 'Schedule deleted successfully.'
        ]);
    }

    /**
     * Format schedule response.
     */
    private function formatSchedule(DoctorSchedule $schedule): array
    {
        return [

            'id' => $schedule->id,

            'doctor' => [
                'id' => $schedule->doctor->id,
                'name' => $schedule->doctor->name,
            ],

            'day' => $schedule->day,

            'start_time' => $schedule->start_time,

            'end_time' => $schedule->end_time,

            'is_available' => $schedule->is_available,

            'max_patients' => $schedule->max_patients,

            'created_at' => $schedule->created_at?->toDateTimeString(),

            'updated_at' => $schedule->updated_at?->toDateTimeString(),
        ];
    }
}
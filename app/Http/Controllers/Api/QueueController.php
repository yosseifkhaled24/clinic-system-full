<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class QueueController extends Controller
{
    public function today($doctorId): JsonResponse
    {
        $appointments = Appointment::with('patient')
            ->where('doctor_id', $doctorId)
            ->whereDate('appointment_date', Carbon::today())
            ->orderBy('queue_number')
            ->get();

        // Existing contract, unchanged: {current, waiting, finished}.
        return response()->json($this->bucketQueue($appointments));
    }

    /**
     * All-doctors queue overview for the admin control center.
     *
     * Returns one entry per doctor (ordered by name), each carrying the
     * EXACT per-queue structure the existing single-doctor endpoint
     * returns ({current, waiting, finished} over raw appointment rows),
     * wrapped with the doctor's identity:
     *
     *   [
     *     { "doctor": {id, name, specialty}, "current": ..., "waiting": [...], "finished": [...] },
     *     ...
     *   ]
     *
     * Doctors with no appointments today are included with empty buckets
     * so the frontend can render an empty state per doctor.
     *
     * Efficiency: exactly TWO queries — one for the doctors, one for
     * every today appointment grouped in memory by doctor_id. No N+1.
     */
    public function all(): JsonResponse
    {
        $doctors = Doctor::query()
            ->orderBy('name')
            ->get(['id', 'name', 'specialty']);

        $byDoctor = Appointment::with('patient')
            ->whereDate('appointment_date', Carbon::today())
            ->whereIn('doctor_id', $doctors->pluck('id'))
            ->orderBy('queue_number')
            ->get()
            ->groupBy('doctor_id');

        $queues = $doctors->map(function (Doctor $doctor) use ($byDoctor) {
            $buckets = $this->bucketQueue(
                $byDoctor->get($doctor->id, new EloquentCollection)
            );

            return [
                'doctor' => [
                    'id' => $doctor->id,
                    'name' => $doctor->name,
                    'specialty' => $doctor->specialty,
                ],

                'current' => $buckets['current'],
                'waiting' => $buckets['waiting'],
                'finished' => $buckets['finished'],
            ];
        });

        return response()->json($queues->values());
    }

    /**
     * Shared bucketing for both endpoints. The queue returns raw models;
     * serialize appointment_date as a plain YYYY-MM-DD string while
     * keeping every other attribute and the loaded patient relation
     * exactly as the model would emit them.
     *
     * Only in_progress becomes `current` (at most one per doctor/day —
     * enforced by the appointment write paths), pending/arrived stay in
     * `waiting` in queue-number order, and completed/cancelled/no_show
     * land in `finished` — skipped (no_show) patients never reappear as
     * eligible waiting patients.
     */
    private function bucketQueue(Collection $appointments): array
    {
        $format = fn (Appointment $appointment) => array_merge(
            $appointment->toArray(),
            ['appointment_date' => $appointment->appointment_date?->toDateString()]
        );

        return [
            'current' => ($current = $appointments->firstWhere('status', 'in_progress'))
                ? $format($current)
                : null,

            'waiting' => $appointments->whereIn('status', [
                'pending',
                'arrived'
            ])->map($format)->values(),

            'finished' => $appointments->whereIn('status', [
                'completed',
                'cancelled',
                'no_show'
            ])->map($format)->values(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\Specialty;

/**
 * Builds a bounded, read-only snapshot of the clinic's PUBLIC information
 * (doctors, services, prices, weekly schedules) that is safe to hand to
 * the Gemini API.
 *
 * Never included: patients, appointments, contact messages, reviews,
 * users or any other private record.
 *
 * Future compatibility:
 *  - Specialty data now comes from the dedicated `specialties` table;
 *    doctors link to it through doctors.specialty_id, and the legacy
 *    doctors.specialty string is kept mirrored by the Doctor::saving()
 *    hook. `specialties()` only lists specialties that actually have
 *    doctors (exactly the distinct-doctor-strings set it produced before
 *    the refactor), so recommendations never mention a specialty the
 *    clinic cannot staff — the chatbot prompt and API contract are
 *    unchanged.
 *  - Weekly hours come from the existing `doctor_schedules` rows and are
 *    presented as recurring windows, never as real-time availability, so
 *    per-doctor slot/availability features can later slot in behind the
 *    same context keys.
 */
class ClinicContextService
{
    /** Hard caps so the prompt stays small, predictable and cheap. */
    public const MAX_DOCTORS = 50;

    public const MAX_SERVICES = 50;

    public const MAX_SPECIALTIES = 30;

    public const BIO_LENGTH = 220;

    public const DESCRIPTION_LENGTH = 220;

    /**
     * @return array{
     *     clinic_name: string,
     *     specialties: list<string>,
     *     doctors: list<array<string, mixed>>,
     *     services: list<array<string, mixed>>,
     *     notes: list<string>
     * }
     */
    public function build(): array
    {
        return [
            'clinic_name' => (string) config('app.name', 'MediCare'),
            'specialties' => $this->specialties(),
            'doctors' => $this->doctors(),
            'services' => $this->services(),
            'notes' => $this->notes(),
        ];
    }

    /**
     * Distinct specialties the clinic actually staffs, read from the
     * dedicated `specialties` table (specialties with at least one
     * doctor) — same set the old distinct-doctors.specialty query
     * produced, so chatbot recommendations behave identically.
     *
     * @return list<string>
     */
    public function specialties(): array
    {
        return Specialty::query()
            ->whereHas('doctors')
            ->orderBy('name')
            ->limit(self::MAX_SPECIALTIES)
            ->pluck('name')
            ->filter(fn ($specialty) => is_string($specialty) && trim($specialty) !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function doctors(): array
    {
        return Doctor::query()
            ->with('schedules')
            ->orderBy('name')
            ->limit(self::MAX_DOCTORS)
            ->get()
            ->map(fn (Doctor $doctor) => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialty' => $doctor->specialty,
                'bio' => $this->truncate($doctor->bio, self::BIO_LENGTH),
                // Recurring weekly windows only — NOT real-time availability.
                'weekly_hours' => $this->weeklyHours($doctor),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function services(): array
    {
        return Service::query()
            ->orderBy('name')
            ->limit(self::MAX_SERVICES)
            ->get()
            ->map(fn (Service $service) => [
                'name' => $service->name,
                'description' => $this->truncate($service->description, self::DESCRIPTION_LENGTH),
                // Real price as stored in the system (decimal, EGP); null = not set.
                'price_egp' => $service->price !== null ? (string) $service->price : null,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function notes(): array
    {
        return [
            'Prices are shown in EGP exactly as stored in the clinic system. If a price is missing, say it is not listed and suggest contacting the clinic.',
            'Working hours are recurring weekly windows (day, start, end). They are NOT real-time appointment availability — never promise a specific open slot.',
            'The assistant cannot create, modify, cancel or check appointments. Direct users to the booking page on the website or to the clinic contact channels.',
        ];
    }

    /**
     * Only schedule rows marked available are exposed as working hours.
     *
     * @return list<array{day: string, start_time: string, end_time: string}>
     */
    private function weeklyHours(Doctor $doctor): array
    {
        return $doctor->schedules
            ->where('is_available', true)
            ->map(fn ($schedule) => [
                'day' => $schedule->day,
                'start_time' => $this->formatTime($schedule->start_time),
                'end_time' => $this->formatTime($schedule->end_time),
            ])
            ->values()
            ->all();
    }

    private function formatTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        // SQLite returns "HH:MM:SS", MySQL may return "HH:MM:SS" — trim to HH:MM.
        return strlen($time) > 5 ? substr($time, 0, 5) : $time;
    }

    private function truncate(?string $text, int $length): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}

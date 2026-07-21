<?php

namespace App\Exports;

use App\Models\Appointment;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class TodayAppointmentsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    use Exportable;

    protected ?string $from;
    protected ?string $to;

    public function __construct(?string $from = null, ?string $to = null)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function query()
    {
        $query = Appointment::with([
            'patient',
            'doctor',
            'service',
        ]);

        if ($this->from && $this->to) {
            $query->whereBetween('appointment_date', [
                $this->from,
                $this->to,
            ]);
        } else {
            $query->whereDate('appointment_date', Carbon::today());
        }

        return $query->orderBy('appointment_date');
    }

    public function headings(): array
    {
        return [
            '#',
            'Patient',
            'Doctor',
            'Service',
            'Price',
            'Appointment Date',
            'Payment Status',
            'Payment Method',
            'Status',
            'Notes',
        ];
    }

    public function map($appointment): array
    {
        return [
            $appointment->id,

            $appointment->patient?->name,

            $appointment->doctor?->name,

            $appointment->service?->name,

            $appointment->service_price,

            optional($appointment->appointment_date)->format('Y-m-d'),

            ucfirst($appointment->payment_status),

            $appointment->payment_method
                ? ucfirst($appointment->payment_method)
                : '-',

            ucfirst(str_replace('_', ' ', $appointment->status)),

            $appointment->notes ?: '-',
        ];
    }
}
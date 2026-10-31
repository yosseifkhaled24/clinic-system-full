<?php

namespace App\Exports;

use App\Models\Appointment;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * E: daily revenue export.
 *
 * Counts ONLY appointments that are both completed and paid, using the
 * server-stored service_price (captured at booking) and the server-side
 * payment state (recorded by the admin payment endpoint, the complete
 * endpoint, or the signed Stripe webhook). Frontend-submitted totals are
 * never involved.
 *
 * The sheet contains one row per collected appointment plus, appended
 * after the detail rows by the AfterSheet event:
 *
 *   - the TOTAL collected revenue for the day, and
 *   - a per-payment-method breakdown (count + amount).
 *
 * Defaults to the Africa/Cairo clinic day (the application timezone);
 * an explicit Y-m-d date overrides it.
 */
class DailyRevenueExport implements FromQuery, WithHeadings, WithMapping, WithEvents, ShouldAutoSize
{
    use Exportable;

    protected ?string $date;

    public function __construct(?string $date = null)
    {
        $this->date = $date;
    }

    /**
     * The revenue row set: completed AND paid, for the clinic day.
     */
    public function query()
    {
        return $this->revenueQuery()->orderBy('appointment_date')->orderBy('queue_number');
    }

    public function headings(): array
    {
        return [
            '#',
            'Patient',
            'Doctor',
            'Appointment Date',
            'Queue #',
            'Amount',
            'Payment Method',
        ];
    }

    public function map($appointment): array
    {
        return [
            $appointment->id,

            $appointment->patient?->name,

            $appointment->doctor?->name,

            optional($appointment->appointment_date)->format('Y-m-d'),

            $appointment->queue_number,

            (float) $appointment->service_price,

            $appointment->payment_method
                ? ucfirst($appointment->payment_method)
                : '-',
        ];
    }

    /**
     * Server-computed totals for the same filtered row set: the grand
     * total and a per-method breakdown. Used by the AfterSheet footer and
     * directly asserted by the tests.
     */
    public function summary(): array
    {
        $byMethod = $this->revenueQuery()
            ->selectRaw('payment_method, COUNT(*) as appointments, COALESCE(SUM(service_price), 0) as total')
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get();

        return [
            'appointments' => (int) $this->revenueQuery()->count(),
            'total' => (float) $this->revenueQuery()->sum('service_price'),
            'by_method' => $byMethod,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $summary = $this->summary();

                $sheet = $event->sheet;
                $row = $sheet->getHighestRow() + 2;

                $sheet->setCellValue('A' . $row, 'Total Collected');
                $sheet->setCellValue('F' . $row, $summary['total']);
                $sheet->setCellValue('G' . $row, $summary['appointments'] . ' appointment(s)');

                $row += 2;

                $sheet->setCellValue('A' . $row, 'Payment Method Breakdown');
                $row += 1;

                $sheet->setCellValue('A' . $row, 'Method');
                $sheet->setCellValue('B' . $row, 'Appointments');
                $sheet->setCellValue('C' . $row, 'Amount');

                foreach ($summary['by_method'] as $line) {
                    $row += 1;

                    $sheet->setCellValue('A' . $row, ucfirst($line->payment_method ?: '-'));
                    $sheet->setCellValue('B' . $row, (int) $line->appointments);
                    $sheet->setCellValue('C' . $row, (float) $line->total);
                }
            },
        ];
    }

    /**
     * Completed + paid, for the clinic day (Cairo "today" by default —
     * Carbon::today() runs in the application timezone Africa/Cairo).
     */
    private function revenueQuery()
    {
        return Appointment::query()
            ->with(['patient', 'doctor'])
            ->where('status', 'completed')
            ->where('payment_status', 'paid')
            ->whereDate('appointment_date', $this->date ?? Carbon::today()->toDateString());
    }
}

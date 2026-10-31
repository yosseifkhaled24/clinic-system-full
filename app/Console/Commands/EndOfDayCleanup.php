<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * C: end-of-day cleanup for the appointment queue.
 *
 * Runs on the Africa/Cairo clinic day (the application timezone — every
 * Carbon call below inherits it). Processes appointments booked STRICTLY
 * BEFORE the cutoff date (default: Cairo today), so the exact run time
 * never matters: a booking only becomes "expired/missed" once its clinic
 * day has fully passed.
 *
 *   status in (pending, arrived, in_progress)  = missed, never resolved
 *     ├─ UNPAID  -> permanently deleted (no money changed hands, no
 *     │             financial column exists to preserve, and appointments
 *     │             carrying a review are SKIPPED so no review row is
 *     │             cascade-deleted)
 *     └─ PAID    -> retained as 'no_show' — payment fields (status,
 *                   method, paid_at) are preserved untouched
 *   completed + PAID   -> retained untouched (real revenue)
 *   completed + UNPAID -> 'needs_investigation' flag set (the revenue
 *                         export already excludes it: completed+paid only)
 *   cancelled / no_show-> retained untouched
 *
 * Idempotent by construction: deleted rows are gone, no-show rows are
 * terminal, and the flag is only set when unset. Supports --dry-run to
 * report what WOULD change without writing anything.
 */
class EndOfDayCleanup extends Command
{
    protected $signature = 'appointments:end-of-day
        {--date= : Process appointments booked before this Cairo date (Y-m-d, defaults to today)}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Clean up expired appointments: delete missed unpaid, retain missed paid as no_show, flag completed unpaid.';

    /** Statuses that mean the patient never reached a resolution. */
    private const MISSED_STATUSES = ['pending', 'arrived', 'in_progress'];

    public function handle(): int
    {
        $cutoff = $this->resolveCutoff();

        if ($cutoff === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'End-of-day cleanup (%s) for appointments booked before %s.',
            $dryRun ? 'DRY RUN' : 'live run',
            $cutoff
        ));

        $deleted = 0;
        $noShowed = 0;
        $flagged = 0;
        $alreadyFlagged = 0;
        $skippedReview = 0;

        // Chunked so a large backlog never loads into memory at once.
        Appointment::query()
            ->whereDate('appointment_date', '<', $cutoff)
            ->chunkById(200, function ($appointments) use (
                $dryRun,
                &$deleted,
                &$noShowed,
                &$flagged,
                &$alreadyFlagged,
                &$skippedReview
            ) {
                foreach ($appointments as $appointment) {
                    if (in_array($appointment->status, self::MISSED_STATUSES, true)) {
                        if ($appointment->payment_status === 'paid') {
                            // Expired PAID miss: retain as no_show, keep the
                            // financial columns exactly as recorded.
                            $noShowed++;

                            if (!$dryRun) {
                                $appointment->update(['status' => 'no_show']);
                            }

                            continue;
                        }

                        // Expired UNPAID miss: permanent delete is only safe
                        // when nothing references the row. Reviews cascade
                        // on delete (reviews.appointment_id), so an
                        // appointment with a review is never removed.
                        if ($appointment->review()->exists()) {
                            $skippedReview++;

                            $this->warn(sprintf(
                                '  Skipped appointment #%d: unpaid miss but has a review — not deleted.',
                                $appointment->id
                            ));

                            continue;
                        }

                        $deleted++;

                        if (!$dryRun) {
                            $appointment->delete();
                        }

                        continue;
                    }

                    if ($appointment->status === 'completed' && $appointment->payment_status !== 'paid') {
                        // Completed but never paid: flag for staff. The
                        // revenue export excludes it automatically because
                        // it only counts completed + paid rows.
                        if ($appointment->needs_investigation) {
                            $alreadyFlagged++;

                            continue;
                        }

                        $flagged++;

                        if (!$dryRun) {
                            $appointment->update(['needs_investigation' => true]);
                        }
                    }

                    // completed+paid, cancelled, no_show: retained untouched.
                }
            });

        $this->table(
            ['Action', 'Count'],
            [
                ['Deleted (missed + unpaid)', $deleted],
                ['Retained as no_show (missed + paid)', $noShowed],
                ['Flagged for investigation (completed + unpaid)', $flagged],
                ['Already flagged', $alreadyFlagged],
                ['Skipped, has a review', $skippedReview],
            ]
        );

        $this->info($dryRun
            ? 'Dry run complete — no rows were changed.'
            : 'Cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * The cutoff: bookings strictly before this Cairo-local date are
     * expired. --date must be a valid Y-m-d; default is today.
     */
    private function resolveCutoff(): ?string
    {
        $raw = $this->option('date');

        if (!$raw) {
            return Carbon::today()->toDateString();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw)) {
            $this->error('The --date option must be a YYYY-MM-DD string.');

            return null;
        }

        return Carbon::parse($raw)->toDateString();
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D2: widen appointments.payment_method to the full approved list.
     *
     * The column started as enum('cash','visa','insurance') while the
     * admin PATCH /appointments/{id}/payment endpoint already accepted
     * 'stripe' / 'instapay' / 'vodafone_cash' in validation, so those
     * requests died as an uncaught SQLSTATE 23000 instead of a controlled
     * response. This migration makes the schema agree with the approved
     * contract:
     *
     *     cash, visa, insurance, stripe, instapay, vodafone_cash
     *
     * SAFETY / DEPLOY NOTES
     * ---------------------
     * - Forward-only widening: existing 'cash' / 'visa' / 'insurance'
     *   values are preserved as-is. No rows are rewritten, backfilled,
     *   or deleted.
     * - Run this read-only pre-check against the LIVE database before
     *   migrating. It returns rows only if the rollback would be unsafe:
     *
     *       SELECT DISTINCT payment_method FROM appointments
     *       WHERE payment_method IS NOT NULL
     *         AND payment_method NOT IN ('cash','visa','insurance');
     *
     *   Any returned value is a post-widening method stored on live data.
     *   The guarded down() below refuses to run while such values exist,
     *   rather than silently truncating or invalidating them.
     * - On SQLite the enum is a CHECK constraint; Laravel's native
     *   change() regenerates it. On MySQL it is a real ENUM and change()
     *   issues a MODIFY COLUMN. Neither touches other columns.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('payment_method', [
                'cash',
                'visa',
                'insurance',
                'stripe',
                'instapay',
                'vodafone_cash',
            ])->nullable()->change();
        });
    }

    /**
     * Roll back to the original three-value enum, but only when no live
     * row actually uses a newer method. If any does, throw loudly instead
     * of truncating / invalidating data.
     */
    public function down(): void
    {
        $outOfScope = DB::table('appointments')
            ->whereNotNull('payment_method')
            ->whereNotIn('payment_method', ['cash', 'visa', 'insurance'])
            ->exists();

        if ($outOfScope) {
            throw new RuntimeException(
                'Cannot roll back the payment_method widening: appointments rows already '
                . 'use stripe/instapay/vodafone_cash. Narrowing the enum would truncate or '
                . 'invalidate them. Keep the widened enum, or migrate those rows first.'
            );
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('payment_method', [
                'cash',
                'visa',
                'insurance',
            ])->nullable()->change();
        });
    }
};

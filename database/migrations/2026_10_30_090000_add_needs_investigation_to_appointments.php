<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * End-of-day cleanup (feature C): a COMPLETED but UNPAID appointment is
     * money that was never collected for a consultation that happened. The
     * cleanup command flags those rows for staff investigation instead of
     * deleting or altering them, and the revenue export excludes them
     * implicitly (it only counts completed + paid rows).
     *
     * SAFETY / DEPLOY NOTES
     * ---------------------
     * - Purely additive: one boolean column with a NOT NULL default of
     *   false. No existing row is rewritten (the default applies to all
     *   existing rows), backfilled, or deleted. No financial column is
     *   touched.
     * - Safe on MySQL 5.7+/8.0 and SQLite: adding a column with a constant
     *   default is an INSTANT/in-place metadata change on MySQL 8 and does
     *   not rewrite the table.
     * - down() drops only this flag column; no appointment data is lost.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('needs_investigation')->default(false);
        });
    }

    /**
     * Reverse the migrations. Drops only the flag column.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('needs_investigation');
        });
    }
};

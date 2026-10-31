<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One review per appointment, enforced by the database itself.
     *
     * DEPLOY NOTE — run this pre-check against the target database
     * BEFORE migrating. It returns rows only if the migration would
     * fail:
     *
     *   SELECT appointment_id, COUNT(*) AS c FROM reviews
     *   GROUP BY appointment_id HAVING c > 1;
     *
     * If it returns rows, resolve them manually first. This migration
     * never deletes or alters review data — a conflict makes it fail
     * loudly instead.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique('appointment_id', 'reviews_appointment_id_unique');
        });
    }

    /**
     * Reverse the migrations (index only; no data is touched either way).
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_appointment_id_unique');
        });
    }
};

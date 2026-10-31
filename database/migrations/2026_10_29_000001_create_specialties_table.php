<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the dedicated `specialties` reference table.
     *
     * Conventions mirror the existing `doctors` / `services` tables:
     * auto-increment primary key, string name, timestamps (this project
     * uses no soft deletes anywhere, so none are added here).
     *
     * The unique index on `name` is the engine-level guarantee against
     * exact duplicates. Case-insensitive / whitespace duplicates are
     * prevented at the application layer (Specialty::normalizeName() and
     * Specialty::findOrCreateByName()) because SQLite's UNIQUE is
     * case-sensitive while MySQL's default utf8mb4 collation is not —
     * the application logic behaves identically on both engines.
     *
     * Guarded with hasTable() so up() is idempotent: the existing-data
     * migration test re-runs this file against a database that already
     * has the table without breaking.
     */
    public function up(): void
    {
        if (Schema::hasTable('specialties')) {
            return;
        }

        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only removes the table this migration created. It can only run
     * after the doctors.specialty_id link column has been dropped by its
     * own migration's down() (standard reverse-order migrate:rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('specialties');
    }
};

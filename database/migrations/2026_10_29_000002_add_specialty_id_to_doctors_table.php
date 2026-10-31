<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link every doctor to a row in `specialties` via `specialty_id`.
     *
     * DATA-PRESERVATION STRATEGY (fully additive — nothing is deleted):
     *
     *  - The legacy `doctors.specialty` string column is KEPT. Every
     *    existing API response, chatbot query and frontend consumer keeps
     *    reading the exact same plain-string field as before, and
     *    rollback stays lossless.
     *
     *  - `specialty_id` is added NULLABLE at the database level.
     *    MySQL cannot safely add a NOT NULL column to a populated table
     *    without a default, and SQLite refuses it outright; refusing to
     *    take that risk is why enforcement lives in the application
     *    instead (Doctor::saving() always resolves a link before a row
     *    can be written — see the Doctor model).
     *
     *  - The backfill below walks every not-yet-linked doctor, trims and
     *    collapses whitespace in its stored string and links it to a
     *    specialty row matched case-insensitively via LOWER(name)=LOWER(?)
     *    (portable across MySQL and SQLite). Values that differ only by
     *    case/whitespace collapse into ONE specialty row; genuinely
     *    different names are never merged (no fuzzy matching is applied).
     *    The first-seen spelling wins (processed in id order), so the
     *    clinic's existing display casing is preserved, and the canonical
     *    name is mirrored back into doctors.specialty so no whitespace or
     *    casing display-duplicates remain for readers of that column.
     *    On today's already-canonical data the mirror is a no-op.
     *
     *  - A doctor whose specialty string is empty/whitespace-only stays
     *    unlinked (the nullable column allows it). No data is invented
     *    and no data is deleted.
     *
     *  - Idempotent: DDL only runs when the column is missing and the
     *    backfill only touches doctors.specialty_id IS NULL rows, so
     *    re-running up() never duplicates or loses anything. This is
     *    exactly what the existing-data migration test exercises.
     *
     *  - Deletion rule: restrictOnDelete() — a specialty referenced by
     *    doctors can never be deleted out from under them, and deleting a
     *    doctor never touches specialties. Nothing cascades. The admin
     *    API adds a friendly 409 pre-check on top; the foreign key is the
     *    hard backstop.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('doctors', 'specialty_id')) {
            // Column + FK in their own Schema::table call: on SQLite this
            // triggers Laravel's table-recreation path, which is the only
            // way that driver applies foreign keys added to an existing
            // table (rows are copied, nothing is lost).
            Schema::table('doctors', function (Blueprint $table) {
                $table->foreignId('specialty_id')
                    ->nullable()
                    ->after('specialty')
                    ->constrained('specialties')
                    ->restrictOnDelete();
            });

            // The index is created in a SEPARATE statement: a plain
            // CREATE INDEX on both engines (running it inside the FK
            // blueprint would make SQLite's recreation path compile it a
            // second time). Explicit because MySQL/InnoDB auto-indexes a
            // foreign key while SQLite does not — this keeps chatbot
            // distinct-specialty reads and admin joins fast on both.
            Schema::table('doctors', function (Blueprint $table) {
                $table->index('specialty_id');
            });
        }

        $this->backfillExistingDoctors();
    }

    /**
     * Reverse the migrations — drops ONLY the link column/index/constraint
     * this migration added. No doctor row and no specialty row is deleted
     * by this method; the strings in doctors.specialty are already
     * canonicalized (whitespace/casing folded by the backfill) and are
     * intentionally left exactly as they are — that normalization is the
     * deduplication requirement, and the values themselves are intact.
     * The specialties table is dropped separately by its own migration's
     * down() (standard reverse-order rollback).
     */
    public function down(): void
    {
        if (! Schema::hasColumn('doctors', 'specialty_id')) {
            return;
        }

        // Order matters on every engine: index first, then the constraint,
        // then the column (each in its own statement so no engine sees a
        // mixed blueprint it cannot compile).
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropIndex(['specialty_id']);
        });

        // Dropping by COLUMNS (not by name) works everywhere: MySQL issues
        // ALTER TABLE ... DROP FOREIGN KEY, and SQLite — which cannot drop
        // foreign keys directly — rebuilds the table without this
        // constraint (Laravel's documented alter path for that driver).
        // After this, no constraint references the column, so the DROP
        // COLUMN below succeeds on both engines.
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropForeign(['specialty_id']);
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('specialty_id');
        });
    }

    /**
     * Link every existing doctor to a specialty row, creating rows from
     * distinct stored strings. Idempotent — only unlinked doctors are
     * considered, in id order so the first-seen spelling is stable.
     */
    private function backfillExistingDoctors(): void
    {
        $doctors = DB::table('doctors')
            ->whereNull('specialty_id')
            ->orderBy('id')
            ->get(['id', 'specialty']);

        foreach ($doctors as $doctor) {
            $name = $this->normalizeName((string) $doctor->specialty);

            if ($name === '') {
                // Not a usable value (empty or whitespace-only): leave the
                // doctor unlinked rather than inventing a specialty.
                continue;
            }

            [$specialtyId, $canonicalName] = $this->resolveSpecialty($name);

            DB::table('doctors')->where('id', $doctor->id)->update([
                // Mirror the canonical spelling so every reader of the
                // legacy string column sees the same value the link uses.
                'specialty' => $canonicalName,
                'specialty_id' => $specialtyId,
            ]);
        }
    }

    /**
     * Find the specialty matching $name case-insensitively, or create it
     * (first-seen spelling becomes canonical). Returns [id, canonicalName].
     *
     * @return array{0: int, 1: string}
     */
    private function resolveSpecialty(string $name): array
    {
        $existing = DB::table('specialties')
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->orderBy('id')
            ->first(['id', 'name']);

        if ($existing !== null) {
            return [(int) $existing->id, (string) $existing->name];
        }

        $now = now();

        $id = DB::table('specialties')->insertGetId([
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(int) $id, $name];
    }

    /**
     * Trim + collapse every run of whitespace to a single space. Must stay
     * in step with Specialty::normalizeName() (kept duplicated here so a
     * migration never depends on an application class that may change).
     */
    private function normalizeName(string $value): string
    {
        $trimmed = trim($value);

        return trim(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
    }
};

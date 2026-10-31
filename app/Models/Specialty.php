<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

/**
 * A clinic specialty (the dedicated reference table for the free-text
 * `doctors.specialty` string that historically lived only on doctors).
 *
 * Compatibility notes:
 *  - The legacy `doctors.specialty` string column is kept in sync with
 *    these rows (see the Doctor::saving() hook and the admin rename
 *    handler), so every existing API consumer keeps reading a plain
 *    string exactly as before.
 *  - Duplicate prevention is case-insensitive and whitespace-aware at
 *    the APPLICATION layer on purpose: SQLite's unique index is
 *    case-sensitive while MySQL's default collation is not — the rules
 *    below behave identically on both engines. Only names that match
 *    exactly after normalization + lowercasing are treated as the same
 *    specialty; genuinely different names are never merged.
 */
class Specialty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    protected static function booted(): void
    {
        // Every stored name is canonical: trimmed, with inner whitespace
        // runs collapsed, so the unique index can never hold padded
        // variants of the same specialty.
        static::saving(function (Specialty $specialty) {
            $specialty->name = static::normalizeName((string) $specialty->name);
        });
    }

    /**
     * Trim + collapse every run of whitespace to a single space.
     * Must stay in step with the backfill inside the
     * add_specialty_id_to_doctors_table migration (duplicated there on
     * purpose — migrations must not depend on application classes).
     */
    public static function normalizeName(string $value): string
    {
        $trimmed = trim($value);

        return trim(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
    }

    /**
     * Find the specialty whose name matches $name case-insensitively
     * after whitespace normalization, or create it (the first spelling
     * seen becomes the stored/canonical one).
     *
     * Never merges genuinely different names: comparison is an exact
     * character-for-character (lowercased) match of the normalized
     * values only — no fuzzy/partial matching.
     *
     * @throws \InvalidArgumentException when the name is empty after normalization
     */
    public static function findOrCreateByName(string $name): self
    {
        $normalized = static::normalizeName($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Specialty name cannot be empty.');
        }

        $existing = static::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$normalized])
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return static::create(['name' => $normalized]);
        } catch (QueryException $e) {
            // Lost a creation race against a concurrent request for the
            // same name: the unique index kept the data correct, so
            // re-read the winner instead of surfacing the error.
            return static::query()
                ->whereRaw('LOWER(name) = LOWER(?)', [$normalized])
                ->orderBy('id')
                ->firstOrFail();
        }
    }

    /**
     * Whether $name is already used by a specialty other than $ignoreId
     * (case-insensitive, whitespace-normalized) — used by the admin CRUD
     * to return a standard 422 "already taken" validation error on both
     * engines.
     */
    public static function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        $normalized = static::normalizeName($name);

        if ($normalized === '') {
            return false;
        }

        return static::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$normalized])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}

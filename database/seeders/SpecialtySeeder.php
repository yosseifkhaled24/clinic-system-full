<?php

namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

/**
 * Seeds the clinic's specialty list FIRST (doctors are seeded after this
 * and reference these records by id).
 *
 * Idempotent by design: firstOrCreate means re-running the seeder (or the
 * whole DatabaseSeeder) never duplicates specialties — existing rows are
 * matched case-insensitively by Specialty::findOrCreateByName(), so even a
 * pre-existing 'cardiology' row satisfies the 'Cardiology' entry instead of
 * creating a second one.
 */
class SpecialtySeeder extends Seeder
{
    /**
     * The clinic's stable, explicit demo specialty names. Public so other
     * seeders can resolve against exactly this list.
     *
     * @var list<string>
     */
    public const NAMES = [
        'Cardiology',
        'Dermatology',
        'Dentistry',
        'Pediatrics',
        'Orthopedics',
        'Neurology',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Specialty::findOrCreateByName($name);
        }
    }
}

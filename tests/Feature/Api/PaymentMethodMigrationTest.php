<?php

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 |---------------------------------------------------------------------------
 | D2 migration — widen_appointments_payment_method (up/down safety)
 |---------------------------------------------------------------------------
 |
 | Verifies on the isolated in-memory SQLite test database that:
 |   - up() preserves existing cash/visa/insurance rows untouched,
 |   - up() lets every approved method persist afterwards,
 |   - down() rolls back cleanly while only legacy values are stored,
 |   - down() REFUSES (RuntimeException, no data loss) when a row already
 |     uses stripe/instapay/vodafone_cash.
 |
 | No live database is touched; no rows are deleted or truncated by the
 | migration itself.
 |
 | Helper names carry the `pmig` prefix to avoid Pest's global function
 | namespace colliding with the other batch test files.
 */

function pmigAppointment(?string $method): Appointment
{
    $owner = User::factory()->create(['role' => 'user']);
    $patient = Patient::factory()->create([
        'user_id' => $owner->id,
        'phone' => '012' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
    ]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'user_id' => $owner->id,
        'payment_status' => 'unpaid',
        'payment_method' => null,
        'paid_at' => null,
    ]);

    if ($method !== null) {
        DB::table('appointments')->where('id', $appointment->id)->update([
            'payment_method' => $method,
        ]);
    }

    return $appointment->refresh();
}

function pmigMigration(): object
{
    return require database_path(
        'migrations/2026_10_29_120000_widen_appointments_payment_method.php'
    );
}

test('up() preserves legacy values and allows every approved method afterwards', function () {
    $legacyCash = pmigAppointment('cash');
    $legacyVisa = pmigAppointment('visa');
    $legacyInsurance = pmigAppointment('insurance');
    $legacyNull = pmigAppointment(null);

    pmigMigration()->up();

    // Legacy rows are untouched — nothing rewritten, nothing lost.
    expect($legacyCash->refresh()->payment_method)->toBe('cash')
        ->and($legacyVisa->refresh()->payment_method)->toBe('visa')
        ->and($legacyInsurance->refresh()->payment_method)->toBe('insurance')
        ->and($legacyNull->refresh()->payment_method)->toBeNull();

    // Every approved method now persists without a CHECK violation.
    foreach (['cash', 'visa', 'insurance', 'stripe', 'instapay', 'vodafone_cash'] as $method) {
        $row = pmigAppointment(null);

        DB::table('appointments')->where('id', $row->id)->update([
            'payment_method' => $method,
        ]);

        expect($row->refresh()->payment_method)->toBe($method);
    }
});

test('down() rolls back cleanly while only legacy values are stored', function () {
    pmigAppointment('cash');
    pmigAppointment('insurance');
    pmigAppointment(null);

    $migration = pmigMigration();
    $migration->up();
    $migration->down();

    // The CHECK constraint is back to the original three values: a legacy
    // value still persists, an out-of-scope value is rejected again.
    $legacy = pmigAppointment(null);
    DB::table('appointments')->where('id', $legacy->id)->update(['payment_method' => 'visa']);
    expect($legacy->refresh()->payment_method)->toBe('visa');

    $rejected = pmigAppointment(null);

    expect(fn () => DB::table('appointments')->where('id', $rejected->id)->update([
        'payment_method' => 'stripe',
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect($rejected->refresh()->payment_method)->toBeNull();
});

test('down() refuses to run (no data loss) when a newer method is already stored', function () {
    $migration = pmigMigration();
    $migration->up();

    // A live row now uses a post-widening method.
    $stripeRow = pmigAppointment(null);
    DB::table('appointments')->where('id', $stripeRow->id)->update([
        'payment_method' => 'stripe',
    ]);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class);

    // The row and the widened enum are both intact: nothing truncated.
    expect($stripeRow->refresh()->payment_method)->toBe('stripe')
        ->and(Schema::getColumns('appointments'))->toBeArray();
});

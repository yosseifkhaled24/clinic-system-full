<?php

use App\Console\Commands\MakeAdmin;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

/*
 |--------------------------------------------------------------------------
 | A7 — seeder & factory security (H-1 + H-2)
 |--------------------------------------------------------------------------
 |
 | H-1: DatabaseSeeder used to insert a fixed administrator account
 |      with a publicly readable password, so every `db:seed` (the
 |      composer `setup` script a buyer runs included) shipped a known
 |      privileged login. The seeder no longer creates ANY
 |      administrator; the first one is provisioned explicitly with
 |      `php artisan app:admin <email>` — hidden password prompt, no
 |      `--password` option (argv would expose it), idempotent.
 |
 | H-2: UserFactory randomly assigned 'admin' to ~25% of users, so a
 |      privileged account could appear wherever a test or a dev dump
 |      called User::factory(). The default is now 'user'; administrators
 |      are requested explicitly with ->admin().
 |
 | The factory test password ('password') is intentionally unchanged —
 | the login suites depend on it — but SEEDED demo accounts never use
 | it: each gets its own random bcrypt hash.
 |
 | Helper names carry the `apv` prefix so they can never collide with
 | the helpers of the other feature files when the whole suite loads.
 */

function apvUser(): User
{
    return User::factory()->create([
        'role' => 'user',
        'email' => 'apv-' . uniqid() . '@gmail.com',
    ]);
}

test('the factory never creates an administrator unless the admin state is requested', function () {
    expect(User::factory()->create()->role)->toBe('user')
        ->and(User::factory()->admin()->create()->role)->toBe('admin');
});

test('app:admin has no password option so a credential can never land in argv', function () {
    $command = new MakeAdmin();

    expect($command->getDefinition()->hasArgument('email'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('password'))->toBeFalse();
});

test('app:admin creates a new administrator from a hidden password prompt', function () {
    $email = 'apv-new-' . uniqid() . '@gmail.com';

    $this->artisan('app:admin', ['email' => $email, '--name' => 'Clinic Admin'])
        ->expectsQuestion('Administrator password (minimum 8 characters)', 'a-very-private-pass')
        ->assertExitCode(0);

    $user = User::where('email', $email)->firstOrFail();

    expect($user->role)->toBe('admin')
        ->and($user->name)->toBe('Clinic Admin')
        // The login flow refuses unverified addresses, so the account
        // must be usable immediately.
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-very-private-pass', $user->password))->toBeTrue();
});

test('app:admin promotes an existing account without touching its password', function () {
    $user = apvUser();
    $passwordBefore = $user->password;

    // No prompt: promotion never asks for or changes a password.
    $this->artisan('app:admin', ['email' => $user->email])->assertExitCode(0);

    $fresh = $user->fresh();

    expect($fresh->role)->toBe('admin')
        ->and($fresh->password)->toBe($passwordBefore);
});

test('app:admin is idempotent for an account that already is an administrator', function () {
    $user = User::factory()->admin()->create();
    $passwordBefore = $user->password;

    $this->artisan('app:admin', ['email' => $user->email])->assertExitCode(0);

    expect($user->fresh()->role)->toBe('admin')
        ->and($user->fresh()->password)->toBe($passwordBefore);
});

test('app:admin refuses a short password and creates nothing', function () {
    $email = 'apv-short-' . uniqid() . '@gmail.com';

    $this->artisan('app:admin', ['email' => $email])
        ->expectsQuestion('Administrator password (minimum 8 characters)', 'short')
        ->assertExitCode(1);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

test('app:admin rejects an invalid e-mail address without prompting', function () {
    $this->artisan('app:admin', ['email' => 'not-an-email'])->assertExitCode(1);

    expect(User::count())->toBe(0);
});

test('the seeder creates no administrator and no guessable demo password', function () {
    $this->seed(DatabaseSeeder::class);

    // H-1: no privileged account may exist after a plain db:seed.
    expect(User::where('role', 'admin')->count())->toBe(0);

    $passwords = User::pluck('password')->all();

    // 30 demo accounts, every one with its OWN random hash (a shared
    // value would be evaluated once and reused for all rows), and none
    // of them the factory's well-known test password.
    expect(count($passwords))->toBe(30)
        ->and(count(array_unique($passwords)))->toBe(30);

    foreach ($passwords as $hash) {
        expect(Hash::check('password', $hash))->toBeFalse();
    }
});

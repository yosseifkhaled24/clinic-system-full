<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * A7 (H-1) — the only supported way to provision a clinic administrator.
 *
 * DatabaseSeeder used to create a fixed admin account with a
 * well-known password, which meant every `db:seed` — including the
 * composer `setup` script a buyer runs — shipped a publicly readable
 * privileged login. That account is gone; this command replaces it.
 *
 * Design rules:
 *  - no migration and no new table: it only touches the existing
 *    users row, so it is safe on any installed database;
 *  - the password is read through a HIDDEN prompt and never written
 *    to stdout, argv, the log or any file — there is deliberately no
 *    `--password` option, because command-line arguments are visible
 *    in the process list and in shell history;
 *  - `role` is not mass assignable (SEC-10), so it is assigned with a
 *    direct property write;
 *  - the account is marked verified, because the login flow rejects
 *    unverified addresses;
 *  - it is idempotent: an existing account is promoted, never
 *    re-created, and its password is never silently changed.
 */
class MakeAdmin extends Command
{
    protected $signature = 'app:admin
        {email : E-mail address of the administrator account}
        {--name= : Display name, used only when the account must be created}
        {--phone= : Phone number, used only when the account must be created}';

    protected $description = 'Create or promote the clinic administrator (the password is prompted for and never printed)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid e-mail address is required.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            return $this->promote($user, $email);
        }

        return $this->create($email);
    }

    private function promote(User $user, string $email): int
    {
        if ($user->role === 'admin') {
            $this->info("{$email} already is an administrator. Password unchanged.");

            return self::SUCCESS;
        }

        // SEC-10: `role` is not mass assignable — direct write only.
        $user->role = 'admin';
        $user->save();

        $this->info("{$email} was promoted to administrator. Password unchanged.");

        return self::SUCCESS;
    }

    private function create(string $email): int
    {
        $password = (string) $this->secret('Administrator password (minimum 8 characters)');

        if (mb_strlen($password) < 8) {
            $this->error('The password must be at least 8 characters. Nothing was created.');

            return self::FAILURE;
        }

        $user = new User([
            'name' => (string) ($this->option('name') ?: 'Administrator'),
            'email' => $email,
            'phone' => $this->option('phone') ?: null,
            'password' => Hash::make($password),
        ]);

        // Not fillable (never reaches the mass-assignment path) and the
        // login flow refuses unverified addresses, so it is set here.
        $user->email_verified_at = now();
        $user->role = 'admin';
        $user->save();

        $this->info("Administrator account created for {$email}. The password was entered privately and is not shown again.");

        return self::SUCCESS;
    }
}

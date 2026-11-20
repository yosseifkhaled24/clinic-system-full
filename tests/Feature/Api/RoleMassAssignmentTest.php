<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SEC-10 — user role mass-assignment protection
|--------------------------------------------------------------------------
|
| `role` was removed from User::$fillable. Role assignment now happens
| only through explicit server-side writes (API registration pins 'user',
| the seeder pins 'admin'). Factories still work because Laravel builds
| factory models under Model::unguarded() — existing role records and
| admin accounts are untouched.
|
| There is NO admin role-management endpoint in this API (verified by
| inspection: no request rule anywhere accepts a `role` field), so there
| is nothing else to keep working — and nothing that could be used to
| escalate one either.
|
| Isolated in-memory SQLite (phpunit.xml). No real MySQL data.
|
*/

test('SEC-10: registration ignores a submitted role and always creates a normal account', function () {
    $payload = [
        'name' => 'Role Injection Attempt',
        'email' => 's10r.role.test@gmail.com',
        'phone' => '01099990001',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        // Hostile input: must never be honored.
        'role' => 'admin',
    ];

    $response = $this->postJson('/api/register', $payload)
        ->assertStatus(201);

    $user = User::where('email', $payload['email'])->firstOrFail();

    expect($user->role)->toBe('user')
        ->and($response->json('user.role'))->toBe('user');
});

test('SEC-10: the User model no longer mass-assigns role', function () {
    $user = new User();
    $user->fill([
        'name' => 'Fill Test',
        'email' => 's10r.fill@example.com',
        'role' => 'admin',
    ]);

    expect($user->name)->toBe('Fill Test')
        ->and($user->email)->toBe('s10r.fill@example.com')
        ->and($user->role)->not->toBe('admin');
});

test('SEC-10: an authenticated profile-style update cannot escalate the role', function () {
    $user = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($user);

    // Whatever the endpoint answers (it does not accept role data),
    // the persisted role must remain exactly what it was.
    $this->patchJson('/api/user', [
        'name' => 'Renamed Patient',
        'role' => 'admin',
    ]);

    expect($user->refresh()->role)->toBe('user');
});

test('SEC-10: existing role records and admin authorization keep working unchanged', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $patient = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($admin);
    $this->getJson('/api/dashboard')->assertStatus(200);

    expect($admin->refresh()->role)->toBe('admin')
        ->and($patient->refresh()->role)->toBe('user');
});

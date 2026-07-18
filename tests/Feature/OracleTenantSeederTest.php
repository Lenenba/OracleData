<?php

use App\Models\User;
use Database\Seeders\OracleTenantSeeder;
use Database\Seeders\UserSeeder;

test('the tenant seeder only provisions demo accounts', function () {
    $this->seed(UserSeeder::class);
    $this->seed(OracleTenantSeeder::class);

    $demo = User::query()->where('email', 'test@example.com')->sole();

    expect($demo->oracleTenants()->count())->toBe(1);
});

test('the tenant seeder never touches a real account', function () {
    // Compte réel avec son propre tenant par défaut issu de l'onboarding.
    $real = createConnectedUser(
        ['email' => 'jules@example.com'],
        ['key' => 'vdl_prod', 'is_default' => true],
    );

    $this->seed(UserSeeder::class);
    $this->seed(OracleTenantSeeder::class);

    $real->refresh();

    expect($real->oracleTenants()->count())->toBe(1)
        ->and($real->oracleTenants()->where('key', 'vdl_prod')->where('is_default', true)->exists())->toBeTrue()
        ->and($real->oracleTenants()->where('is_default', true)->count())->toBe(1);
});

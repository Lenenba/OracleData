<?php

use App\Models\Query;
use App\Models\User;

test('database seeder creates demo users and query library', function () {
    $this->seed();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'analyste@oracledata.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'finance@oracledata.test')->exists())->toBeTrue()
        ->and(Query::query()->count())->toBeGreaterThanOrEqual(6)
        ->and(Query::query()->where('access_level', 'organization')->count())->toBeGreaterThanOrEqual(4)
        ->and(Query::query()->where('parameters->resource_key', 'purchase_orders')->exists())->toBeTrue();
});

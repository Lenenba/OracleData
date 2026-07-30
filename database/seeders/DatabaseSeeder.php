<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            OracleTenantSeeder::class,
            CategorySeeder::class,
            TagSeeder::class,
            QueryTemplateSeeder::class,
            QuerySeeder::class,
            SemanticLayerSeeder::class,
            // — enrichment seeders (depend on the above) —
            GroupSeeder::class,
            SharingSeeder::class,
            ChangeRequestSeeder::class,
            DashboardSeeder::class,
            AutomationSeeder::class,
            ApiTokenSeeder::class,
            QueryExecutionSeeder::class,
        ]);
    }
}

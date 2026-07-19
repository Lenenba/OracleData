<?php

namespace Database\Seeders;

use App\Services\SemanticCatalogSynchronizer;
use Illuminate\Database\Seeder;

class SemanticLayerSeeder extends Seeder
{
    public function run(SemanticCatalogSynchronizer $synchronizer): void
    {
        $synchronizer->synchronize();
    }
}

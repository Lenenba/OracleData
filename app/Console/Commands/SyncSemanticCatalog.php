<?php

namespace App\Console\Commands;

use App\Services\SemanticCatalogSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('semantic:sync-catalog')]
#[Description('Importe le catalogue Oracle autorisé et le glossaire dans une version sémantique canonique')]
class SyncSemanticCatalog extends Command
{
    public function handle(SemanticCatalogSynchronizer $synchronizer): int
    {
        $result = $synchronizer->synchronize();
        $version = $result['version'];
        $created = collect($result['created'])->sum();

        $this->info($result['version_created']
            ? "Version sémantique {$version->version_number} publiée."
            : "Catalogue inchangé ; version {$version->version_number} conservée.");
        $this->line("{$created} ligne(s) créée(s), empreinte {$version->content_hash}.");

        return self::SUCCESS;
    }
}

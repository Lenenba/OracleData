<?php

namespace App\Console\Commands;

use App\Models\QueryExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

#[Signature('exports:purge')]
#[Description('Supprime les fichiers et enregistrements des exports serveur expirés')]
class PurgeExpiredQueryExports extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk(QueryExport::DISK);
        $purged = 0;

        QueryExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(200, function (Collection $exports) use ($disk, &$purged): void {
                /** @var Collection<int, QueryExport> $exports */
                foreach ($exports as $export) {
                    if ($export->file_path !== null) {
                        $disk->delete($export->file_path);
                    }

                    $export->delete();
                    $purged++;
                }
            });

        $this->info("{$purged} export(s) expiré(s) purgé(s).");

        return self::SUCCESS;
    }
}

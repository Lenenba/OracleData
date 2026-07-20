<?php

namespace App\Actions\Queries;

use App\Enums\QueryAccessLevel;
use App\Models\Query;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\PostmanCollectionImporter;
use App\Services\SemanticLineageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportPostmanQueries
{
    public function __construct(
        private readonly PostmanCollectionImporter $importer,
        private readonly FusionManager $fusion,
        private readonly SemanticLineageService $lineage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    public function preview(User $user, array $collection): array
    {
        $result = $this->importer->parse($collection);
        $existing = $this->existingFingerprints($user);

        foreach ($result['importable'] as $index => $candidate) {
            $alreadyImported = isset($existing[$this->fingerprint(
                $candidate['resource_path'],
                $candidate['parameters'],
            )]);

            $result['importable'][$index]['already_imported'] = $alreadyImported;
            $result['importable'][$index]['default_selected'] = ! $alreadyImported
                && ! $candidate['tenant_specific_path'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $collection
     * @param  list<int>|null  $selected
     * @return array{created: int, skipped_existing: int, skipped_invalid_selection: int, selected: int}
     */
    public function execute(User $user, array $collection, ?array $selected, string $tenantKey): array
    {
        $fusion = $this->fusion->forUser($user);

        if (! $fusion->has($tenantKey)) {
            throw ValidationException::withMessages([
                'tenant_key' => __('L’environnement Oracle choisi n’est pas disponible.'),
            ]);
        }

        $preview = $this->preview($user, $collection);
        $candidates = $preview['importable'];
        $selected ??= array_keys(array_filter(
            $candidates,
            fn (array $candidate): bool => $candidate['default_selected'],
        ));
        $selected = array_values(array_unique($selected));
        $tenantId = $fusion->tenantId($tenantKey);

        return DB::transaction(function () use ($user, $candidates, $selected, $tenantKey, $tenantId): array {
            // Lock the user's library while the duplicate check and inserts are
            // performed, preventing partial or repeated imports in one request.
            $existing = $this->existingFingerprints($user, lock: true);
            $created = 0;
            $skippedExisting = 0;
            $skippedInvalidSelection = 0;

            foreach ($selected as $index) {
                $candidate = $candidates[$index] ?? null;

                if (! is_array($candidate)) {
                    $skippedInvalidSelection++;

                    continue;
                }

                $fingerprint = $this->fingerprint($candidate['resource_path'], $candidate['parameters']);

                if (isset($existing[$fingerprint])) {
                    $skippedExisting++;

                    continue;
                }

                $query = $user->queries()->create([
                    'name' => $candidate['name'],
                    'description' => null,
                    'resource_path' => $candidate['resource_path'],
                    'mode' => 'single',
                    'access_level' => QueryAccessLevel::PRIVATE,
                    'tenant_key' => $tenantKey,
                    'oracle_tenant_id' => $tenantId,
                    'parameters' => $candidate['parameters'],
                ]);

                $lineageDefinition = $candidate['parameters'];

                if (is_string($candidate['semantic_resource_key'])) {
                    $lineageDefinition['resource_key'] = $candidate['semantic_resource_key'];
                }

                $this->lineage->syncQuery($query, $lineageDefinition);
                $existing[$fingerprint] = true;
                $created++;
            }

            $this->audit->record($user, 'query.collection_imported', $user, [
                'candidates_count' => count($candidates),
                'selected_count' => count($selected),
                'created_count' => $created,
                'skipped_existing_count' => $skippedExisting,
                'skipped_invalid_selection_count' => $skippedInvalidSelection,
            ]);

            return [
                'created' => $created,
                'skipped_existing' => $skippedExisting,
                'skipped_invalid_selection' => $skippedInvalidSelection,
                'selected' => count($selected),
            ];
        });
    }

    /**
     * @return array<string, true>
     */
    private function existingFingerprints(User $user, bool $lock = false): array
    {
        $query = Query::query()
            ->where('user_id', $user->id)
            ->where('mode', 'single')
            ->whereNotNull('resource_path')
            ->select(['id', 'resource_path', 'parameters']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $fingerprints = [];

        foreach ($query->get() as $savedQuery) {
            $fingerprints[$this->fingerprint(
                (string) $savedQuery->resource_path,
                $savedQuery->parameters ?? [],
            )] = true;
        }

        return $fingerprints;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function fingerprint(string $path, array $parameters): string
    {
        ksort($parameters);

        return hash('sha256', json_encode(
            [$path, $parameters],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}

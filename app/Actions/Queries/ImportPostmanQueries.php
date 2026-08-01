<?php

namespace App\Actions\Queries;

use App\Enums\OracleExecutionPolicy;
use App\Enums\QueryAccessLevel;
use App\Models\Query;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\PostmanCollectionImporter;
use App\Services\SemanticLineageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lot 12A — imports Oracle Fusion REST queries from a Postman collection.
 *
 * Two entry-points:
 *
 * - `preview()` parses the collection and enriches each candidate with an
 *   `already_imported` flag (true if an identical path+parameters combination
 *   already exists in the user's library) and a `default_selected` flag.
 *
 * - `execute()` creates the selected queries for the chosen tenant, skipping
 *   any duplicate that already exists (idempotent).
 */
class ImportPostmanQueries
{
    public function __construct(
        private readonly PostmanCollectionImporter $importer,
        private readonly FusionManager $fusion,
        private readonly SemanticLineageService $lineage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Parse and annotate a Postman collection for the preview dialog.
     *
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
                (array) $candidate['parameters'],
            )]);

            $result['importable'][$index]['already_imported'] = $alreadyImported;
            $result['importable'][$index]['default_selected'] = ! $alreadyImported
                && ! $candidate['tenant_specific_path'];
        }

        return $result;
    }

    /**
     * Persist the selected candidates as private queries for the given tenant.
     *
     * @param  array<string, mixed>  $collection
     * @param  list<int>|null  $selected  null = use the recommended selection
     * @return array{created: int, skipped_existing: int, skipped_invalid_selection: int, selected: int}
     */
    public function execute(User $user, array $collection, ?array $selected, string $tenantKey): array
    {
        $fusion = $this->fusion->forUser($user);

        if (! $fusion->has($tenantKey)) {
            throw ValidationException::withMessages([
                'tenant_key' => __('L\'environnement Oracle choisi n\'est pas disponible.'),
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

                $fingerprint = $this->fingerprint(
                    $candidate['resource_path'],
                    (array) $candidate['parameters'],
                );

                if (isset($existing[$fingerprint])) {
                    $skippedExisting++;

                    continue;
                }

                // Build a readable name — prefix with folder when present.
                $name = $candidate['name'];

                if (isset($candidate['folder']) && $candidate['folder'] !== '') {
                    $name = Str::limit((string) $candidate['folder'], 40, '').' / '.$name;
                }

                $name = Str::limit($name, 255, '');

                $query = $user->queries()->create([
                    'name' => $name,
                    'description' => null,
                    'resource_path' => $candidate['resource_path'],
                    'mode' => 'single',
                    'access_level' => QueryAccessLevel::PRIVATE,
                    'execution_policy' => OracleExecutionPolicy::BEST_EFFORT,
                    'tenant_key' => $tenantKey,
                    'oracle_tenant_id' => $tenantId,
                    'parameters' => $candidate['parameters'] !== [] ? $candidate['parameters'] : null,
                ]);

                $lineageDefinition = (array) $candidate['parameters'];

                if (is_string($candidate['semantic_resource_key'] ?? null)) {
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
     * Build a map of import-fingerprint => true for all existing queries
     * belonging to the given user so we can detect duplicates cheaply.
     *
     * @return array<string, true>
     */
    private function existingFingerprints(User $user, bool $lock = false): array
    {
        $query = Query::query()
            ->where('user_id', $user->id)
            ->whereNotNull('resource_path')
            ->select(['resource_path', 'parameters']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $fingerprints = [];

        foreach ($query->get() as $savedQuery) {
            $fingerprints[$this->fingerprint(
                (string) $savedQuery->resource_path,
                is_array($savedQuery->parameters) ? $savedQuery->parameters : [],
            )] = true;
        }

        return $fingerprints;
    }

    /**
     * Deterministic fingerprint matching the one used by PostmanCollectionImporter.
     *
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

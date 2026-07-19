<?php

namespace App\Services;

use App\Enums\DataQualityAssertionType;
use App\Enums\DataQualityHealthStatus;
use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use App\Enums\OracleExecutionPolicy;
use App\Enums\OracleSchemaImpactSeverity;
use App\Enums\OracleSchemaImpactStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Enums\ReferenceScenarioType;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateValidationRun;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class QueryTemplateQualityService
{
    public function __construct(
        private readonly FusionManager $fusion,
        private readonly QueryTemplateParameterBinder $binder,
        private readonly OracleQueryTool $tool,
        private readonly QueryExecutionRecorder $executions,
        private readonly QueryTemplateRuntimeFactory $runtimeFactory,
        private readonly DataQualityAssertionEvaluator $assertions,
        private readonly DatasetEquivalenceComparator $comparator,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Execute one exact version and persist immutable, value-free evidence.
     *
     * @param  array<string, mixed>  $parameterValues
     */
    public function run(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        string $tenantKey,
        array $parameterValues,
        DataQualityRunPurpose $purpose,
        ?QueryTemplateReferenceDataset $reference = null,
    ): QueryTemplateValidationRun {
        Gate::forUser($actor)->authorize('runQualityValidation', [$template, $version]);
        $this->assertVersionContext($template, $version, $purpose);
        $execution = $this->executeVersion(
            $template,
            $version,
            $actor,
            $tenantKey,
            $parameterValues,
        );
        $parameterHash = $this->parameterHash($template, $execution['values']);
        $referenceComparison = null;
        $referenceErrorCode = null;

        if ($reference !== null) {
            $this->assertReferenceContext(
                $reference,
                $template,
                $version,
                $execution['query_execution'],
                $parameterHash,
            );

            if ($execution['error_code'] === null) {
                try {
                    $referenceComparison = $this->comparator->compareProfile(
                        $reference->fingerprint,
                        $execution['rows'],
                        $reference->comparison_config,
                    );
                } catch (InvalidArgumentException) {
                    $referenceErrorCode = 'reference_profile_invalid';
                }
            }
        }

        $rules = $this->applicableRules($version->quality_rules ?? [], $reference?->id);
        $evaluation = match (true) {
            $execution['error_code'] !== null => $this->errorEvaluation(
                $version->quality_rules ?? [],
                $execution['error_code'],
            ),
            $referenceErrorCode !== null => $this->errorEvaluation(
                $version->quality_rules ?? [],
                $referenceErrorCode,
            ),
            default => $this->assertions->evaluate(
                $rules,
                $execution['rows'],
                $execution['duration_ms'],
                $reference === null ? [] : [$reference->id => $referenceComparison],
            ),
        };
        $errorCode = $execution['error_code'] ?? $referenceErrorCode;
        $run = $this->persistRun(
            $template,
            $version,
            $actor,
            $purpose,
            $execution['query_execution'],
            $reference,
            $parameterHash,
            $evaluation,
            $execution['duration_ms'],
            count($execution['rows']),
            $errorCode,
            $execution['started_at'],
        );

        $this->audit->record($actor, 'query_template.quality_validated', $template, [
            'query_template_version_id' => $version->id,
            'query_template_validation_run_id' => $run->id,
            'query_template_reference_dataset_id' => $reference?->id,
            'query_execution_id' => $execution['query_execution']->id,
            'purpose' => $purpose->value,
            'status' => $run->status->value,
            'score' => $run->score === null ? null : (float) $run->score,
            'duration_ms' => $run->duration_ms,
            'row_count' => $run->row_count,
            'parameter_hash' => $parameterHash,
            'rules_hash' => $run->rules_hash,
        ]);

        return $run;
    }

    /**
     * Capture a safe HMAC profile for one draft scenario.
     *
     * @param  array<string, mixed>  $parameterValues
     * @param  array<string, mixed>  $comparisonConfig
     */
    public function captureReference(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        string $name,
        ReferenceScenarioType $scenario,
        string $tenantKey,
        array $parameterValues,
        array $comparisonConfig,
    ): QueryTemplateReferenceDataset {
        Gate::forUser($actor)->authorize('captureQualityReference', [$template, $version]);

        if ($version->status !== QueryTemplateVersionStatus::DRAFT) {
            throw ValidationException::withMessages([
                'reference_dataset' => __('Un jeu de référence ne peut être capturé que pour un brouillon.'),
            ]);
        }

        $expectedContentHash = $version->content_hash;
        $execution = $this->executeVersion(
            $template,
            $version,
            $actor,
            $tenantKey,
            $parameterValues,
        );

        if ($execution['error_code'] !== null) {
            throw ValidationException::withMessages([
                'reference_dataset' => __('Oracle doit retourner un résultat exploitable avant la capture du jeu de référence.'),
            ]);
        }

        $comparisonConfig = $this->comparator->comparisonConfig($comparisonConfig);
        $profile = $this->comparator->profile($execution['rows'], $comparisonConfig);
        $parameterHash = $this->parameterHash($template, $execution['values']);
        $parameterKeys = array_keys($execution['values']);
        sort($parameterKeys, SORT_STRING);
        $queryExecution = $execution['query_execution'];
        $reference = DB::transaction(function () use (
            $template,
            $version,
            $actor,
            $name,
            $scenario,
            $parameterHash,
            $parameterKeys,
            $comparisonConfig,
            $profile,
            $execution,
            $queryExecution,
            $expectedContentHash,
        ): QueryTemplateReferenceDataset {
            $lockedVersion = QueryTemplateVersion::query()
                ->whereKey($version->id)
                ->where('query_template_id', $template->id)
                ->lockForUpdate()
                ->first();

            if ($lockedVersion === null
                || $lockedVersion->status !== QueryTemplateVersionStatus::DRAFT
                || ! hash_equals($expectedContentHash, $lockedVersion->content_hash)) {
                throw ValidationException::withMessages([
                    'reference_dataset' => __('Le brouillon a changé pendant la capture. Relancez le scénario avec sa version actuelle.'),
                ]);
            }

            return QueryTemplateReferenceDataset::query()->create([
                'query_template_id' => $template->id,
                'query_template_version_id' => $lockedVersion->id,
                'oracle_tenant_id' => $queryExecution->oracle_tenant_id,
                'auth_connection_id' => $queryExecution->auth_connection_id,
                'captured_by_user_id' => $actor->id,
                'name' => $name,
                'scenario' => $scenario,
                'parameter_hash' => $parameterHash,
                'parameter_keys' => $parameterKeys,
                'comparison_config' => $comparisonConfig,
                'fingerprint' => $profile,
                'row_count' => count($execution['rows']),
                'dataset_hash' => (string) $profile['dataset_hash'],
                'captured_at' => now(),
            ]);
        });

        $this->audit->record($actor, 'query_template.reference_captured', $template, [
            'query_template_version_id' => $version->id,
            'query_template_reference_dataset_id' => $reference->id,
            'query_execution_id' => $queryExecution->id,
            'scenario' => $scenario->value,
            'row_count' => $reference->row_count,
            'dataset_hash' => $reference->dataset_hash,
            'parameter_hash' => $parameterHash,
        ]);

        return $reference;
    }

    /**
     * Evaluate an already recorded public template execution opportunistically.
     * Reference assertions are intentionally excluded unless the run explicitly
     * selected the matching governed scenario.
     *
     * @param  array<string, mixed>  $parameterValues
     * @param  array<string, mixed>  $payload
     */
    public function observeExecution(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        QueryExecution $execution,
        array $parameterValues,
        array $payload,
    ): ?QueryTemplateValidationRun {
        $rules = $this->applicableRules($version->quality_rules ?? [], null);

        if ($rules === []) {
            return null;
        }

        $rows = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
        $errorCode = ($payload['error'] ?? null) === null ? null : 'oracle_error';
        $evaluation = $errorCode === null
            ? $this->assertions->evaluate($rules, $rows, $execution->duration_ms, [])
            : $this->errorEvaluation($version->quality_rules ?? [], $errorCode);

        return $this->persistRun(
            $template,
            $version,
            $actor,
            DataQualityRunPurpose::Monitoring,
            $execution,
            null,
            $this->parameterHash($template, $parameterValues),
            $evaluation,
            $execution->duration_ms,
            count($rows),
            $errorCode,
            $execution->started_at,
        );
    }

    /**
     * @param  array<string, mixed>  $parameterValues
     * @return array{
     *     query_execution: QueryExecution,
     *     values: array<string, mixed>,
     *     rows: list<array<array-key, mixed>>,
     *     duration_ms: int,
     *     error_code: string|null,
     *     started_at: CarbonInterface
     * }
     */
    private function executeVersion(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        string $tenantKey,
        array $parameterValues,
    ): array {
        $fusion = $this->fusion->forUser($actor);

        if (! $fusion->has($tenantKey)) {
            throw ValidationException::withMessages([
                'tenant' => __('Cette connexion Oracle n’est pas active ou ne vous appartient pas.'),
            ]);
        }

        $runtimeTemplate = $this->runtimeFactory->fromVersion($template, $version);

        try {
            $bound = $this->binder->bind($runtimeTemplate, $parameterValues);
            $toolQuery = $this->binder->toToolQuery($bound['parameters']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'parameter_values' => $exception->getMessage(),
            ]);
        }

        $startedAt = now();
        $startedAtNs = hrtime(true);
        $rows = [];
        $errorCode = null;
        $payload = [
            'items' => [],
            'error' => null,
        ];

        try {
            $result = $this->tool
                ->forUser($actor)
                ->run($tenantKey, $toolQuery, OracleExecutionPolicy::EXACT);
            $this->assertFilterWasApplied($toolQuery, $result['params']);
            $rows = array_values(array_filter(
                $result['items'],
                fn (mixed $row): bool => is_array($row),
            ));
            $payload['items'] = $rows;
        } catch (InvalidArgumentException|RuntimeException) {
            $errorCode = 'oracle_error';
            $payload['error'] = __('La validation Oracle a échoué.');
        }

        $durationMs = max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000));
        $queryExecution = $this->executions->recordTemplateAction(
            $actor,
            $template,
            $version->id,
            $tenantKey,
            $payload,
            $startedAt,
            $durationMs,
            QueryExecution::PURPOSE_QUALITY_VALIDATION,
        );

        return [
            'query_execution' => $queryExecution,
            'values' => $bound['values'],
            'rows' => $rows,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function persistRun(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        DataQualityRunPurpose $purpose,
        QueryExecution $execution,
        ?QueryTemplateReferenceDataset $reference,
        string $parameterHash,
        array $evaluation,
        int $durationMs,
        int $rowCount,
        ?string $errorCode,
        CarbonInterface $startedAt,
    ): QueryTemplateValidationRun {
        return DB::transaction(function () use (
            $template,
            $version,
            $actor,
            $purpose,
            $execution,
            $reference,
            $parameterHash,
            $evaluation,
            $durationMs,
            $rowCount,
            $errorCode,
            $startedAt,
        ): QueryTemplateValidationRun {
            $lockedTemplate = QueryTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedVersion = QueryTemplateVersion::query()
                ->whereKey($version->id)
                ->where('query_template_id', $lockedTemplate->id)
                ->lockForUpdate()
                ->first();

            if ($lockedVersion === null
                || ! hash_equals($version->content_hash, $lockedVersion->content_hash)) {
                throw ValidationException::withMessages([
                    'quality_validation' => __('La version a changé pendant la validation. Relancez le contrôle.'),
                ]);
            }

            $run = QueryTemplateValidationRun::query()->create([
                'query_template_id' => $template->id,
                'query_template_version_id' => $lockedVersion->id,
                'query_execution_id' => $execution->id,
                'query_template_reference_dataset_id' => $reference?->id,
                'oracle_tenant_id' => $execution->oracle_tenant_id,
                'auth_connection_id' => $execution->auth_connection_id,
                'run_by_user_id' => $actor->id,
                'purpose' => $purpose,
                'status' => DataQualityRunStatus::from((string) $evaluation['status']),
                'version_content_hash' => $lockedVersion->content_hash,
                'rules_hash' => QueryTemplateVersion::qualityRulesHash($lockedVersion->quality_rules ?? []),
                'parameter_hash' => $parameterHash,
                'assertion_results' => $evaluation['assertions'] ?? [],
                'score' => $evaluation['score'] ?? 0,
                'duration_ms' => $durationMs,
                'row_count' => $rowCount,
                'error_code' => $errorCode,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            if ($lockedTemplate->published_version_id === $lockedVersion->id) {
                $this->refreshHealthProjection($lockedTemplate, $lockedVersion, $run);
            }

            return $run;
        });
    }

    private function refreshHealthProjection(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        QueryTemplateValidationRun $latest,
    ): void {
        $runs = $version->validationRuns()
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['status', 'score']);
        $failureStreak = 0;

        foreach ($runs as $run) {
            if ($run->status === DataQualityRunStatus::Passed) {
                break;
            }

            $failureStreak++;
        }

        $score = $runs->isEmpty()
            ? (float) ($latest->score ?? 0)
            : round((float) $runs->avg(fn (QueryTemplateValidationRun $run): float => (float) ($run->score ?? 0)), 2);
        $openImpacts = $version->schemaImpacts()
            ->where('oracle_tenant_id', $latest->oracle_tenant_id)
            ->whereIn('status', [
                OracleSchemaImpactStatus::Open->value,
                OracleSchemaImpactStatus::Acknowledged->value,
            ])
            ->get(['severity']);
        $hasBreakingImpact = $openImpacts->contains(
            fn ($impact): bool => $impact->severity === OracleSchemaImpactSeverity::Breaking,
        );
        $hasReviewImpact = $openImpacts->isNotEmpty();
        $status = match (true) {
            $latest->status !== DataQualityRunStatus::Passed,
            $failureStreak >= 2,
            $hasBreakingImpact => DataQualityHealthStatus::Failing,
            $score < 90,
            $hasReviewImpact => DataQualityHealthStatus::Degraded,
            default => DataQualityHealthStatus::Healthy,
        };

        $template->applyGovernanceProjection([
            'quality_status' => $status,
            'quality_score' => $score,
            'quality_checked_at' => $latest->finished_at,
            'quality_failure_streak' => $failureStreak,
            'latest_quality_run_id' => $latest->id,
        ]);
    }

    private function assertVersionContext(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        DataQualityRunPurpose $purpose,
    ): void {
        abort_unless($version->belongsToTemplate($template), 404);

        if ($purpose === DataQualityRunPurpose::PrePublication
            && $version->status !== QueryTemplateVersionStatus::REVIEW) {
            throw ValidationException::withMessages([
                'purpose' => __('La validation avant publication exige une version en revue.'),
            ]);
        }

        if ($purpose === DataQualityRunPurpose::Monitoring
            && $version->status !== QueryTemplateVersionStatus::PUBLISHED) {
            throw ValidationException::withMessages([
                'purpose' => __('La surveillance exige une version publiée.'),
            ]);
        }
    }

    private function assertReferenceContext(
        QueryTemplateReferenceDataset $reference,
        QueryTemplate $template,
        QueryTemplateVersion $version,
        QueryExecution $execution,
        string $parameterHash,
    ): void {
        if ($reference->query_template_id !== $template->id
            || $reference->query_template_version_id !== $version->id
            || $reference->oracle_tenant_id === null
            || $reference->oracle_tenant_id !== $execution->oracle_tenant_id
            || ($reference->auth_connection_id !== null
                && $reference->auth_connection_id !== $execution->auth_connection_id)
            || ! hash_equals($reference->parameter_hash, $parameterHash)) {
            throw ValidationException::withMessages([
                'reference_dataset_id' => __('Le jeu de référence ne correspond pas au tenant, à la version et aux paramètres exécutés.'),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function applicableRules(array $rules, ?int $referenceId): array
    {
        return array_values(array_filter($rules, function (array $rule) use ($referenceId): bool {
            if (($rule['enabled'] ?? true) !== true) {
                return false;
            }

            if (($rule['type'] ?? null) !== DataQualityAssertionType::ReferenceEquivalence->value) {
                return true;
            }

            return $referenceId !== null
                && (int) ($rule['config']['reference_dataset_id'] ?? 0) === $referenceId;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function errorEvaluation(array $rules, string $code): array
    {
        return [
            'status' => DataQualityRunStatus::Error->value,
            'score' => 0,
            'required_failed' => true,
            'rules_hash' => QueryTemplateVersion::qualityRulesHash($rules),
            'assertions' => [[
                'index' => 0,
                'type' => 'execution',
                'required' => true,
                'passed' => false,
                'code' => $code,
                'metrics' => [],
            ]],
        ];
    }

    /** @param array<string, mixed> $values */
    private function parameterHash(
        QueryTemplate $template,
        array $values,
    ): string {
        return $this->comparator->fingerprintValue([
            'query_template_id' => $template->id,
            'values' => $values,
        ], 'template-parameters');
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertFilterWasApplied(array $expected, array $actual): void
    {
        $filter = trim((string) ($expected['q'] ?? ''));

        if ($filter !== '' && (! array_key_exists('q', $actual) || $actual['q'] !== $filter)) {
            throw new RuntimeException('Le filtre du modèle n’a pas été appliqué intégralement par Oracle.');
        }
    }
}

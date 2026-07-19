<?php

namespace App\Services;

use App\Enums\DataQualityAssertionType;
use App\Enums\DataQualityHealthStatus;
use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateRole;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\Category;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateRoleAssignment;
use App\Models\QueryTemplateValidationRun;
use App\Models\QueryTemplateVersion;
use App\Models\Role;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type OracleResource from OracleResourceCatalog */
class QueryTemplateGovernanceService
{
    /** @var list<string> */
    private const array PARAMETER_TYPES = ['number', 'integer', 'date', 'select', 'boolean', 'string'];

    /** @var list<string> */
    private const array FILTER_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];

    /** @var list<string> */
    private const array AUDIT_MODES = ['clear', 'masked', 'hmac', 'omit'];

    /** @var list<string> */
    private const array RUNTIME_PARAMETER_KEYS = ['limit', 'offset'];

    /** @var list<string> */
    private const array DEFINITION_PARAMETER_KEYS = [
        'resource_key',
        'fields',
        'expand',
        'joins',
        'child_fields',
        'q',
        'orderBy',
        'limit',
        'offset',
    ];

    /** @var list<string> */
    private const array PARAMETER_DEFINITION_KEYS = [
        'key', 'label', 'description', 'type', 'required', 'default', 'min', 'max',
        'step', 'options', 'audit', 'binding',
    ];

    /** @var list<string> */
    private const array PARAMETER_BINDING_KEYS = ['kind', 'field', 'operator', 'key'];

    /** @var list<string> */
    private const array PARAMETER_AUDIT_KEYS = ['mode', 'key_version'];

    /** @var list<string> */
    private const array QUALITY_RULE_KEYS = ['id', 'name', 'type', 'enabled', 'required', 'config'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OracleResourceCatalog $catalog,
        private readonly SemanticLineageService $lineage,
    ) {}

    public function createDraft(
        QueryTemplate $template,
        User $actor,
        ?string $changeSummary = null,
        ?int $expectedTemplateLock = null,
    ): QueryTemplateVersion {
        return DB::transaction(function () use ($template, $actor, $changeSummary, $expectedTemplateLock): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);

            if ($lockedTemplate->governance_status !== QueryTemplateGovernanceStatus::PUBLISHED
                || $lockedTemplate->published_version_id === null) {
                throw new ConflictHttpException(__('Seul un modèle publié peut recevoir un nouveau brouillon.'));
            }

            Gate::forUser($actor)->authorize('createDraft', $lockedTemplate);

            if ($lockedTemplate->versions()->whereNotNull('open_slot')->exists()) {
                throw new ConflictHttpException(__('Un brouillon ou une revue est déjà ouvert pour ce modèle.'));
            }

            $published = $lockedTemplate->versions()
                ->whereKey($lockedTemplate->published_version_id)
                ->lockForUpdate()
                ->firstOrFail();
            $nextNumber = ((int) $lockedTemplate->versions()->max('version_number')) + 1;
            $definition = $published->definition;
            $translations = $published->translations;
            $qualityRules = $published->quality_rules ?? [];
            $version = $lockedTemplate->versions()->create([
                'version_number' => $nextNumber,
                'status' => QueryTemplateVersionStatus::DRAFT,
                'open_slot' => 1,
                'definition' => $definition,
                'translations' => $translations,
                'quality_rules' => $qualityRules,
                'content_hash' => QueryTemplateVersion::contentHash($definition, $translations, $qualityRules),
                'change_summary' => $this->nullableTrim($changeSummary),
                'created_by_user_id' => $actor->id,
                'lock_version' => 1,
            ]);
            $qualityRules = $this->copyReferenceDatasetsForVersion(
                $published,
                $version,
                $qualityRules,
            );
            $version->update([
                'quality_rules' => $qualityRules,
                'content_hash' => QueryTemplateVersion::contentHash($definition, $translations, $qualityRules),
            ]);
            $this->lineage->syncTemplateVersion($version, $definition);

            $lockedTemplate->applyGovernanceProjection([
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.version_drafted', $lockedTemplate, [
                'query_template_version_id' => $version->id,
                'version_number' => $version->version_number,
                'source_version_id' => $published->id,
            ]);

            return $version;
        });
    }

    /** @param list<string> $roles */
    public function syncRoles(QueryTemplate $template, User $user, User $actor, array $roles): void
    {
        DB::transaction(function () use ($template, $user, $actor, $roles): void {
            $lockedTemplate = $this->lockTemplate($template);
            Gate::forUser($actor)->authorize('manageRoles', $lockedTemplate);
            $roleNames = array_values(array_unique($roles));
            $allowed = array_map(
                fn (QueryTemplateRole $role): string => $role->value,
                QueryTemplateRole::cases(),
            );

            if (array_diff($roleNames, $allowed) !== []) {
                throw ValidationException::withMessages(['roles' => __('Un rôle éditorial est invalide.')]);
            }

            $before = QueryTemplateRoleAssignment::query()
                ->where('query_template_id', $lockedTemplate->id)
                ->where('user_id', $user->id)
                ->with('role:id,name')
                ->get()
                ->pluck('role.name')
                ->sort()
                ->values()
                ->all();
            sort($roleNames);

            if ($before === $roleNames) {
                return;
            }

            $roleModels = Role::query()->whereIn('name', $roleNames)->get()->keyBy('name');

            if ($roleModels->count() !== count($roleNames)) {
                throw ValidationException::withMessages(['roles' => __('Les rôles éditoriaux ne sont pas configurés.')]);
            }

            QueryTemplateRoleAssignment::query()
                ->where('query_template_id', $lockedTemplate->id)
                ->where('user_id', $user->id)
                ->delete();

            foreach ($roleNames as $roleName) {
                QueryTemplateRoleAssignment::query()->create([
                    'query_template_id' => $lockedTemplate->id,
                    'role_id' => $roleModels->get($roleName)->id,
                    'user_id' => $user->id,
                    'assigned_by_user_id' => $actor->id,
                ]);
            }

            $this->audit->record($actor, 'query_template.roles_synced', $lockedTemplate, [
                'target_user_id' => $user->id,
                'before_roles' => $before,
                'after_roles' => $roleNames,
            ]);
        });
    }

    public function assignTechnicalOwner(
        QueryTemplate $template,
        ?User $technicalOwner,
        User $actor,
        int $expectedTemplateLock,
    ): QueryTemplate {
        return DB::transaction(function () use ($template, $technicalOwner, $actor, $expectedTemplateLock): QueryTemplate {
            $lockedTemplate = $this->lockTemplate($template);
            Gate::forUser($actor)->authorize('assignTechnicalOwner', $lockedTemplate);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $before = $lockedTemplate->technical_owner_user_id;
            $after = $technicalOwner?->id;

            if ($before === $after) {
                return $lockedTemplate;
            }

            $lockedTemplate->applyGovernanceProjection([
                'technical_owner_user_id' => $after,
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.technical_owner_assigned', $lockedTemplate, [
                'before_user_id' => $before,
                'after_user_id' => $after,
            ]);

            return $lockedTemplate->refresh();
        });
    }

    public function restoreHistoricalVersion(
        QueryTemplate $template,
        QueryTemplateVersion $sourceVersion,
        User $actor,
        int $expectedTemplateLock,
        int $expectedVersionLock,
        ?string $changeSummary = null,
    ): QueryTemplateVersion {
        return DB::transaction(function () use (
            $template,
            $sourceVersion,
            $actor,
            $expectedTemplateLock,
            $expectedVersionLock,
            $changeSummary,
        ): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);

            if (! $lockedTemplate->isPublished()) {
                throw new ConflictHttpException(__('Seul un modèle publié peut recevoir une restauration.'));
            }

            $lockedSource = $this->lockVersion($lockedTemplate, $sourceVersion);
            $this->assertVersionLock($lockedSource, $expectedVersionLock);

            if ($lockedSource->published_at === null
                || $lockedSource->status !== QueryTemplateVersionStatus::SUPERSEDED
                || $lockedSource->id === $lockedTemplate->published_version_id) {
                throw new ConflictHttpException(__('Seule une ancienne version réellement publiée peut être restaurée.'));
            }

            if ($lockedTemplate->versions()->whereNotNull('open_slot')->exists()) {
                throw new ConflictHttpException(__('Un brouillon ou une revue est déjà ouvert pour ce modèle.'));
            }

            Gate::forUser($actor)->authorize('restoreVersion', [$lockedTemplate, $lockedSource]);

            $this->assertSnapshotHash($lockedSource);
            $this->validateSnapshot($lockedSource->definition, $lockedSource->translations);
            $nextNumber = ((int) $lockedTemplate->versions()->max('version_number')) + 1;
            $draft = $lockedTemplate->versions()->create([
                'restored_from_version_id' => $lockedSource->id,
                'version_number' => $nextNumber,
                'status' => QueryTemplateVersionStatus::DRAFT,
                'open_slot' => 1,
                'definition' => $lockedSource->definition,
                'translations' => $lockedSource->translations,
                'quality_rules' => $lockedSource->quality_rules ?? [],
                'content_hash' => $lockedSource->content_hash,
                'change_summary' => $this->nullableTrim($changeSummary),
                'created_by_user_id' => $actor->id,
                'lock_version' => 1,
            ]);
            $restoredQualityRules = $this->copyReferenceDatasetsForVersion(
                $lockedSource,
                $draft,
                $lockedSource->quality_rules ?? [],
            );
            $draft->update([
                'quality_rules' => $restoredQualityRules,
                'content_hash' => QueryTemplateVersion::contentHash(
                    $lockedSource->definition,
                    $lockedSource->translations,
                    $restoredQualityRules,
                ),
            ]);
            $this->lineage->syncTemplateVersion($draft, $lockedSource->definition);

            $lockedTemplate->applyGovernanceProjection([
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.version_restored', $lockedTemplate, [
                'query_template_version_id' => $draft->id,
                'version_number' => $draft->version_number,
                'restored_from_version_id' => $lockedSource->id,
                'restored_from_version_number' => $lockedSource->version_number,
                'source_content_hash' => $lockedSource->content_hash,
            ]);

            return $draft;
        });
    }

    public function certifyPublishedVersion(
        QueryTemplate $template,
        User $actor,
        int $expectedTemplateLock,
        int $expectedPublishedVersionId,
        ?string $publicNote = null,
    ): QueryTemplateCertification {
        return DB::transaction(function () use (
            $template,
            $actor,
            $expectedTemplateLock,
            $expectedPublishedVersionId,
            $publicNote,
        ): QueryTemplateCertification {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);

            if (! $lockedTemplate->isPublished()
                || $lockedTemplate->published_version_id !== $expectedPublishedVersionId) {
                throw new ConflictHttpException(__('La version publiée a changé. Rechargez la page.'));
            }

            Gate::forUser($actor)->authorize('certify', $lockedTemplate);

            if ($lockedTemplate->business_owner_user_id === null) {
                throw ValidationException::withMessages([
                    'business_owner_user_id' => __('Un propriétaire métier est requis avant la certification.'),
                ]);
            }

            if ($lockedTemplate->review_due_at === null || $lockedTemplate->review_due_at->isBefore(today())) {
                throw ValidationException::withMessages([
                    'review_due_at' => __('Une date de révision valide et non dépassée est requise avant la certification.'),
                ]);
            }

            if ($lockedTemplate->certifications()->whereNotNull('active_slot')->exists()) {
                throw new ConflictHttpException(__('Ce modèle possède déjà une certification active.'));
            }

            $publishedVersion = $lockedTemplate->versions()
                ->whereKey($expectedPublishedVersionId)
                ->lockForUpdate()
                ->first();

            if ($publishedVersion === null
                || $publishedVersion->status !== QueryTemplateVersionStatus::PUBLISHED
                || $publishedVersion->published_at === null) {
                throw new ConflictHttpException(__('La version ciblée n’est plus la version publiée.'));
            }

            $this->assertSnapshotHash($publishedVersion);
            $this->validateSnapshot($publishedVersion->definition, $publishedVersion->translations);
            $this->assertPublicationQualityPassed($publishedVersion);
            $note = $this->normalizePublicNote($publicNote);
            $certifiedAt = now();
            $certification = $lockedTemplate->certifications()->create([
                'query_template_version_id' => $publishedVersion->id,
                'version_content_hash' => $publishedVersion->content_hash,
                'active_slot' => QueryTemplateCertification::ACTIVE_SLOT,
                'certified_by_user_id' => $actor->id,
                'certified_at' => $certifiedAt,
                'public_note' => $note,
                'lock_version' => 1,
            ]);

            $lockedTemplate->applyGovernanceProjection([
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.certified', $lockedTemplate, [
                'query_template_certification_id' => $certification->id,
                'query_template_version_id' => $publishedVersion->id,
                'version_number' => $publishedVersion->version_number,
                'version_content_hash' => $publishedVersion->content_hash,
            ]);

            return $certification;
        });
    }

    public function revokeCertification(
        QueryTemplate $template,
        QueryTemplateCertification $certification,
        User $actor,
        int $expectedTemplateLock,
        int $expectedCertificationLock,
    ): QueryTemplateCertification {
        return DB::transaction(function () use (
            $template,
            $certification,
            $actor,
            $expectedTemplateLock,
            $expectedCertificationLock,
        ): QueryTemplateCertification {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $lockedCertification = $lockedTemplate->certifications()
                ->whereKey($certification->id)
                ->lockForUpdate()
                ->first();

            if ($lockedCertification === null) {
                abort(404);
            }

            Gate::forUser($actor)->authorize('revokeCertification', [$lockedTemplate, $lockedCertification]);

            if ($lockedCertification->lock_version !== $expectedCertificationLock) {
                throw new ConflictHttpException(__('Cette certification a été modifiée depuis son ouverture. Rechargez la page.'));
            }

            if (! $lockedCertification->isActive()
                || $lockedCertification->query_template_version_id !== $lockedTemplate->published_version_id) {
                throw new ConflictHttpException(__('Cette certification n’est plus active.'));
            }

            $this->revokeCertificationRecord(
                $lockedTemplate,
                $lockedCertification,
                $actor,
                QueryTemplateCertification::REASON_MANUAL,
            );
            $lockedTemplate->applyGovernanceProjection([
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);

            return $lockedCertification->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function updateDraft(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        array $definition,
        array $translations,
        ?string $changeSummary,
        ?int $businessOwnerUserId,
        ?DateTimeInterface $reviewDueAt,
        int $expectedTemplateLock,
        int $expectedVersionLock,
        ?array $qualityRules = null,
    ): QueryTemplateVersion {
        return DB::transaction(function () use (
            $template,
            $version,
            $actor,
            $definition,
            $translations,
            $changeSummary,
            $businessOwnerUserId,
            $reviewDueAt,
            $expectedTemplateLock,
            $expectedVersionLock,
            $qualityRules,
        ): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $lockedVersion = $this->lockVersion($lockedTemplate, $version);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $this->assertVersionLock($lockedVersion, $expectedVersionLock);

            if ($lockedVersion->status !== QueryTemplateVersionStatus::DRAFT) {
                throw new ConflictHttpException(__('Seul un brouillon peut être modifié.'));
            }

            Gate::forUser($actor)->authorize('updateDraft', [$lockedTemplate, $lockedVersion]);

            $technicalKeys = ['resource_key', 'resource_path', 'parameters', 'parameter_definitions'];
            $technicalChanges = array_values(array_filter(
                $technicalKeys,
                fn (string $key): bool => ($lockedVersion->definition[$key] ?? null) !== ($definition[$key] ?? null),
            ));

            if ($technicalChanges !== []) {
                Gate::forUser($actor)->authorize('updateTechnicalDefinition', [$lockedTemplate, $lockedVersion]);
            }

            $beforeContentHash = $lockedVersion->content_hash;
            $this->validateSnapshot($definition, $translations);
            $this->assertBusinessOwnerExists($businessOwnerUserId);
            $normalizedQualityRules = $this->validateQualityRules(
                $qualityRules ?? ($lockedVersion->quality_rules ?? []),
                $definition,
                $lockedVersion,
            );
            $contentHash = QueryTemplateVersion::contentHash(
                $definition,
                $translations,
                $normalizedQualityRules,
            );

            $lockedVersion->update([
                'definition' => $definition,
                'translations' => $translations,
                'quality_rules' => $normalizedQualityRules,
                'content_hash' => $contentHash,
                'change_summary' => $this->nullableTrim($changeSummary),
                'lock_version' => $lockedVersion->lock_version + 1,
            ]);
            $this->lineage->syncTemplateVersion($lockedVersion, $definition);
            $lockedTemplate->applyGovernanceProjection([
                'business_owner_user_id' => $businessOwnerUserId,
                'review_due_at' => $reviewDueAt,
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.version_updated', $lockedTemplate, [
                'query_template_version_id' => $lockedVersion->id,
                'version_number' => $lockedVersion->version_number,
                'business_owner_user_id' => $businessOwnerUserId,
                'review_due_at' => $reviewDueAt?->format('Y-m-d'),
                'quality_rule_count' => count($normalizedQualityRules),
                'quality_rules_hash' => QueryTemplateVersion::qualityRulesHash($normalizedQualityRules),
            ]);

            if ($technicalChanges !== []) {
                $this->audit->record($actor, 'query_template.technical_definition_updated', $lockedTemplate, [
                    'query_template_version_id' => $lockedVersion->id,
                    'changed_keys' => $technicalChanges,
                    'before_content_hash' => $beforeContentHash,
                    'after_content_hash' => $contentHash,
                    'resource_key' => (string) ($definition['resource_key'] ?? ''),
                ]);
            }

            return $lockedVersion->refresh();
        });
    }

    public function submitForReview(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        int $expectedTemplateLock,
        int $expectedVersionLock,
    ): QueryTemplateVersion {
        return DB::transaction(function () use (
            $template,
            $version,
            $actor,
            $expectedTemplateLock,
            $expectedVersionLock,
        ): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $lockedVersion = $this->lockVersion($lockedTemplate, $version);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $this->assertVersionLock($lockedVersion, $expectedVersionLock);

            if ($lockedVersion->status !== QueryTemplateVersionStatus::DRAFT) {
                throw new ConflictHttpException(__('Seul un brouillon peut être soumis en revue.'));
            }

            Gate::forUser($actor)->authorize('submitForReview', [$lockedTemplate, $lockedVersion]);

            $this->validateSnapshot($lockedVersion->definition, $lockedVersion->translations);
            $this->validateQualityRules(
                $lockedVersion->quality_rules ?? [],
                $lockedVersion->definition,
                $lockedVersion,
            );
            $this->assertSnapshotHash($lockedVersion);
            $lockedVersion->update([
                'status' => QueryTemplateVersionStatus::REVIEW,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
                'lock_version' => $lockedVersion->lock_version + 1,
            ]);
            $lockedTemplate->applyGovernanceProjection([
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.version_submitted', $lockedTemplate, [
                'query_template_version_id' => $lockedVersion->id,
                'version_number' => $lockedVersion->version_number,
                'from_status' => QueryTemplateVersionStatus::DRAFT->value,
                'to_status' => QueryTemplateVersionStatus::REVIEW->value,
            ]);

            return $lockedVersion->refresh();
        });
    }

    public function publish(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        int $expectedTemplateLock,
        int $expectedVersionLock,
    ): QueryTemplateVersion {
        return DB::transaction(function () use (
            $template,
            $version,
            $actor,
            $expectedTemplateLock,
            $expectedVersionLock,
        ): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $lockedVersion = $this->lockVersion($lockedTemplate, $version);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $this->assertVersionLock($lockedVersion, $expectedVersionLock);

            if ($lockedVersion->status !== QueryTemplateVersionStatus::REVIEW) {
                throw new ConflictHttpException(__('Seule une version en revue peut être publiée.'));
            }

            Gate::forUser($actor)->authorize('publish', [$lockedTemplate, $lockedVersion]);

            $this->validateSnapshot($lockedVersion->definition, $lockedVersion->translations);
            $this->validateQualityRules(
                $lockedVersion->quality_rules ?? [],
                $lockedVersion->definition,
                $lockedVersion,
            );
            $this->assertSnapshotHash($lockedVersion);
            $qualityRun = $this->assertPublicationQualityPassed($lockedVersion);
            $previousPublishedId = $lockedTemplate->published_version_id;

            $this->revokeActiveCertification(
                $lockedTemplate,
                $actor,
                QueryTemplateCertification::REASON_NEW_PUBLICATION,
            );

            if ($previousPublishedId !== null && $previousPublishedId !== $lockedVersion->id) {
                $previous = $lockedTemplate->versions()
                    ->whereKey($previousPublishedId)
                    ->lockForUpdate()
                    ->first();

                if ($previous !== null && $previous->status === QueryTemplateVersionStatus::PUBLISHED) {
                    $previous->update([
                        'status' => QueryTemplateVersionStatus::SUPERSEDED,
                        'lock_version' => $previous->lock_version + 1,
                    ]);
                }
            }

            $publishedAt = now();
            $lockedVersion->update([
                'status' => QueryTemplateVersionStatus::PUBLISHED,
                'open_slot' => null,
                'published_by_user_id' => $actor->id,
                'published_at' => $publishedAt,
                'lock_version' => $lockedVersion->lock_version + 1,
            ]);
            $this->projectPublishedSnapshot($lockedTemplate, $lockedVersion, $actor, $publishedAt);
            if ($qualityRun !== null) {
                $lockedTemplate->applyGovernanceProjection([
                    'quality_status' => match (true) {
                        $qualityRun->status !== DataQualityRunStatus::Passed => DataQualityHealthStatus::Failing,
                        (float) ($qualityRun->score ?? 0) < 90 => DataQualityHealthStatus::Degraded,
                        default => DataQualityHealthStatus::Healthy,
                    },
                    'quality_score' => $qualityRun->score,
                    'quality_checked_at' => $qualityRun->finished_at,
                    'quality_failure_streak' => 0,
                    'latest_quality_run_id' => $qualityRun->id,
                ]);
            }
            $this->audit->record($actor, 'query_template.version_published', $lockedTemplate, [
                'query_template_version_id' => $lockedVersion->id,
                'version_number' => $lockedVersion->version_number,
                'previous_published_version_id' => $previousPublishedId,
                'from_status' => QueryTemplateVersionStatus::REVIEW->value,
                'to_status' => QueryTemplateVersionStatus::PUBLISHED->value,
                'quality_validation_run_id' => $qualityRun?->id,
            ]);

            return $lockedVersion->refresh();
        });
    }

    public function archive(
        QueryTemplate $template,
        User $actor,
        int $expectedTemplateLock,
    ): QueryTemplate {
        return DB::transaction(function () use ($template, $actor, $expectedTemplateLock): QueryTemplate {
            $lockedTemplate = $this->lockTemplate($template);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);

            if ($lockedTemplate->governance_status !== QueryTemplateGovernanceStatus::PUBLISHED) {
                throw new ConflictHttpException(__('Seul un modèle publié peut être archivé.'));
            }

            Gate::forUser($actor)->authorize('archive', $lockedTemplate);

            $openVersions = $lockedTemplate->versions()
                ->whereNotNull('open_slot')
                ->lockForUpdate()
                ->get();

            foreach ($openVersions as $openVersion) {
                $openVersion->update([
                    'status' => QueryTemplateVersionStatus::SUPERSEDED,
                    'open_slot' => null,
                    'lock_version' => $openVersion->lock_version + 1,
                ]);
            }

            $this->revokeActiveCertification(
                $lockedTemplate,
                $actor,
                QueryTemplateCertification::REASON_TEMPLATE_ARCHIVED,
            );

            $archivedAt = now();
            $lockedTemplate->applyGovernanceProjection([
                'governance_status' => QueryTemplateGovernanceStatus::ARCHIVED,
                'is_active' => false,
                'archived_at' => $archivedAt,
                'archived_by_user_id' => $actor->id,
                'lock_version' => $lockedTemplate->lock_version + 1,
            ]);
            $this->audit->record($actor, 'query_template.archived', $lockedTemplate, [
                'published_version_id' => $lockedTemplate->published_version_id,
                'closed_open_version_count' => $openVersions->count(),
                'from_status' => QueryTemplateGovernanceStatus::PUBLISHED->value,
                'to_status' => QueryTemplateGovernanceStatus::ARCHIVED->value,
            ]);

            return $lockedTemplate->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function validateSnapshot(array $definition, array $translations): void
    {
        $errors = [];
        $resourceKey = trim((string) ($definition['resource_key'] ?? ''));
        $resourcePath = trim((string) ($definition['resource_path'] ?? ''));
        $resource = $this->catalog->find($resourceKey);
        $name = trim((string) ($definition['name'] ?? ''));
        $parameters = is_array($definition['parameters'] ?? null) ? $definition['parameters'] : [];
        $definitions = is_array($definition['parameter_definitions'] ?? null)
            ? $definition['parameter_definitions']
            : [];

        if ($name === '' || mb_strlen($name) > 255) {
            $errors['definition.name'] = __('Le nom du modèle est obligatoire et limité à 255 caractères.');
        }

        if ($resource === null) {
            $errors['definition.resource_key'] = __('La ressource Oracle du modèle n’est pas autorisée.');
        } elseif ($resourcePath !== $resource['path']) {
            $errors['definition.resource_path'] = __('Le chemin Oracle ne correspond pas à la ressource sélectionnée.');
        }

        if (($parameters['resource_key'] ?? null) !== $resourceKey) {
            $errors['definition.parameters.resource_key'] = __('La ressource des paramètres est incohérente.');
        }

        foreach (array_keys($parameters) as $parameterKey) {
            if (! in_array((string) $parameterKey, self::DEFINITION_PARAMETER_KEYS, true)) {
                $errors["definition.parameters.{$parameterKey}"] = __('Ce paramètre Oracle n’est pas autorisé.');
            }
        }

        $this->validateTechnicalParameters($parameters, $resource, $errors);

        if (isset($parameters['limit'])
            && (! is_numeric($parameters['limit']) || (int) $parameters['limit'] < 1 || (int) $parameters['limit'] > 500)) {
            $errors['definition.parameters.limit'] = __('La limite doit être comprise entre 1 et 500.');
        }

        $definitionKeys = [];

        foreach ($definitions as $index => $parameterDefinition) {
            if (! is_array($parameterDefinition)) {
                $errors["definition.parameter_definitions.{$index}"] = __('La définition du paramètre est invalide.');

                continue;
            }

            $unknownDefinitionKeys = array_diff(array_keys($parameterDefinition), self::PARAMETER_DEFINITION_KEYS);

            if ($unknownDefinitionKeys !== []) {
                $errors["definition.parameter_definitions.{$index}"] = __('La définition contient des propriétés non autorisées.');
            }

            $key = trim((string) ($parameterDefinition['key'] ?? ''));
            $type = (string) ($parameterDefinition['type'] ?? '');
            $binding = is_array($parameterDefinition['binding'] ?? null)
                ? $parameterDefinition['binding']
                : [];
            $auditMode = (string) data_get($parameterDefinition, 'audit.mode', '');

            if (array_diff(array_keys($binding), self::PARAMETER_BINDING_KEYS) !== []) {
                $errors["definition.parameter_definitions.{$index}.binding"] = __('La liaison contient des propriétés non autorisées.');
            }

            $audit = is_array($parameterDefinition['audit'] ?? null) ? $parameterDefinition['audit'] : [];

            if (array_diff(array_keys($audit), self::PARAMETER_AUDIT_KEYS) !== []) {
                $errors["definition.parameter_definitions.{$index}.audit"] = __('La politique d’audit contient des propriétés non autorisées.');
            }

            if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || in_array($key, $definitionKeys, true)) {
                $errors["definition.parameter_definitions.{$index}.key"] = __('Les clés de paramètres doivent être uniques et sûres.');
            } else {
                $definitionKeys[] = $key;
            }

            if (! in_array($type, self::PARAMETER_TYPES, true)) {
                $errors["definition.parameter_definitions.{$index}.type"] = __('Le type du paramètre n’est pas pris en charge.');
            }

            if (! in_array($auditMode, self::AUDIT_MODES, true)) {
                $errors["definition.parameter_definitions.{$index}.audit.mode"] = __('Chaque paramètre doit définir une politique d’audit autorisée.');
            }

            if ($auditMode === 'hmac' && trim((string) data_get($parameterDefinition, 'audit.key_version', '')) === '') {
                $errors["definition.parameter_definitions.{$index}.audit.key_version"] = __('Une politique HMAC doit préciser sa version de clé.');
            }

            $bindingKind = (string) ($binding['kind'] ?? '');

            if ($bindingKind === 'filter') {
                $field = (string) ($binding['field'] ?? '');
                $operator = strtoupper(trim((string) ($binding['operator'] ?? '')));

                if ($resource !== null && ! in_array($field, $resource['fields'], true)) {
                    $errors["definition.parameter_definitions.{$index}.binding.field"] = __('Le champ de filtre n’appartient pas à la ressource Oracle.');
                }

                if (! in_array($operator, self::FILTER_OPERATORS, true)) {
                    $errors["definition.parameter_definitions.{$index}.binding.operator"] = __('L’opérateur de filtre n’est pas autorisé.');
                }
            } elseif ($bindingKind === 'parameter') {
                if (! in_array((string) ($binding['key'] ?? ''), self::RUNTIME_PARAMETER_KEYS, true)) {
                    $errors["definition.parameter_definitions.{$index}.binding.key"] = __('Le paramètre d’exécution ciblé n’est pas autorisé.');
                }
            } else {
                $errors["definition.parameter_definitions.{$index}.binding.kind"] = __('Le type de liaison du paramètre n’est pas autorisé.');
            }

            if ($type === 'select') {
                $options = is_array($parameterDefinition['options'] ?? null)
                    ? $parameterDefinition['options']
                    : [];
                $optionValues = array_map(
                    fn (mixed $option): string => is_array($option) ? (string) ($option['value'] ?? '') : '',
                    $options,
                );

                if ($optionValues === [] || in_array('', $optionValues, true) || count($optionValues) !== count(array_unique($optionValues))) {
                    $errors["definition.parameter_definitions.{$index}.options"] = __('Les options de liste doivent être non vides et uniques.');
                }
            }
        }

        foreach (['fr', 'en', 'es'] as $locale) {
            $translation = $translations[$locale] ?? null;

            if (! is_array($translation) || trim((string) ($translation['name'] ?? '')) === '') {
                $errors["translations.{$locale}.name"] = __('Le nom traduit est obligatoire dans les trois langues.');
            }
        }

        $categoryId = $definition['category_id'] ?? null;

        if ($categoryId !== null && ! Category::query()->whereKey($categoryId)->exists()) {
            $errors['definition.category_id'] = __('La catégorie sélectionnée n’existe plus.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  OracleResource|null  $resource
     * @param  array<string, array<array-key, mixed>|string>  $errors
     */
    private function validateTechnicalParameters(array $parameters, ?array $resource, array &$errors): void
    {
        $fields = $this->technicalList($parameters['fields'] ?? null);
        $expand = $this->technicalList($parameters['expand'] ?? null);
        $joins = $this->technicalList($parameters['joins'] ?? null);

        foreach (['fields' => $fields, 'expand' => $expand, 'joins' => $joins] as $key => $values) {
            if ($values === null) {
                $errors["definition.parameters.{$key}"] = __('Cette sélection Oracle est invalide.');
            }
        }

        if ($resource === null || $fields === null || $expand === null || $joins === null) {
            return;
        }

        $this->assertAllowedTechnicalValues($fields, $resource['fields'], 'fields', $errors);
        $this->assertAllowedTechnicalValues($expand, $resource['child_resources'], 'expand', $errors);
        $this->assertAllowedTechnicalValues($joins, array_keys($resource['join_keys']), 'joins', $errors);

        $childFields = $parameters['child_fields'] ?? [];

        if (! is_array($childFields)) {
            $errors['definition.parameters.child_fields'] = __('Les champs enfants doivent former une table contrôlée.');
        } else {
            foreach ($childFields as $child => $selectedFields) {
                $child = (string) $child;
                $normalizedFields = $this->technicalList($selectedFields);
                $allowedFields = [];

                if (in_array($child, $expand, true)) {
                    $allowedFields = $resource['child_fields'][$child] ?? [];
                } elseif (in_array($child, $joins, true)) {
                    $allowedFields = $this->catalog->find($child)['fields'] ?? [];
                } else {
                    $errors["definition.parameters.child_fields.{$child}"] = __('Cette ressource enfant n’est pas sélectionnée.');

                    continue;
                }

                if ($normalizedFields === null || array_diff($normalizedFields, $allowedFields) !== []) {
                    $errors["definition.parameters.child_fields.{$child}"] = __('Un champ enfant n’appartient pas au catalogue Oracle.');
                }
            }
        }

        $orderBy = trim((string) ($parameters['orderBy'] ?? ''));

        if ($orderBy !== '') {
            foreach (explode(',', $orderBy) as $clause) {
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?::(asc|desc))?$/i', trim($clause), $matches) !== 1
                    || ! in_array($matches[1], $resource['fields'], true)) {
                    $errors['definition.parameters.orderBy'] = __('Le tri Oracle contient un champ ou une direction non autorisés.');
                    break;
                }
            }
        }

        $q = trim((string) ($parameters['q'] ?? ''));

        if ($q !== '' && ! $this->isSafeOracleFilter($q, $resource['fields'])) {
            $errors['definition.parameters.q'] = __('Le filtre Oracle ne respecte pas la grammaire autorisée.');
        }

        if (isset($parameters['offset'])
            && (! is_numeric($parameters['offset']) || (int) $parameters['offset'] < 0)) {
            $errors['definition.parameters.offset'] = __('Le décalage Oracle doit être un entier positif.');
        }
    }

    /** @return list<string>|null */
    private function technicalList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_string($value) ? explode(',', $value) : $value;

        if (! is_array($items)) {
            return null;
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_string($item) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($item)) !== 1) {
                return null;
            }

            $normalized[] = trim($item);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $allowed
     * @param  array<string, array<array-key, mixed>|string>  $errors
     */
    private function assertAllowedTechnicalValues(array $values, array $allowed, string $key, array &$errors): void
    {
        if (array_diff($values, $allowed) !== []) {
            $errors["definition.parameters.{$key}"] = __('Une valeur n’appartient pas au catalogue Oracle.');
        }
    }

    /** @param list<string> $allowedFields */
    private function isSafeOracleFilter(string $filter, array $allowedFields): bool
    {
        if (preg_match('/[\x00-\x1F\x7F;]/u', $filter) === 1) {
            return false;
        }

        $literal = "(?:'(?:[^']|'')*'|-?\\d+(?:\\.\\d+)?|true|false|null)";

        foreach (preg_split('/\s+AND\s+/i', $filter) ?: [] as $clause) {
            $pattern = '/^([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|=|>|<|LIKE)\s*'.$literal.'$/i';

            if (preg_match($pattern, trim($clause), $matches) !== 1
                || ! in_array($matches[1], $allowedFields, true)) {
                return false;
            }
        }

        return true;
    }

    private function lockTemplate(QueryTemplate $template): QueryTemplate
    {
        return QueryTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
    }

    private function lockVersion(QueryTemplate $template, QueryTemplateVersion $version): QueryTemplateVersion
    {
        $lockedVersion = $template->versions()->whereKey($version->id)->lockForUpdate()->first();

        if ($lockedVersion === null) {
            abort(404);
        }

        return $lockedVersion;
    }

    private function assertTemplateLock(QueryTemplate $template, ?int $expected): void
    {
        if ($expected !== null && $template->lock_version !== $expected) {
            throw new ConflictHttpException(__('Ce modèle a été modifié depuis son ouverture. Rechargez la page.'));
        }
    }

    private function assertVersionLock(QueryTemplateVersion $version, int $expected): void
    {
        if ($version->lock_version !== $expected) {
            throw new ConflictHttpException(__('Cette version a été modifiée depuis son ouverture. Rechargez la page.'));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function validateQualityRules(
        array $rules,
        array $definition,
        QueryTemplateVersion $version,
    ): array {
        if (count($rules) > 50) {
            throw ValidationException::withMessages([
                'quality_rules' => __('Un maximum de 50 assertions est autorisé par version.'),
            ]);
        }

        $normalized = [];
        $identifiers = [];
        $allowedFields = $this->qualityOutputFields($definition);

        foreach ($rules as $index => $rule) {
            if (! is_array($rule)
                || array_is_list($rule)
                || array_diff(array_keys($rule), self::QUALITY_RULE_KEYS) !== []) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}" => __('La définition de cette assertion contient des clés non autorisées.'),
                ]);
            }

            $id = trim((string) ($rule['id'] ?? ''));
            $name = trim((string) ($rule['name'] ?? ''));
            $type = DataQualityAssertionType::tryFrom((string) ($rule['type'] ?? ''));
            $config = $rule['config'] ?? [];

            if ((array_key_exists('enabled', $rule) && ! is_bool($rule['enabled']))
                || (array_key_exists('required', $rule) && ! is_bool($rule['required']))) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}" => __('Les indicateurs enabled et required doivent être booléens.'),
                ]);
            }

            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) !== 1 || isset($identifiers[$id])) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.id" => __('Chaque assertion doit avoir un identifiant unique et stable.'),
                ]);
            }

            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.name" => __('Le nom de l’assertion est requis et limité à 255 caractères.'),
                ]);
            }

            if ($type === null || ! is_array($config)) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.type" => __('Le type ou la configuration de l’assertion est invalide.'),
                ]);
            }

            $identifiers[$id] = true;
            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type->value,
                'enabled' => (bool) ($rule['enabled'] ?? true),
                'required' => (bool) ($rule['required'] ?? true),
                'config' => $this->normalizeQualityRuleConfig(
                    $type,
                    $config,
                    $allowedFields,
                    $version,
                    $index,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allowedFields
     * @return array<string, mixed>
     */
    private function normalizeQualityRuleConfig(
        DataQualityAssertionType $type,
        array $config,
        array $allowedFields,
        QueryTemplateVersion $version,
        int $index,
    ): array {
        if ($type === DataQualityAssertionType::NonEmpty) {
            return [];
        }

        if ($type === DataQualityAssertionType::RowCountRange) {
            $min = isset($config['min']) && is_numeric($config['min']) ? (int) $config['min'] : 0;
            $max = isset($config['max']) && is_numeric($config['max']) ? (int) $config['max'] : null;

            if ($min < 0 || ($max !== null && ($max < $min || $max > 1_000_000))) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.config" => __('La fourchette de lignes est invalide.'),
                ]);
            }

            return array_filter(['min' => $min, 'max' => $max], fn (mixed $value): bool => $value !== null);
        }

        if (in_array($type, [DataQualityAssertionType::Unique, DataQualityAssertionType::RequiredFields], true)) {
            $fields = $this->normalizeQualityFields($config['fields'] ?? null, $allowedFields, $index);

            return ['fields' => $fields];
        }

        if ($type === DataQualityAssertionType::AllowedValues) {
            $fields = $this->normalizeQualityFields([$config['field'] ?? null], $allowedFields, $index);
            $values = $config['values'] ?? null;

            if (! is_array($values) || $values === [] || count($values) > 100 || collect($values)->contains(
                fn (mixed $value): bool => ! is_scalar($value) && $value !== null,
            )) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.config.values" => __('La liste des valeurs autorisées est invalide.'),
                ]);
            }

            return ['field' => $fields[0], 'values' => array_values($values)];
        }

        if ($type === DataQualityAssertionType::MaxDuration) {
            $maximum = isset($config['max_ms']) && is_numeric($config['max_ms'])
                ? (int) $config['max_ms']
                : 0;

            if ($maximum < 1 || $maximum > 300_000) {
                throw ValidationException::withMessages([
                    "quality_rules.{$index}.config.max_ms" => __('Le seuil de durée doit être compris entre 1 ms et 300 000 ms.'),
                ]);
            }

            return ['max_ms' => $maximum];
        }

        $referenceId = isset($config['reference_dataset_id']) && is_numeric($config['reference_dataset_id'])
            ? (int) $config['reference_dataset_id']
            : 0;
        $referenceExists = $referenceId > 0 && QueryTemplateReferenceDataset::query()
            ->whereKey($referenceId)
            ->where('query_template_id', $version->query_template_id)
            ->where('query_template_version_id', $version->id)
            ->exists();

        if (! $referenceExists) {
            throw ValidationException::withMessages([
                "quality_rules.{$index}.config.reference_dataset_id" => __('Le jeu de référence doit appartenir à cette version.'),
            ]);
        }

        return ['reference_dataset_id' => $referenceId];
    }

    /**
     * @param  list<string>  $allowedFields
     * @return list<string>
     */
    private function normalizeQualityFields(mixed $value, array $allowedFields, int $index): array
    {
        if (! is_array($value)) {
            $value = [];
        }

        $fields = array_values(array_unique(array_map(
            fn (mixed $field): string => trim((string) $field),
            $value,
        )));

        if ($fields === [] || count($fields) > 20 || array_diff($fields, $allowedFields) !== []) {
            throw ValidationException::withMessages([
                "quality_rules.{$index}.config.fields" => __('Les champs de l’assertion doivent provenir de la projection Oracle autorisée.'),
            ]);
        }

        return $fields;
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function qualityOutputFields(array $definition): array
    {
        $parameters = is_array($definition['parameters'] ?? null) ? $definition['parameters'] : [];
        $fields = $this->normalizeStringList($parameters['fields'] ?? []);
        $childFields = is_array($parameters['child_fields'] ?? null) ? $parameters['child_fields'] : [];

        foreach ($childFields as $child => $children) {
            foreach ($this->normalizeStringList($children) as $field) {
                $fields[] = (string) $child.'.'.$field;
            }
        }

        if ($fields === []) {
            $resource = $this->catalog->find((string) ($definition['resource_key'] ?? ''));
            $fields = is_array($resource['fields'] ?? null) ? $resource['fields'] : [];
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        )));
    }

    private function assertPublicationQualityPassed(
        QueryTemplateVersion $version,
    ): ?QueryTemplateValidationRun {
        $rules = array_values(array_filter(
            $version->quality_rules ?? [],
            fn (array $rule): bool => ($rule['enabled'] ?? true) === true,
        ));

        if ($rules === []) {
            return null;
        }

        $referenceIds = collect($rules)
            ->filter(fn (array $rule): bool => ($rule['type'] ?? null) === DataQualityAssertionType::ReferenceEquivalence->value)
            ->map(fn (array $rule): int => (int) ($rule['config']['reference_dataset_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();
        $requiredReferenceIds = $referenceIds->isEmpty() ? collect([null]) : $referenceIds;
        $runs = collect();

        foreach ($requiredReferenceIds as $referenceId) {
            $run = $version->validationRuns()
                ->where('purpose', DataQualityRunPurpose::PrePublication->value)
                ->where('status', DataQualityRunStatus::Passed->value)
                ->where('version_content_hash', $version->content_hash)
                ->where('rules_hash', QueryTemplateVersion::qualityRulesHash($version->quality_rules ?? []))
                ->when(
                    $referenceId === null,
                    fn ($query) => $query->whereNull('query_template_reference_dataset_id'),
                    fn ($query) => $query->where('query_template_reference_dataset_id', $referenceId),
                )
                ->when(
                    $version->submitted_at !== null,
                    fn ($query) => $query->where('finished_at', '>=', $version->submitted_at),
                )
                ->latest('finished_at')
                ->first();

            if ($run === null) {
                throw ValidationException::withMessages([
                    'quality_validation' => __('Une validation de données réussie et à jour est requise pour chaque scénario avant la publication ou la certification.'),
                ]);
            }

            $runs->push($run);
        }

        return $runs->sortByDesc('finished_at')->first();
    }

    /**
     * Copy value-free reference profiles when a published snapshot becomes a
     * new draft, then rewrite only the technical identifiers in its rules.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function copyReferenceDatasetsForVersion(
        QueryTemplateVersion $source,
        QueryTemplateVersion $target,
        array $rules,
    ): array {
        $referenceIds = collect($rules)
            ->filter(fn (array $rule): bool => ($rule['type'] ?? null) === DataQualityAssertionType::ReferenceEquivalence->value)
            ->map(fn (array $rule): int => (int) ($rule['config']['reference_dataset_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($referenceIds->isEmpty()) {
            return $rules;
        }

        $sources = $source->referenceDatasets()
            ->whereIn('id', $referenceIds)
            ->get()
            ->keyBy('id');

        if ($sources->count() !== $referenceIds->count()) {
            throw new ConflictHttpException(__('Un jeu de référence de la version source est introuvable.'));
        }

        $mapping = [];
        foreach ($sources as $sourceReference) {
            $copy = $sourceReference->replicate();
            $copy->query_template_version_id = $target->id;
            $copy->save();
            $mapping[$sourceReference->id] = $copy->id;
        }

        return array_map(function (array $rule) use ($mapping): array {
            if (($rule['type'] ?? null) !== DataQualityAssertionType::ReferenceEquivalence->value) {
                return $rule;
            }

            $sourceId = (int) ($rule['config']['reference_dataset_id'] ?? 0);
            $rule['config']['reference_dataset_id'] = $mapping[$sourceId];

            return $rule;
        }, $rules);
    }

    private function assertSnapshotHash(QueryTemplateVersion $version): void
    {
        $computedHash = QueryTemplateVersion::contentHash(
            $version->definition,
            $version->translations,
            $version->quality_rules ?? [],
        );

        if (! hash_equals($version->content_hash, $computedHash)) {
            throw new ConflictHttpException(__('Le contenu de cette version ne correspond plus à son empreinte.'));
        }
    }

    private function normalizePublicNote(?string $note): ?string
    {
        $normalized = $this->nullableTrim($note);

        if ($normalized === null) {
            return null;
        }

        if (mb_strlen($normalized) > 500) {
            throw ValidationException::withMessages([
                'public_note' => __('La note publique est limitée à 500 caractères.'),
            ]);
        }

        if (strip_tags($normalized) !== $normalized) {
            throw ValidationException::withMessages([
                'public_note' => __('La note publique doit être du texte brut sans HTML.'),
            ]);
        }

        return $normalized;
    }

    private function revokeActiveCertification(
        QueryTemplate $template,
        User $actor,
        string $reason,
    ): ?QueryTemplateCertification {
        $certification = $template->certifications()
            ->where('active_slot', QueryTemplateCertification::ACTIVE_SLOT)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        if ($certification === null) {
            return null;
        }

        $this->revokeCertificationRecord($template, $certification, $actor, $reason);

        return $certification;
    }

    private function revokeCertificationRecord(
        QueryTemplate $template,
        QueryTemplateCertification $certification,
        User $actor,
        string $reason,
    ): void {
        if (! in_array($reason, QueryTemplateCertification::REVOCATION_REASONS, true)) {
            throw new \InvalidArgumentException('Motif de révocation de certification invalide.');
        }

        if ($certification->query_template_id !== $template->id || ! $certification->isActive()) {
            throw new ConflictHttpException(__('Cette certification n’est plus active.'));
        }

        $certification->update([
            'active_slot' => null,
            'revoked_by_user_id' => $actor->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'lock_version' => $certification->lock_version + 1,
        ]);
        $this->audit->record($actor, 'query_template.certification_revoked', $template, [
            'query_template_certification_id' => $certification->id,
            'query_template_version_id' => $certification->query_template_version_id,
            'version_content_hash' => $certification->version_content_hash,
            'reason' => $reason,
        ]);
    }

    private function assertBusinessOwnerExists(?int $userId): void
    {
        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages([
                'business_owner_user_id' => __('Le propriétaire métier sélectionné n’existe plus.'),
            ]);
        }
    }

    private function projectPublishedSnapshot(
        QueryTemplate $template,
        QueryTemplateVersion $version,
        User $actor,
        DateTimeInterface $publishedAt,
    ): void {
        $definition = $version->definition;

        DB::table('query_template_translations')
            ->where('query_template_id', $template->id)
            ->delete();

        foreach ($version->translations as $locale => $translation) {
            DB::table('query_template_translations')->insert([
                'query_template_id' => $template->id,
                'locale' => $locale,
                'name' => (string) ($translation['name'] ?? ''),
                'description' => $this->nullableTrim($translation['description'] ?? null),
                'parameter_labels' => $this->jsonOrNull($translation['parameter_labels'] ?? []),
                'parameter_descriptions' => $this->jsonOrNull($translation['parameter_descriptions'] ?? []),
                'parameter_options' => $this->jsonOrNull($translation['parameter_options'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $template->applyGovernanceProjection([
            'name' => (string) $definition['name'],
            'description' => $this->nullableTrim($definition['description'] ?? null),
            'category_id' => $definition['category_id'] ?? null,
            'resource_key' => (string) $definition['resource_key'],
            'resource_path' => (string) $definition['resource_path'],
            'parameters' => $definition['parameters'],
            'parameter_definitions' => $definition['parameter_definitions'],
            'sort_order' => (int) ($definition['sort_order'] ?? 0),
            'is_active' => true,
            'governance_status' => QueryTemplateGovernanceStatus::PUBLISHED,
            'published_version_id' => $version->id,
            'published_at' => $publishedAt,
            'published_by_user_id' => $actor->id,
            'archived_at' => null,
            'archived_by_user_id' => null,
            'quality_status' => DataQualityHealthStatus::Unknown,
            'quality_score' => null,
            'quality_checked_at' => null,
            'quality_failure_streak' => 0,
            'latest_quality_run_id' => null,
            'lock_version' => $template->lock_version + 1,
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

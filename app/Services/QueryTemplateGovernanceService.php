<?php

namespace App\Services;

use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\Category;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OracleResourceCatalog $catalog,
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
            $version = $lockedTemplate->versions()->create([
                'version_number' => $nextNumber,
                'status' => QueryTemplateVersionStatus::DRAFT,
                'open_slot' => 1,
                'definition' => $definition,
                'translations' => $translations,
                'content_hash' => QueryTemplateVersion::contentHash($definition, $translations),
                'change_summary' => $this->nullableTrim($changeSummary),
                'created_by_user_id' => $actor->id,
                'lock_version' => 1,
            ]);

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
                'content_hash' => $lockedSource->content_hash,
                'change_summary' => $this->nullableTrim($changeSummary),
                'created_by_user_id' => $actor->id,
                'lock_version' => 1,
            ]);

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
        ): QueryTemplateVersion {
            $lockedTemplate = $this->lockTemplate($template);
            $lockedVersion = $this->lockVersion($lockedTemplate, $version);
            $this->assertTemplateLock($lockedTemplate, $expectedTemplateLock);
            $this->assertVersionLock($lockedVersion, $expectedVersionLock);

            if ($lockedVersion->status !== QueryTemplateVersionStatus::DRAFT) {
                throw new ConflictHttpException(__('Seul un brouillon peut être modifié.'));
            }

            $this->validateSnapshot($definition, $translations);
            $this->assertBusinessOwnerExists($businessOwnerUserId);
            $contentHash = QueryTemplateVersion::contentHash($definition, $translations);

            $lockedVersion->update([
                'definition' => $definition,
                'translations' => $translations,
                'content_hash' => $contentHash,
                'change_summary' => $this->nullableTrim($changeSummary),
                'lock_version' => $lockedVersion->lock_version + 1,
            ]);
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
            ]);

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

            $this->validateSnapshot($lockedVersion->definition, $lockedVersion->translations);
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

            $this->validateSnapshot($lockedVersion->definition, $lockedVersion->translations);
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
            $this->audit->record($actor, 'query_template.version_published', $lockedTemplate, [
                'query_template_version_id' => $lockedVersion->id,
                'version_number' => $lockedVersion->version_number,
                'previous_published_version_id' => $previousPublishedId,
                'from_status' => QueryTemplateVersionStatus::REVIEW->value,
                'to_status' => QueryTemplateVersionStatus::PUBLISHED->value,
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

            $key = trim((string) ($parameterDefinition['key'] ?? ''));
            $type = (string) ($parameterDefinition['type'] ?? '');
            $binding = is_array($parameterDefinition['binding'] ?? null)
                ? $parameterDefinition['binding']
                : [];
            $auditMode = (string) data_get($parameterDefinition, 'audit.mode', '');

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

    private function assertSnapshotHash(QueryTemplateVersion $version): void
    {
        $computedHash = QueryTemplateVersion::contentHash(
            $version->definition,
            $version->translations,
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
            'lock_version' => $template->lock_version + 1,
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param mixed $value */
    private function jsonOrNull(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

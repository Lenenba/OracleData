<?php

use App\Enums\DataQualityHealthStatus;
use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use App\Enums\ReferenceScenarioType;
use App\Models\AuditEvent;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateValidationRun;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use App\Services\QueryTemplateQualityService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array<string, array<string, string>> */
function stepEightTranslations(string $name = 'Factures contrôlées'): array
{
    return [
        'fr' => ['name' => $name, 'description' => 'Contrôle qualité français'],
        'en' => ['name' => 'Quality-controlled invoices', 'description' => 'English quality control'],
        'es' => ['name' => 'Facturas controladas', 'description' => 'Control de calidad español'],
    ];
}

/**
 * @param  list<array<string, mixed>>  $rules
 */
function stepEightSaveDraft(
    QueryTemplate $template,
    QueryTemplateVersion $draft,
    User $admin,
    array $rules,
): QueryTemplateVersion {
    return app(QueryTemplateGovernanceService::class)->updateDraft(
        $template,
        $draft,
        $admin,
        $draft->definition,
        stepEightTranslations(),
        'Assertions qualité de la version',
        $admin->id,
        today()->addMonth(),
        $template->refresh()->lock_version,
        $draft->refresh()->lock_version,
        $rules,
    );
}

/** @param list<array<string, mixed>> $rows */
function stepEightFakeOracle(array $rows): void
{
    Http::fake(['*' => Http::response([
        'items' => $rows,
        'count' => count($rows),
        'hasMore' => false,
    ])]);
}

beforeEach(function () {
    $this->admin = createConnectedUser(
        ['is_super_admin' => true],
        [
            'key' => 'quality_main',
            'label' => 'Qualité principale',
            'base_url' => 'https://quality-main.fa.oraclecloud.com',
            'is_default' => true,
        ],
        ['identifier' => 'quality_admin', 'secret' => 'quality_secret'],
    );
    $this->template = QueryTemplate::factory()->create([
        'slug' => 'step-eight-quality-template',
    ]);
});

test('required assertions gate publication while optional warnings lower the health score', function () {
    $governance = app(QueryTemplateGovernanceService::class);
    $quality = app(QueryTemplateQualityService::class);
    $draft = $governance->createDraft(
        $this->template,
        $this->admin,
        null,
        $this->template->lock_version,
    );
    $rules = [
        [
            'id' => 'not-empty',
            'name' => 'Au moins une facture',
            'type' => 'non_empty',
            'required' => true,
            'config' => [],
        ],
        [
            'id' => 'known-supplier',
            'name' => 'Fournisseur attendu',
            'type' => 'allowed_values',
            'required' => false,
            'config' => ['field' => 'Supplier', 'values' => ['Approved']],
        ],
    ];
    $draft = stepEightSaveDraft($this->template, $draft, $this->admin, $rules);

    expect($draft->quality_rules)->toHaveCount(2)
        ->and($draft->quality_rules[0]['type'])->toBe('non_empty')
        ->and($draft->quality_rules[0]['enabled'])->toBeTrue()
        ->and($draft->quality_rules[1]['required'])->toBeFalse()
        ->and($draft->content_hash)->toBe(QueryTemplateVersion::contentHash(
            $draft->definition,
            $draft->translations,
            $draft->quality_rules,
        ));

    $review = $governance->submitForReview(
        $this->template,
        $draft,
        $this->admin,
        $this->template->refresh()->lock_version,
        $draft->lock_version,
    );

    expect(fn () => $governance->publish(
        $this->template,
        $review,
        $this->admin,
        $this->template->refresh()->lock_version,
        $review->lock_version,
    ))->toThrow(ValidationException::class);

    stepEightFakeOracle([[
        'InvoiceNumber' => 'INV-8',
        'Supplier' => 'Unexpected',
        'InvoiceAmount' => 800,
    ]]);
    $run = $quality->run(
        $this->template,
        $review,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 800],
        DataQualityRunPurpose::PrePublication,
    );

    expect($run->status)->toBe(DataQualityRunStatus::Passed)
        ->and((float) $run->score)->toBe(50.0)
        ->and($run->assertion_results)->toHaveCount(2)
        ->and($run->assertion_results[1])->toMatchArray([
            'required' => false,
            'passed' => false,
            'code' => 'value_not_allowed',
        ]);

    $published = $governance->publish(
        $this->template,
        $review,
        $this->admin,
        $this->template->refresh()->lock_version,
        $review->refresh()->lock_version,
    );

    expect($published->id)->toBe($review->id)
        ->and($this->template->refresh()->quality_status)->toBe(DataQualityHealthStatus::Degraded)
        ->and((float) $this->template->quality_score)->toBe(50.0)
        ->and($this->template->latest_quality_run_id)->toBe($run->id);
});

test('reference profiles stay value free and every configured scenario must pass', function () {
    $governance = app(QueryTemplateGovernanceService::class);
    $quality = app(QueryTemplateQualityService::class);
    $draft = $governance->createDraft(
        $this->template,
        $this->admin,
        null,
        $this->template->lock_version,
    );
    $filterRows = [
        ['InvoiceNumber' => 'SECRET-A', 'Supplier' => null, 'InvoiceAmount' => 10],
        ['InvoiceNumber' => 'SECRET-B', 'Supplier' => 'Acme', 'InvoiceAmount' => 20],
    ];
    $orderRows = [
        ['InvoiceNumber' => 'ORDER-1', 'Supplier' => 'Acme', 'InvoiceAmount' => 30],
        ['InvoiceNumber' => 'ORDER-1', 'Supplier' => 'Acme', 'InvoiceAmount' => 30],
    ];
    $comparison = [
        'order_sensitive' => true,
        'duplicate_sensitive' => true,
        'compare_nulls' => true,
        'compare_schema' => true,
        'nested_order_sensitive' => true,
        'aggregates' => [['field' => 'InvoiceAmount', 'function' => 'sum']],
    ];

    Http::fakeSequence()
        ->push(['items' => $filterRows, 'count' => 2, 'hasMore' => false])
        ->push(['items' => $orderRows, 'count' => 2, 'hasMore' => false])
        ->push(['items' => array_reverse($filterRows), 'count' => 2, 'hasMore' => false])
        ->push(['items' => $filterRows, 'count' => 2, 'hasMore' => false])
        ->push(['items' => $orderRows, 'count' => 2, 'hasMore' => false])
        ->push(['items' => $filterRows, 'count' => 2, 'hasMore' => false]);

    $filterReference = $quality->captureReference(
        $this->template,
        $draft,
        $this->admin,
        'Filtre avec valeurs nulles',
        ReferenceScenarioType::Filter,
        'quality_main',
        ['minimum_amount' => 10],
        $comparison,
    );
    $orderReference = $quality->captureReference(
        $this->template,
        $draft,
        $this->admin,
        'Ordre et doublons',
        ReferenceScenarioType::Order,
        'quality_main',
        ['minimum_amount' => 30],
        $comparison,
    );
    $persistedEvidence = json_encode([
        $filterReference->fingerprint,
        $filterReference->parameter_hash,
        AuditEvent::query()->where('action', 'query_template.reference_captured')->get()->pluck('context'),
    ], JSON_THROW_ON_ERROR);

    expect($filterReference->dataset_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($filterReference->parameter_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($persistedEvidence)->not->toContain('SECRET-A')
        ->and($persistedEvidence)->not->toContain('SECRET-B')
        ->and(fn () => $filterReference->update(['name' => 'Altéré']))->toThrow(LogicException::class)
        ->and(fn () => $filterReference->delete())->toThrow(LogicException::class);

    $draft = stepEightSaveDraft($this->template, $draft, $this->admin, [
        [
            'id' => 'filter-reference',
            'name' => 'Référence du filtre',
            'type' => 'reference_equivalence',
            'config' => ['reference_dataset_id' => $filterReference->id],
        ],
        [
            'id' => 'order-reference',
            'name' => 'Référence ordre et doublons',
            'type' => 'reference_equivalence',
            'config' => ['reference_dataset_id' => $orderReference->id],
        ],
    ]);
    $review = $governance->submitForReview(
        $this->template,
        $draft,
        $this->admin,
        $this->template->refresh()->lock_version,
        $draft->lock_version,
    );

    $mismatch = $quality->run(
        $this->template,
        $review,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 10],
        DataQualityRunPurpose::PrePublication,
        $filterReference,
    );
    expect($mismatch->status)->toBe(DataQualityRunStatus::Failed)
        ->and($mismatch->assertion_results[0]['code'])->toBe('reference_mismatch');

    $filterRun = $quality->run(
        $this->template,
        $review,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 10],
        DataQualityRunPurpose::PrePublication,
        $filterReference,
    );
    expect($filterRun->status)->toBe(DataQualityRunStatus::Passed);

    expect(fn () => $governance->publish(
        $this->template,
        $review,
        $this->admin,
        $this->template->refresh()->lock_version,
        $review->lock_version,
    ))->toThrow(ValidationException::class);

    $orderRun = $quality->run(
        $this->template,
        $review,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 30],
        DataQualityRunPurpose::PrePublication,
        $orderReference,
    );
    expect($orderRun->status)->toBe(DataQualityRunStatus::Passed);

    $governance->publish(
        $this->template,
        $review,
        $this->admin,
        $this->template->refresh()->lock_version,
        $review->refresh()->lock_version,
    );

    expect($this->template->refresh()->published_version_id)->toBe($review->id)
        ->and(QueryTemplateValidationRun::query()
            ->where('purpose', DataQualityRunPurpose::PrePublication->value)
            ->count())->toBe(3);

    $nextDraft = $governance->createDraft(
        $this->template,
        $this->admin,
        null,
        $this->template->lock_version,
    );
    $copiedFilterReference = $nextDraft->referenceDatasets()
        ->where('scenario', ReferenceScenarioType::Filter->value)
        ->sole();
    $copiedFilterRule = collect($nextDraft->quality_rules)
        ->firstWhere('id', 'filter-reference');
    $copiedRun = $quality->run(
        $this->template,
        $nextDraft,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 10],
        DataQualityRunPurpose::Manual,
        $copiedFilterReference,
    );

    expect($nextDraft->referenceDatasets()->count())->toBe(2)
        ->and($copiedFilterReference->id)->not->toBe($filterReference->id)
        ->and($copiedFilterReference->dataset_hash)->toBe($filterReference->dataset_hash)
        ->and($copiedFilterRule['config']['reference_dataset_id'])->toBe($copiedFilterReference->id)
        ->and($copiedRun->status)->toBe(DataQualityRunStatus::Passed);
});

test('normal executions monitor health and temporarily suspend a certification', function () {
    $governance = app(QueryTemplateGovernanceService::class);
    $quality = app(QueryTemplateQualityService::class);
    $draft = $governance->createDraft(
        $this->template,
        $this->admin,
        null,
        $this->template->lock_version,
    );
    $draft = stepEightSaveDraft($this->template, $draft, $this->admin, [[
        'id' => 'not-empty',
        'name' => 'Résultat obligatoire',
        'type' => 'non_empty',
        'config' => [],
    ]]);
    $review = $governance->submitForReview(
        $this->template,
        $draft,
        $this->admin,
        $this->template->refresh()->lock_version,
        $draft->lock_version,
    );
    Http::fakeSequence()
        ->push([
            'items' => [['InvoiceNumber' => 'OK-1', 'Supplier' => 'Acme', 'InvoiceAmount' => 10]],
            'count' => 1,
            'hasMore' => false,
        ])
        ->push(['items' => [], 'count' => 0, 'hasMore' => false])
        ->push([
            'items' => [['InvoiceNumber' => 'RECOVERED', 'Supplier' => 'Acme', 'InvoiceAmount' => 20]],
            'count' => 1,
            'hasMore' => false,
        ]);
    $quality->run(
        $this->template,
        $review,
        $this->admin,
        'quality_main',
        ['minimum_amount' => 10],
        DataQualityRunPurpose::PrePublication,
    );
    $published = $governance->publish(
        $this->template,
        $review,
        $this->admin,
        $this->template->refresh()->lock_version,
        $review->refresh()->lock_version,
    );
    $certification = $governance->certifyPublishedVersion(
        $this->template,
        $this->admin,
        $this->template->refresh()->lock_version,
        $published->id,
        'Résultats surveillés automatiquement',
    );

    $this->actingAs($this->admin)
        ->postJson(route('query-templates.run', $this->template), [
            'tenant' => 'quality_main',
            'parameter_values' => ['minimum_amount' => 10],
        ])
        ->assertOk();

    expect($this->template->refresh()->quality_status)->toBe(DataQualityHealthStatus::Failing)
        ->and($this->template->quality_failure_streak)->toBe(1)
        ->and($certification->isEffectiveFor($this->template))->toBeFalse();

    $this->actingAs($this->admin)
        ->postJson(route('query-templates.run', $this->template), [
            'tenant' => 'quality_main',
            'parameter_values' => ['minimum_amount' => 10],
        ])
        ->assertOk();

    expect($this->template->refresh()->quality_status)->toBe(DataQualityHealthStatus::Degraded)
        ->and($this->template->quality_failure_streak)->toBe(0)
        ->and($certification->isEffectiveFor($this->template))->toBeTrue()
        ->and(QueryTemplateValidationRun::query()
            ->where('purpose', DataQualityRunPurpose::Monitoring->value)
            ->count())->toBe(2)
        ->and(QueryExecution::query()->where('purpose', QueryExecution::PURPOSE_RUN)->count())->toBe(2);
});

test('quality endpoints enforce user tenant and nested reference isolation', function () {
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft(
        $this->template,
        $this->admin,
        null,
        $this->template->lock_version,
    );
    $outsider = createConnectedUser([], [
        'key' => 'quality_other',
        'label' => 'Autre utilisateur',
        'base_url' => 'https://quality-other.fa.oraclecloud.com',
        'is_default' => true,
    ]);
    $otherTemplate = QueryTemplate::factory()->create();
    $foreignReference = QueryTemplateReferenceDataset::factory()->create([
        'query_template_id' => $otherTemplate->id,
        'query_template_version_id' => $otherTemplate->published_version_id,
        'oracle_tenant_id' => $this->admin->oracleTenants()->value('id'),
        'auth_connection_id' => $this->admin->authConnections()->value('id'),
        'captured_by_user_id' => $this->admin->id,
    ]);
    Http::fake();
    $url = route('query-template-governance.versions.quality-runs.store', [
        $this->template,
        $draft,
    ]);

    $this->actingAs($outsider)
        ->postJson($url, [
            'tenant' => 'quality_other',
            'parameter_values' => [],
        ])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->postJson($url, [
            'tenant' => 'quality_other',
            'parameter_values' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tenant');

    $this->actingAs($this->admin)
        ->postJson($url, [
            'tenant' => 'quality_main',
            'parameter_values' => [],
            'reference_dataset_id' => $foreignReference->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reference_dataset_id');

    Http::assertNothingSent();
});

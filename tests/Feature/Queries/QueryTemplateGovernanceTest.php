<?php

use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Http\Controllers\QueryTemplateController;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateTranslation;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\OracleResourceCatalog;
use App\Services\QueryExecutionRecorder;
use App\Services\QueryTemplateGovernanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\QueryTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @return array<string, array<string, mixed>> */
function validTemplateGovernanceTranslations(string $name = 'Factures gouvernées'): array
{
    return [
        'fr' => ['name' => $name, 'description' => 'Description française'],
        'en' => ['name' => 'Governed invoices', 'description' => 'English description'],
        'es' => ['name' => 'Facturas gobernadas', 'description' => 'Descripción española'],
    ];
}

test('only super administrators may govern official templates', function () {
    $template = QueryTemplate::factory()->create();
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_super_admin' => true]);

    expect(Gate::forUser($user)->allows('viewAnyGovernance', QueryTemplate::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('createDraft', $template))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAnyGovernance', QueryTemplate::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('createDraft', $template))->toBeTrue();
});

test('a reviewed version is published atomically without mutating its history', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $businessOwner = User::factory()->create();
    $template = QueryTemplate::factory()->create(['name' => 'Ancien nom']);
    $firstVersion = $template->publishedVersion()->sole();
    $governance = app(QueryTemplateGovernanceService::class);

    $draft = $governance->createDraft($template, $admin, 'Préparer la nouvelle présentation', 1);
    $template->refresh();
    $definition = array_replace($draft->definition, [
        'name' => 'Nouveau nom publié',
        'description' => 'Nouvelle description',
        'sort_order' => 12,
    ]);
    $draft = $governance->updateDraft(
        $template,
        $draft,
        $admin,
        $definition,
        validTemplateGovernanceTranslations('Nouveau nom publié'),
        'Résumé de changement confidentiel',
        $businessOwner->id,
        CarbonImmutable::parse('2027-01-31'),
        $template->lock_version,
        $draft->lock_version,
    );

    // The live projection remains unchanged throughout draft and review.
    expect($template->refresh()->name)->toBe('Ancien nom')
        ->and(QueryTemplate::query()->active()->whereKey($template)->exists())->toBeTrue();

    $draft = $governance->submitForReview(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
    $published = $governance->publish(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
    $template->refresh();

    expect($published->status)->toBe(QueryTemplateVersionStatus::PUBLISHED)
        ->and($published->open_slot)->toBeNull()
        ->and($template->published_version_id)->toBe($published->id)
        ->and($template->governance_status)->toBe(QueryTemplateGovernanceStatus::PUBLISHED)
        ->and($template->name)->toBe('Nouveau nom publié')
        ->and($template->description)->toBe('Nouvelle description')
        ->and($template->business_owner_user_id)->toBe($businessOwner->id)
        ->and($template->review_due_at?->toDateString())->toBe('2027-01-31')
        ->and($firstVersion->refresh()->status)->toBe(QueryTemplateVersionStatus::SUPERSEDED)
        ->and(QueryTemplateTranslation::query()->where('query_template_id', $template->id)->count())->toBe(3)
        ->and($published->fresh()->definition['name'])->toBe('Nouveau nom publié');

    $events = AuditEvent::query()
        ->where('subject_type', $template->getMorphClass())
        ->where('subject_id', $template->id)
        ->get();
    $encodedContexts = json_encode($events->pluck('context')->all(), JSON_THROW_ON_ERROR);

    expect($events->pluck('action')->all())->toContain(
        'query_template.version_drafted',
        'query_template.version_updated',
        'query_template.version_submitted',
        'query_template.version_published',
    )
        ->and($encodedContexts)->not->toContain('Nouveau nom publié')
        ->and($encodedContexts)->not->toContain('Résumé de changement confidentiel')
        ->and($encodedContexts)->not->toContain('parameter_definitions');
});

test('the workflow rejects parallel drafts stale locks and cross-template versions', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $template = QueryTemplate::factory()->create();
    $otherTemplate = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft($template, $admin, null, $template->lock_version);

    expect(fn () => $governance->createDraft(
        $template,
        $admin,
        null,
        $template->refresh()->lock_version,
    ))->toThrow(ConflictHttpException::class)
        ->and(fn () => $governance->updateDraft(
            $template,
            $draft,
            $admin,
            $draft->definition,
            validTemplateGovernanceTranslations(),
            null,
            null,
            null,
            1,
            $draft->lock_version,
        ))->toThrow(ConflictHttpException::class)
        ->and(fn () => $governance->updateDraft(
            $otherTemplate,
            $draft,
            $admin,
            $draft->definition,
            validTemplateGovernanceTranslations(),
            null,
            null,
            null,
            $otherTemplate->refresh()->lock_version,
            $draft->lock_version,
        ))->toThrow(Exception::class);
});

test('the governance console returns actionable conflicts instead of an inertia exception modal', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $template = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);
    $governance->createDraft($template, $admin, null, $template->lock_version);
    $showUrl = route('query-template-governance.show', $template);
    $payload = ['lock_version' => $template->refresh()->lock_version];

    $this->actingAs($admin)
        ->from($showUrl)
        ->withHeader('X-Inertia', 'true')
        ->post(route('query-template-governance.versions.store', $template), $payload)
        ->assertRedirect($showUrl)
        ->assertSessionHasErrors('governance');

    $this->actingAs($admin)
        ->postJson(route('query-template-governance.versions.store', $template), $payload)
        ->assertConflict()
        ->assertJsonStructure(['message']);
});

test('invalid snapshots cannot enter review and submitted content is immutable', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $template = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft($template, $admin, null, $template->lock_version);
    $invalidDefinition = array_replace($draft->definition, [
        'resource_key' => 'unknown_resource',
        'resource_path' => '/unsafe',
    ]);

    expect(fn () => $governance->updateDraft(
        $template,
        $draft,
        $admin,
        $invalidDefinition,
        validTemplateGovernanceTranslations(),
        null,
        null,
        null,
        $template->refresh()->lock_version,
        $draft->lock_version,
    ))->toThrow(ValidationException::class);

    $draft = $governance->updateDraft(
        $template,
        $draft,
        $admin,
        $draft->definition,
        validTemplateGovernanceTranslations(),
        null,
        null,
        null,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
    $submitted = $governance->submitForReview(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );

    expect(fn () => $submitted->update([
        'definition' => array_replace($submitted->definition, ['name' => 'Mutation interdite']),
    ]))->toThrow(LogicException::class);
});

test('archiving removes a template from the public library and preserves every version', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $template = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft($template, $admin, null, $template->lock_version);
    $versionCount = $template->versions()->count();
    $archived = $governance->archive($template, $admin, $template->refresh()->lock_version);

    expect($archived->governance_status)->toBe(QueryTemplateGovernanceStatus::ARCHIVED)
        ->and($archived->is_active)->toBeFalse()
        ->and(QueryTemplate::query()->active()->whereKey($template)->exists())->toBeFalse()
        ->and($template->versions()->count())->toBe($versionCount)
        ->and($draft->refresh()->status)->toBe(QueryTemplateVersionStatus::SUPERSEDED)
        ->and($draft->open_slot)->toBeNull();
});

test('the governance-aware seeder bootstraps version one and never overwrites it', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);
    $template = QueryTemplate::query()->where('slug', 'supplier-invoices-above-amount')->firstOrFail();
    $versionId = $template->published_version_id;
    $translation = $template->translations()->where('locale', 'fr')->firstOrFail();

    $template->applyGovernanceProjection(['name' => 'Nom gouverné hors seeder']);
    $translation->update(['name' => 'Traduction gouvernée']);
    $this->seed(QueryTemplateSeeder::class);

    expect($template->refresh()->name)->toBe('Nom gouverné hors seeder')
        ->and($template->published_version_id)->toBe($versionId)
        ->and($template->versions()->count())->toBe(1)
        ->and($translation->refresh()->name)->toBe('Traduction gouvernée');
});

test('the governance seeder safely resumes an orphaned initial version', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);
    $template = QueryTemplate::query()
        ->where('slug', 'supplier-invoices-above-amount')
        ->firstOrFail();
    $versionId = $template->published_version_id;

    DB::table('query_templates')->where('id', $template->id)->update([
        'governance_status' => QueryTemplateGovernanceStatus::DRAFT->value,
        'published_version_id' => null,
        'published_at' => null,
    ]);

    $this->seed(QueryTemplateSeeder::class);

    expect($template->refresh()->published_version_id)->toBe($versionId)
        ->and($template->governance_status)->toBe(QueryTemplateGovernanceStatus::PUBLISHED)
        ->and($template->versions()->count())->toBe(1);
});

test('a stale catalogue model never mixes one version with newer translations', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);
    $admin = User::factory()->create(['is_super_admin' => true]);
    $template = QueryTemplate::query()
        ->where('slug', 'supplier-invoices-above-amount')
        ->firstOrFail();
    $staleTemplate = QueryTemplate::query()
        ->with('publishedVersion')
        ->findOrFail($template->id);
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft($template, $admin, null, $template->lock_version);
    $draft = $governance->updateDraft(
        $template,
        $draft,
        $admin,
        array_replace($draft->definition, ['name' => 'Nom publié v2']),
        validTemplateGovernanceTranslations('Nom publié v2'),
        null,
        null,
        null,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
    $draft = $governance->submitForReview(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
    $governance->publish(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );

    // Simulate the second SELECT of an in-flight catalogue request after the
    // publication committed: its base pointer is still v1 but translations
    // loaded from the projection table are now v2.
    $staleTemplate->load(['translations', 'category.translations']);
    $method = new ReflectionMethod(QueryTemplateController::class, 'templatePayload');
    $method->setAccessible(true);
    /** @var array<string, mixed> $payload */
    $payload = $method->invoke(
        app(QueryTemplateController::class),
        $staleTemplate,
        app(OracleResourceCatalog::class),
        'fr',
    );

    expect($payload['version'])->toBe(1)
        ->and($payload['name'])->toBe('Factures fournisseurs supérieures à un montant')
        ->and($payload['name'])->not->toBe('Nom publié v2');
});

test('saved clone executions retain the exact official source version after a concurrent archive', function () {
    $user = createConnectedUser();
    $tenant = $user->oracleTenants()->firstOrFail();
    $template = QueryTemplate::factory()->create();
    $query = Query::factory()->for($user)->create([
        'query_template_id' => $template->id,
        'tenant_key' => $tenant->key,
        'oracle_tenant_id' => $tenant->id,
    ]);
    $query->forceFill(['query_template_version_id' => $template->published_version_id])->save();
    $query->delete();

    $execution = app(QueryExecutionRecorder::class)->recordQueryRun(
        $user,
        $query,
        $tenant->key,
        ['items' => [], 'error' => null],
        now()->subSecond(),
        25,
    );

    expect($execution->source_type)->toBe(QueryExecution::SOURCE_SAVED_QUERY)
        ->and($execution->query_id)->toBe($query->id)
        ->and($execution->query_template_id)->toBeNull()
        ->and($execution->query_template_version_id)->toBe($template->published_version_id)
        ->and($execution->queryTemplateVersion?->id)->toBe($template->published_version_id);
});

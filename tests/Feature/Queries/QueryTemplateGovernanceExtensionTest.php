<?php

use App\Enums\QueryTemplateVersionStatus;
use App\Models\AuditEvent;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\QueryTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function phaseSixBTemplate(string $slug = 'supplier-invoices-above-amount'): QueryTemplate
{
    return QueryTemplate::query()->where('slug', $slug)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $definitionChanges
 * @param  array<string, array<string, mixed>>  $translationChanges
 */
function publishPhaseSixBVersion(
    QueryTemplate $template,
    User $admin,
    array $definitionChanges = [],
    array $translationChanges = [],
): QueryTemplateVersion {
    $governance = app(QueryTemplateGovernanceService::class);
    $draft = $governance->createDraft(
        $template,
        $admin,
        'Préparation version suivante',
        $template->refresh()->lock_version,
    );
    $definition = array_replace($draft->definition, $definitionChanges);
    $translations = $draft->translations;

    foreach ($translationChanges as $locale => $changes) {
        $translations[$locale] = array_replace($translations[$locale] ?? [], $changes);
    }

    $draft = $governance->updateDraft(
        $template,
        $draft,
        $admin,
        $definition,
        $translations,
        'Résumé non public',
        $admin->id,
        today()->addMonth(),
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

    return $governance->publish(
        $template,
        $draft,
        $admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    );
}

beforeEach(function () {
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);
    $this->admin = User::factory()->create(['is_super_admin' => true]);
});

test('a historical publication is restored into a new draft and keeps durable provenance', function () {
    $template = phaseSixBTemplate();
    $source = $template->publishedVersion()->sole();
    $published = publishPhaseSixBVersion(
        $template,
        $this->admin,
        ['name' => 'Projection publiée v2'],
        ['fr' => ['name' => 'Projection publiée v2']],
    );
    $projectionName = $template->refresh()->name;
    $governance = app(QueryTemplateGovernanceService::class);
    $restored = $governance->restoreHistoricalVersion(
        $template,
        $source,
        $this->admin,
        $template->lock_version,
        $source->refresh()->lock_version,
        'Revenir au comportement validé',
    );

    expect($restored->id)->not->toBe($source->id)
        ->and($restored->version_number)->toBe($published->version_number + 1)
        ->and($restored->status)->toBe(QueryTemplateVersionStatus::DRAFT)
        ->and($restored->restored_from_version_id)->toBe($source->id)
        ->and($restored->definition)->toBe($source->definition)
        ->and($restored->translations)->toBe($source->translations)
        ->and($restored->content_hash)->toBe($source->content_hash)
        ->and($template->refresh()->published_version_id)->toBe($published->id)
        ->and($template->name)->toBe($projectionName)
        ->and($template->versions()->whereNotNull('open_slot')->count())->toBe(1);

    $submitted = $governance->submitForReview(
        $template,
        $restored,
        $this->admin,
        $template->lock_version,
        $restored->lock_version,
    );
    $republished = $governance->publish(
        $template,
        $submitted,
        $this->admin,
        $template->refresh()->lock_version,
        $submitted->lock_version,
    );

    expect($republished->restored_from_version_id)->toBe($source->id)
        ->and($template->refresh()->published_version_id)->toBe($republished->id)
        ->and($source->refresh()->status)->toBe(QueryTemplateVersionStatus::SUPERSEDED);

    $event = AuditEvent::query()->where('action', 'query_template.version_restored')->sole();
    $encoded = json_encode($event->context, JSON_THROW_ON_ERROR);

    expect($event->context)->toMatchArray([
        'query_template_version_id' => $restored->id,
        'restored_from_version_id' => $source->id,
        'source_content_hash' => $source->content_hash,
    ])->and($encoded)
        ->not->toContain('Revenir au comportement validé')
        ->not->toContain('parameter_definitions')
        ->not->toContain('Projection publiée v2');
});

test('restoration rejects stale locks open cycles unpublished history and altered hashes', function () {
    $template = phaseSixBTemplate();
    $source = $template->publishedVersion()->sole();
    publishPhaseSixBVersion($template, $this->admin, ['name' => 'Version courante']);
    $governance = app(QueryTemplateGovernanceService::class);

    expect(fn () => $governance->restoreHistoricalVersion(
        $template,
        $source,
        $this->admin,
        1,
        $source->refresh()->lock_version,
    ))->toThrow(ConflictHttpException::class);

    $draft = $governance->createDraft(
        $template,
        $this->admin,
        null,
        $template->refresh()->lock_version,
    );
    expect(fn () => $governance->restoreHistoricalVersion(
        $template,
        $source,
        $this->admin,
        $template->refresh()->lock_version,
        $source->lock_version,
    ))->toThrow(ConflictHttpException::class);
    DB::table('query_template_versions')->where('id', $draft->id)->update([
        'status' => QueryTemplateVersionStatus::SUPERSEDED->value,
        'open_slot' => null,
        'lock_version' => $draft->lock_version + 1,
    ]);
    $draft->refresh();
    expect(fn () => $governance->restoreHistoricalVersion(
        $template,
        $draft,
        $this->admin,
        $template->refresh()->lock_version,
        $draft->lock_version,
    ))->toThrow(ConflictHttpException::class);

    $tamperedDefinition = array_replace($source->definition, ['name' => 'Altération hors gouvernance']);
    DB::table('query_template_versions')->where('id', $source->id)->update([
        'definition' => json_encode($tamperedDefinition, JSON_THROW_ON_ERROR),
    ]);
    $source->refresh();
    expect(fn () => $governance->restoreHistoricalVersion(
        $template,
        $source,
        $this->admin,
        $template->refresh()->lock_version,
        $source->lock_version,
    ))->toThrow(ConflictHttpException::class);
});

test('comparison is canonical read only and supports versions outside the history page', function () {
    $template = phaseSixBTemplate();
    $from = $template->publishedVersion()->sole();
    $parameters = array_replace($from->definition['parameters'], ['limit' => 51]);
    $to = publishPhaseSixBVersion(
        $template,
        $this->admin,
        [
            'description' => null,
            'parameters' => $parameters,
        ],
        [
            'fr' => ['name' => 'Nom français comparé'],
            'en' => ['description' => 'Compared English description'],
        ],
    );
    $fromLock = $from->refresh()->lock_version;
    $toLock = $to->lock_version;

    $response = $this->actingAs($this->admin)->getJson(route(
        'query-template-governance.versions.compare',
        [$template, 'from_version_id' => $from->id, 'to_version_id' => $to->id],
    ));
    $response->assertOk()
        ->assertJsonPath('from_version.id', $from->id)
        ->assertJsonPath('to_version.id', $to->id)
        ->assertJsonPath('from_version.lock_version', $fromLock)
        ->assertJsonPath('to_version.lock_version', $toLock);
    $changes = collect($response->json('changes'))->keyBy('path')->all();

    expect($changes)->toHaveKeys([
        'definition.description',
        'definition.parameters.limit',
        'translations.fr.name',
        'translations.en.description',
    ])->and($changes['definition.description']['section'])->toBe('metadata')
        ->and($changes['definition.parameters.limit']['section'])->toBe('technical')
        ->and($changes['translations.fr.name']['section'])->toBe('translations')
        ->and($from->refresh()->lock_version)->toBe($fromLock)
        ->and($to->refresh()->lock_version)->toBe($toLock)
        ->and(AuditEvent::query()->where('action', 'query_template.versions_compared')->exists())->toBeFalse();

    $this->actingAs($this->admin)->getJson(route(
        'query-template-governance.versions.compare',
        [$template, 'from_version_id' => $to->id, 'to_version_id' => $to->id],
    ))->assertOk()
        ->assertJsonPath('summary.total', 0)
        ->assertJsonCount(0, 'changes');
});

test('comparison and restoration enforce super admin and nested version boundaries', function () {
    $template = QueryTemplate::query()->orderBy('id')->firstOrFail();
    $other = QueryTemplate::query()->whereKeyNot($template->id)->orderBy('id')->firstOrFail();
    $from = $template->publishedVersion()->sole();
    $foreign = $other->publishedVersion()->sole();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route(
        'query-template-governance.versions.compare',
        [$template, 'from_version_id' => 999999, 'to_version_id' => 999998],
    ))->assertForbidden();

    $this->actingAs($this->admin)->getJson(route(
        'query-template-governance.versions.compare',
        [$template, 'from_version_id' => $from->id, 'to_version_id' => $foreign->id],
    ))->assertNotFound();

    $this->actingAs($this->admin)->postJson(route(
        'query-template-governance.versions.restore',
        [$template, $foreign],
    ), [
        'template_lock_version' => $template->lock_version,
        'version_lock_version' => $foreign->lock_version,
    ])->assertNotFound();
});

test('certification binds the exact current hash and exposes only an effective public attestation', function () {
    $template = phaseSixBTemplate();
    $published = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Version certifiable']);
    $governance = app(QueryTemplateGovernanceService::class);
    $certification = $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
        '  Validée par le contrôle métier  ',
    );

    expect($certification->isActive())->toBeTrue()
        ->and($certification->query_template_version_id)->toBe($published->id)
        ->and($certification->version_content_hash)->toBe($published->content_hash)
        ->and($certification->public_note)->toBe('Validée par le contrôle métier')
        ->and($certification->isEffectiveFor($template->refresh()))->toBeTrue();

    $reader = createConnectedUser();
    $this->actingAs($reader)
        ->get(route('query-templates.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('template.is_certified', true)
            ->where('template.certification_note', 'Validée par le contrôle métier')
            ->where('template.certification.public_note', 'Validée par le contrôle métier')
            ->where('template.certification.version_number', $published->version_number));

    $event = AuditEvent::query()->where('action', 'query_template.certified')->sole();
    $encoded = json_encode($event->context, JSON_THROW_ON_ERROR);

    expect($event->context)->toMatchArray([
        'query_template_certification_id' => $certification->id,
        'query_template_version_id' => $published->id,
        'version_content_hash' => $published->content_hash,
    ])->and($encoded)->not->toContain('Validée par le contrôle métier');
});

test('certification requires current governance prerequisites and an untampered executable snapshot', function () {
    $template = phaseSixBTemplate();
    $published = $template->publishedVersion()->sole();
    $governance = app(QueryTemplateGovernanceService::class);

    expect(fn () => $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->lock_version,
        $published->id,
    ))->toThrow(ValidationException::class);

    $published = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Prérequis présents']);
    expect(fn () => $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        1,
        $published->id,
    ))->toThrow(ConflictHttpException::class)
        ->and(fn () => $governance->certifyPublishedVersion(
            $template,
            $this->admin,
            $template->refresh()->lock_version,
            $published->id - 1,
        ))->toThrow(ConflictHttpException::class);

    $categoryId = $published->definition['category_id'] ?? null;

    if ($categoryId !== null) {
        DB::table('categories')->where('id', $categoryId)->delete();

        expect(fn () => $governance->certifyPublishedVersion(
            $template,
            $this->admin,
            $template->refresh()->lock_version,
            $published->id,
        ))->toThrow(ValidationException::class);
    }

    $tampered = array_replace($published->definition, ['name' => 'Empreinte falsifiée']);
    DB::table('query_template_versions')->where('id', $published->id)->update([
        'definition' => json_encode($tampered, JSON_THROW_ON_ERROR),
    ]);
    $published->refresh();
    expect(fn () => $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
    ))->toThrow(ConflictHttpException::class);
});

test('certification conflicts and nested revocation stay isolated', function () {
    $template = phaseSixBTemplate();
    $published = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Certification isolée']);
    $governance = app(QueryTemplateGovernanceService::class);
    $certification = $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
    );

    expect(fn () => $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
    ))->toThrow(ConflictHttpException::class)
        ->and(fn () => $governance->revokeCertification(
            $template,
            $certification,
            $this->admin,
            1,
            $certification->lock_version,
        ))->toThrow(ConflictHttpException::class);

    $otherTemplate = QueryTemplate::query()->whereKeyNot($template->id)->firstOrFail();
    $this->actingAs($this->admin)->postJson(route(
        'query-template-governance.certifications.revoke',
        [$otherTemplate, $certification],
    ), [
        'template_lock_version' => $otherTemplate->lock_version,
        'certification_lock_version' => $certification->lock_version,
    ])->assertNotFound();

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route(
        'query-template-governance.certifications.revoke',
        [$template, $certification],
    ), [
        'template_lock_version' => $template->refresh()->lock_version,
        'certification_lock_version' => $certification->lock_version,
    ])->assertForbidden();
});

test('manual revocation new publication and archive all close certification history atomically', function () {
    $template = phaseSixBTemplate();
    $published = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Version certifiée v2']);
    $governance = app(QueryTemplateGovernanceService::class);
    $manual = $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
        'Note publique sans secret',
    );
    $revoked = $governance->revokeCertification(
        $template,
        $manual,
        $this->admin,
        $template->refresh()->lock_version,
        $manual->lock_version,
    );

    expect($revoked->isActive())->toBeFalse()
        ->and($revoked->revocation_reason)->toBe(QueryTemplateCertification::REASON_MANUAL)
        ->and($revoked->active_slot)->toBeNull();

    $active = $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
    );
    $newPublished = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Version publiée v3']);

    expect($active->refresh()->revocation_reason)->toBe(QueryTemplateCertification::REASON_NEW_PUBLICATION)
        ->and($active->active_slot)->toBeNull()
        ->and($template->activeCertification()->exists())->toBeFalse();

    $archiveCertification = $governance->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $newPublished->id,
    );
    $governance->archive($template, $this->admin, $template->refresh()->lock_version);

    expect($archiveCertification->refresh()->revocation_reason)
        ->toBe(QueryTemplateCertification::REASON_TEMPLATE_ARCHIVED)
        ->and($archiveCertification->active_slot)->toBeNull()
        ->and($template->activeCertification()->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'query_template.certification_revoked')->count())->toBe(3);
});

test('an overdue certification remains revocable in governance but is hidden from the public badge', function () {
    $template = phaseSixBTemplate();
    $published = publishPhaseSixBVersion($template, $this->admin, ['name' => 'Version à réviser']);
    $certification = app(QueryTemplateGovernanceService::class)->certifyPublishedVersion(
        $template,
        $this->admin,
        $template->refresh()->lock_version,
        $published->id,
    );
    $template->refresh();
    $template->applyGovernanceProjection([
        'review_due_at' => today()->subDay(),
        'lock_version' => $template->lock_version + 1,
    ]);
    $reader = createConnectedUser();

    expect($certification->refresh()->isActive())->toBeTrue()
        ->and($certification->isEffectiveFor($template->refresh()))->toBeFalse();

    $this->actingAs($reader)
        ->get(route('query-templates.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('template.is_certified', false)
            ->where('template.certification_note', null)
            ->where('template.certification', null));

    $this->actingAs($this->admin)
        ->get(route('query-template-governance.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('template.certification.id', $certification->id)
            ->where('template.certification.is_effective', false));
});

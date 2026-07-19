<?php

use App\Enums\QueryTemplateRole;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\AuditEvent;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateRoleAssignment;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

test('template delegations are scoped cumulative and audited with strict role separation', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $editor = User::factory()->create();
    $publisher = User::factory()->create();
    $outsider = User::factory()->create();
    $template = QueryTemplate::factory()->create();
    $otherTemplate = QueryTemplate::factory()->create();

    $this->actingAs($admin)->patch(route('query-template-governance.delegations.update', [$template, $editor]), [
        'roles' => [QueryTemplateRole::Editor->value, QueryTemplateRole::Publisher->value],
    ])->assertRedirectToRoute('query-template-governance.show', $template);
    $this->actingAs($admin)->patch(route('query-template-governance.delegations.update', [$template, $publisher]), [
        'roles' => [QueryTemplateRole::Publisher->value],
    ])->assertRedirect();

    expect($editor->queryTemplateRoleNames($template))->toBe([
        QueryTemplateRole::Editor->value,
        QueryTemplateRole::Publisher->value,
    ])->and($editor->queryTemplateRoleNames($otherTemplate))->toBe([])
        ->and(Gate::forUser($editor)->allows('viewGovernance', $template))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('viewGovernance', $otherTemplate))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('viewGovernance', $template))->toBeFalse()
        ->and(QueryTemplateRoleAssignment::query()->where('assigned_by_user_id', $admin->id)->count())->toBe(3)
        ->and(AuditEvent::query()->where('action', 'query_template.roles_synced')->count())->toBe(2);

    $this->actingAs($editor)->patch(route('query-template-governance.delegations.update', [$template, $outsider]), [
        'roles' => [QueryTemplateRole::Editor->value],
    ])->assertForbidden();
    $this->actingAs($editor)
        ->get(route('query-template-governance.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can_view_query_template_governance', true)
            ->where('governanceRoleCandidates', []));
    $this->actingAs($editor)->get(route('query-template-governance.show', $otherTemplate))->assertForbidden();
});

test('governance mutations recheck a revoked delegation inside the locked service boundary', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $editor = User::factory()->create();
    $template = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);

    $governance->syncRoles($template, $editor, $admin, [QueryTemplateRole::Editor->value]);
    expect(Gate::forUser($editor)->allows('createDraft', $template))->toBeTrue();

    $governance->syncRoles($template, $editor, $admin, []);

    expect(fn () => $governance->createDraft(
        $template->refresh(),
        $editor,
        null,
        $template->lock_version,
    ))->toThrow(AuthorizationException::class)
        ->and($template->versions()->whereNotNull('open_slot')->exists())->toBeFalse();
});

test('only publishers or super admins assign the exact technical owner', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $publisher = User::factory()->create();
    $editor = User::factory()->create();
    $technicalOwner = User::factory()->create();
    $template = QueryTemplate::factory()->create();

    $this->actingAs($admin)->patch(route('query-template-governance.delegations.update', [$template, $publisher]), [
        'roles' => [QueryTemplateRole::Publisher->value],
    ]);
    $this->actingAs($admin)->patch(route('query-template-governance.delegations.update', [$template, $editor]), [
        'roles' => [QueryTemplateRole::Editor->value],
    ]);

    $this->actingAs($editor)->patch(route('query-template-governance.technical-owner.update', $template), [
        'technical_owner_user_id' => $technicalOwner->id,
        'lock_version' => $template->refresh()->lock_version,
    ])->assertForbidden();
    $this->actingAs($editor)
        ->get(route('query-template-governance.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('governanceRoleCandidates', [])
            ->where('technicalOwnerCandidates', []));
    $this->actingAs($publisher)->patch(route('query-template-governance.technical-owner.update', $template), [
        'technical_owner_user_id' => $technicalOwner->id,
        'lock_version' => $template->refresh()->lock_version,
    ])->assertRedirect();

    expect($template->refresh()->technical_owner_user_id)->toBe($technicalOwner->id)
        ->and(Gate::forUser($technicalOwner)->allows('viewGovernance', $template))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query_template.technical_owner_assigned')->count())->toBe(1);
});

test('only the exact technical owner can submit a catalog whitelisted Oracle definition', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $editor = User::factory()->create();
    $technicalOwner = User::factory()->create();
    $template = QueryTemplate::factory()->create();
    $governance = app(QueryTemplateGovernanceService::class);

    $this->actingAs($admin)->patch(route('query-template-governance.delegations.update', [$template, $editor]), [
        'roles' => [QueryTemplateRole::Editor->value],
    ]);
    $governance->assignTechnicalOwner($template, $technicalOwner, $admin, $template->refresh()->lock_version);
    $draft = $governance->createDraft($template->refresh(), $admin, null, $template->refresh()->lock_version);
    $payload = [
        'name' => $draft->definition['name'],
        'description' => $draft->definition['description'],
        'category_id' => $draft->definition['category_id'],
        'sort_order' => $draft->definition['sort_order'],
        'translations' => [
            'fr' => ['name' => 'Fournisseurs contrôlés'],
            'en' => ['name' => 'Controlled suppliers'],
            'es' => ['name' => 'Proveedores controlados'],
        ],
        'resource_key' => 'suppliers',
        'parameters' => [
            'fields' => 'Supplier,SupplierNumber,Status',
            'q' => "Status='ACTIVE'",
            'orderBy' => 'Supplier:asc',
            'limit' => 25,
        ],
        'parameter_definitions' => [[
            'key' => 'status',
            'label' => 'Statut',
            'type' => 'string',
            'required' => false,
            'audit' => ['mode' => 'masked'],
            'binding' => ['kind' => 'filter', 'field' => 'Status', 'operator' => '='],
        ]],
        'template_lock_version' => $template->refresh()->lock_version,
        'version_lock_version' => $draft->refresh()->lock_version,
    ];

    $this->actingAs($editor)->patch(route('query-template-governance.versions.update', [$template, $draft]), $payload)
        ->assertForbidden();
    $this->actingAs($technicalOwner)->patch(route('query-template-governance.versions.update', [$template, $draft]), $payload)
        ->assertRedirect();

    $draft->refresh();
    expect($draft->definition['resource_path'])->toBe('/fscmRestApi/resources/11.13.18.05/suppliers')
        ->and($draft->definition['parameters']['resource_key'])->toBe('suppliers')
        ->and(AuditEvent::query()->where('action', 'query_template.technical_definition_updated')->count())->toBe(1);

    $payload['parameters']['fields'] = 'Supplier;DROP_TABLE';
    $payload['template_lock_version'] = $template->refresh()->lock_version;
    $payload['version_lock_version'] = $draft->lock_version;
    $this->actingAs($technicalOwner)->patch(route('query-template-governance.versions.update', [$template, $draft]), $payload)
        ->assertSessionHasErrors('definition.parameters.fields');

    $draft->update(['status' => QueryTemplateVersionStatus::REVIEW]);
    expect(Gate::forUser($editor)->allows('publish', [$template, $draft]))->toBeFalse();
});

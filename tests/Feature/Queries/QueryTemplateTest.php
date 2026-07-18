<?php

use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Query;
use App\Models\QueryTemplate;
use App\Services\QueryTemplateParameterBinder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\QueryTemplateSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = createConnectedUser([], [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ], [
        'identifier' => 'template_runner',
        'secret' => 'template_secret',
    ]);
});

test('the predefined library exposes active templates and hides inactive ones', function () {
    $visible = QueryTemplate::factory()->create([
        'slug' => 'visible-template',
        'name' => 'Visible template',
    ]);
    QueryTemplate::factory()->inactive()->create([
        'slug' => 'hidden-template',
        'name' => 'Hidden template',
    ]);

    $this->actingAs($this->user)
        ->get(route('query-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('query-templates/index')
            ->has('templates', 1)
            ->where('templates.0.slug', $visible->slug)
            ->where('templates.0.name', $visible->name));
});

test('a template configuration page uses only the readers Oracle environments', function () {
    $template = QueryTemplate::factory()->create();

    $this->actingAs($this->user)
        ->get(route('query-templates.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('query-templates/show')
            ->where('template.slug', $template->slug)
            ->where('defaultTenant', 'client_x')
            ->where('tenants.client_x', 'Client X'));
});

test('inactive templates are not available and guests must authenticate', function () {
    $active = QueryTemplate::factory()->create();
    $inactive = QueryTemplate::factory()->inactive()->create();

    $this->get(route('query-templates.show', $active))
        ->assertRedirect(route('login'));

    $this->actingAs($this->user)
        ->get(route('query-templates.show', $inactive))
        ->assertNotFound();
});

test('the binder applies typed values without accepting query structure from the user', function () {
    $template = QueryTemplate::factory()->create([
        'parameters' => [
            'resource_key' => 'invoices',
            'fields' => 'Supplier,InvoiceAmount',
            'limit' => 50,
        ],
        'parameter_definitions' => [
            [
                'key' => 'minimum_amount',
                'label' => 'Montant minimum',
                'type' => 'number',
                'required' => true,
                'default' => 1000,
                'min' => 0,
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'InvoiceAmount',
                    'operator' => '>',
                ],
            ],
            [
                'key' => 'result_limit',
                'label' => 'Limite',
                'type' => 'integer',
                'required' => true,
                'default' => 50,
                'min' => 1,
                'max' => 500,
                'binding' => ['kind' => 'parameter', 'key' => 'limit'],
            ],
            [
                'key' => 'maximum_amount',
                'label' => 'Montant maximum',
                'type' => 'number',
                'required' => false,
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'InvoiceAmount',
                    'operator' => '<',
                ],
            ],
        ],
    ]);

    $bound = app(QueryTemplateParameterBinder::class)->bind($template, [
        'minimum_amount' => '2500.50',
        'result_limit' => '80',
        'maximum_amount' => null,
    ]);

    expect($bound['parameters'])
        ->toMatchArray([
            'resource_key' => 'invoices',
            'fields' => 'Supplier,InvoiceAmount',
            'q' => 'InvoiceAmount > 2500.5',
            'limit' => 80,
        ])
        ->and($bound['values'])->toBe([
            'minimum_amount' => 2500.5,
            'result_limit' => 80,
        ]);

    expect(fn () => app(QueryTemplateParameterBinder::class)->bind($template, [
        'minimum_amount' => 1000,
        'resource_key' => 'workers',
    ]))->toThrow(ValidationException::class);
});

test('string filter values are escaped while fields and operators stay server controlled', function () {
    $template = QueryTemplate::factory()->create([
        'resource_key' => 'suppliers',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
        'parameters' => ['resource_key' => 'suppliers', 'limit' => 25],
        'parameter_definitions' => [[
            'key' => 'supplier_name',
            'label' => 'Fournisseur',
            'type' => 'string',
            'required' => true,
            'binding' => [
                'kind' => 'filter',
                'field' => 'Supplier',
                'operator' => '=',
            ],
        ]],
    ]);

    $bound = app(QueryTemplateParameterBinder::class)->bind($template, [
        'supplier_name' => "O'Brien' OR Status!='INACTIVE",
    ]);

    expect($bound['parameters']['q'])
        ->toBe("Supplier = 'O''Brien'' OR Status!=''INACTIVE'");
});

test('preview binds the chosen amount, caps the result size, and leaves the template untouched', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/*' => Http::response([
            'items' => [[
                'Supplier' => 'Acme',
                'InvoiceNumber' => 'INV-2500',
                'InvoiceAmount' => 3200,
            ]],
            'count' => 1,
            'hasMore' => false,
        ]),
    ]);
    $template = QueryTemplate::factory()->create([
        'slug' => 'invoices-over-amount',
        'parameters' => [
            'resource_key' => 'invoices',
            'fields' => 'Supplier,InvoiceNumber,InvoiceAmount',
            'orderBy' => 'InvoiceAmount:desc',
            'limit' => 100,
        ],
    ]);
    $originalParameters = $template->parameters;

    $this->actingAs($this->user)
        ->postJson(route('query-templates.preview', $template), [
            'tenant' => 'client_x',
            'parameter_values' => ['minimum_amount' => 2500],
        ])
        ->assertOk()
        ->assertJsonPath('tenant', 'client_x')
        ->assertJsonPath('parameters.q', 'InvoiceAmount > 2500')
        ->assertJsonPath('parameters.limit', 25)
        ->assertJsonPath('items.0.Supplier', 'Acme')
        ->assertJsonPath('error', null);

    Http::assertSent(function ($request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['q'] === 'InvoiceAmount > 2500'
            && $query['limit'] === '25';
    });

    expect($template->fresh()->parameters)->toBe($originalParameters);
    $this->assertDatabaseMissing('audit_events', [
        'action' => 'query_template.executed',
    ]);
});

test('run uses the full runtime limit and records a safe audit event', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0])]);
    $template = QueryTemplate::factory()->create([
        'parameter_definitions' => [
            [
                'key' => 'minimum_amount',
                'label' => 'Montant minimum',
                'type' => 'number',
                'required' => true,
                'default' => 1000,
                'min' => 0,
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'InvoiceAmount',
                    'operator' => '>',
                ],
            ],
            [
                'key' => 'result_limit',
                'label' => 'Limite',
                'type' => 'integer',
                'required' => true,
                'default' => 50,
                'min' => 1,
                'max' => 500,
                'binding' => ['kind' => 'parameter', 'key' => 'limit'],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('query-templates.run', $template), [
            'tenant' => 'client_x',
            'parameter_values' => [
                'minimum_amount' => 4000,
                'result_limit' => 80,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('parameters.q', 'InvoiceAmount > 4000')
        ->assertJsonPath('parameters.limit', 80)
        ->assertJsonPath('error', null);

    $event = AuditEvent::query()
        ->where('action', 'query_template.executed')
        ->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->subject_id)->toBe($template->id)
        ->and($event->context)->toMatchArray([
            'tenant_key' => 'client_x',
            'parameter_values' => [
                'minimum_amount' => 4000,
                'result_limit' => 80,
            ],
        ]);

    Http::assertSent(function ($request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['limit'] === '80';
    });
});

test('invalid runtime values are rejected before any Oracle request', function () {
    Http::preventStrayRequests();
    $template = QueryTemplate::factory()->create();

    $this->actingAs($this->user)
        ->postJson(route('query-templates.run', $template), [
            'tenant' => 'client_x',
            'parameter_values' => ['minimum_amount' => -1],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parameter_values.minimum_amount');

    Http::assertNothingSent();
});

test('cloning materialises parameters in an editable private query without altering the template', function () {
    $category = Category::factory()->create();
    $template = QueryTemplate::factory()->create([
        'name' => 'Factures importantes',
        'category_id' => $category->id,
    ]);
    $original = $template->only([
        'slug',
        'name',
        'description',
        'category_id',
        'resource_key',
        'resource_path',
        'parameters',
        'parameter_definitions',
        'is_active',
        'sort_order',
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('query-templates.clone', $template), [
            'tenant' => 'client_x',
            'parameter_values' => ['minimum_amount' => 7500],
        ]);

    $copy = Query::query()->where('user_id', $this->user->id)->sole();

    $response->assertRedirect(route('queries.edit', $copy));
    expect($copy->name)->toBe('Copie de Factures importantes')
        ->and($copy->visibility)->toBe('private')
        ->and($copy->query_template_id)->toBe($template->id)
        ->and($copy->category_id)->toBe($category->id)
        ->and($copy->tenant_key)->toBe('client_x')
        ->and($copy->parameters['q'])->toBe('InvoiceAmount > 7500');

    expect($template->fresh()->only(array_keys($original)))->toBe($original);

    $this->actingAs($this->user)
        ->get(route('queries.edit', $copy))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/edit')
            ->where('query.source_template.slug', $template->slug)
            ->where('query.source_template.name', $template->name));
});

test('template records reject application updates and deletes', function () {
    $template = QueryTemplate::factory()->create();

    expect(fn () => $template->update(['name' => 'Changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $template->delete())
        ->toThrow(LogicException::class);
});

test('the template seeder is user independent and idempotent', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);
    $ids = QueryTemplate::query()->orderBy('slug')->pluck('id', 'slug')->all();

    $this->seed(QueryTemplateSeeder::class);

    expect(QueryTemplate::query()->count())->toBe(4)
        ->and(QueryTemplate::query()->orderBy('slug')->pluck('id', 'slug')->all())->toBe($ids)
        ->and(QueryTemplate::query()->where('slug', 'customer-invoices-above-balance')->exists())->toBeTrue()
        ->and(QueryTemplate::query()->where('slug', 'open-purchase-orders-above-amount')->exists())->toBeFalse()
        ->and(QueryTemplate::query()->where('slug', 'supplier-invoices-above-amount')->value('parameter_definitions'))
        ->not->toBeNull();
});

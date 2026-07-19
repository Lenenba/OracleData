<?php

use App\Models\Query;
use App\Models\QueryTemplate;
use Database\Seeders\CategorySeeder;
use Database\Seeders\QueryTemplateSeeder;
use Inertia\Testing\AssertableInertia as Assert;

dataset('query template locales', [
    'french' => [
        'fr',
        'Fournisseurs par statut',
        'Affiche les fournisseurs correspondant au statut sélectionné.',
        'Statut du fournisseur',
        'Actif',
        'Inactif',
        'Nombre maximal de résultats',
    ],
    'english' => [
        'en',
        'Suppliers by status',
        'Shows suppliers matching the selected status.',
        'Supplier status',
        'Active',
        'Inactive',
        'Maximum number of results',
    ],
    'spanish' => [
        'es',
        'Proveedores por estado',
        'Muestra los proveedores que corresponden al estado seleccionado.',
        'Estado del proveedor',
        'Activo',
        'Inactivo',
        'Número máximo de resultados',
    ],
]);

test('the template index and detail page use the reader locale without changing technical values', function (
    string $locale,
    string $name,
    string $description,
    string $statusLabel,
    string $activeLabel,
    string $inactiveLabel,
    string $limitLabel,
) {
    $user = createConnectedUser(['locale' => $locale]);
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);

    $template = QueryTemplate::query()->where('slug', 'suppliers-by-status')->sole();

    $this->actingAs($user)
        ->get(route('query-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('query-templates/index')
            ->has('templates', 4)
            ->where('templates.3.slug', 'suppliers-by-status')
            ->where('templates.3.name', $name)
            ->where('templates.3.description', $description));

    $this->actingAs($user)
        ->get(route('query-templates.show', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('query-templates/show')
            ->where('template.name', $name)
            ->where('template.description', $description)
            ->where('template.resource_key', 'suppliers')
            ->where('template.parameter_definitions.0.key', 'supplier_status')
            ->where('template.parameter_definitions.0.label', $statusLabel)
            ->where('template.parameter_definitions.0.type', 'select')
            ->where('template.parameter_definitions.0.default', 'ACTIVE')
            ->where('template.parameter_definitions.0.binding.kind', 'filter')
            ->where('template.parameter_definitions.0.binding.field', 'Status')
            ->where('template.parameter_definitions.0.binding.operator', '=')
            ->where('template.parameter_definitions.0.options.0.value', 'ACTIVE')
            ->where('template.parameter_definitions.0.options.0.label', $activeLabel)
            ->where('template.parameter_definitions.0.options.1.value', 'INACTIVE')
            ->where('template.parameter_definitions.0.options.1.label', $inactiveLabel)
            ->where('template.parameter_definitions.1.key', 'result_limit')
            ->where('template.parameter_definitions.1.label', $limitLabel)
            ->where('template.parameter_definitions.1.binding.kind', 'parameter')
            ->where('template.parameter_definitions.1.binding.key', 'limit'));
})->with('query template locales');

test('template localization falls back field by field from the locale to french and then the base value', function () {
    $template = QueryTemplate::factory()->create([
        'name' => 'Base name',
        'description' => 'Base description',
        'parameter_definitions' => [
            [
                'key' => 'supplier_status',
                'label' => 'Base status',
                'description' => 'Base status description',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => 'ACTIVE', 'label' => 'Base active'],
                    ['value' => 'INACTIVE', 'label' => 'Base inactive'],
                ],
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'Status',
                    'operator' => '=',
                ],
            ],
            [
                'key' => 'result_limit',
                'label' => 'Base limit',
                'description' => 'Base limit description',
                'type' => 'integer',
                'required' => true,
                'binding' => ['kind' => 'parameter', 'key' => 'limit'],
            ],
        ],
    ]);
    $template->translations()->createMany([
        [
            'locale' => 'fr',
            'name' => 'Nom français',
            'description' => 'Description française',
            'parameter_labels' => ['supplier_status' => 'Statut français'],
            'parameter_descriptions' => ['supplier_status' => 'Description du statut en français'],
            'parameter_options' => ['supplier_status' => ['ACTIVE' => 'Actif français']],
        ],
        [
            'locale' => 'en',
            'name' => 'English name',
            'description' => null,
            'parameter_labels' => ['supplier_status' => 'English status'],
        ],
    ]);
    $template->load('translations');

    $english = $template->parameterDefinitionsFor('en');

    expect($template->nameFor('en'))->toBe('English name')
        ->and($template->descriptionFor('en'))->toBe('Description française')
        ->and($english[0]['label'])->toBe('English status')
        ->and($english[0]['description'])->toBe('Description du statut en français')
        ->and($english[0]['options'][0])->toBe(['value' => 'ACTIVE', 'label' => 'Actif français'])
        ->and($english[0]['options'][1])->toBe(['value' => 'INACTIVE', 'label' => 'Base inactive'])
        ->and($english[0]['binding'])->toBe(['kind' => 'filter', 'field' => 'Status', 'operator' => '='])
        ->and($english[1]['label'])->toBe('Base limit')
        ->and($english[1]['description'])->toBe('Base limit description')
        ->and($template->nameFor('es'))->toBe('Nom français')
        ->and($template->descriptionFor('es'))->toBe('Description française');

    $baseOnly = QueryTemplate::factory()->create([
        'name' => 'Untranslated base name',
        'description' => 'Untranslated base description',
    ]);

    expect($baseOnly->nameFor('es'))->toBe('Untranslated base name')
        ->and($baseOnly->descriptionFor('es'))->toBe('Untranslated base description');
});

test('a clone stores localized copy and source text and keeps showing the localized source while editing', function () {
    $user = createConnectedUser(['locale' => 'es'], [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ]);
    $this->seed(CategorySeeder::class);
    $this->seed(QueryTemplateSeeder::class);

    $template = QueryTemplate::query()->where('slug', 'suppliers-by-status')->sole();

    $response = $this->actingAs($user)
        ->post(route('query-templates.clone', $template), [
            'tenant' => 'client_x',
            'parameter_values' => [
                'supplier_status' => 'ACTIVE',
                'result_limit' => 40,
            ],
        ]);

    $copy = Query::query()->where('user_id', $user->id)->sole();

    $response->assertRedirect(route('queries.edit', $copy));
    expect($copy->name)->toBe('Copia de Proveedores por estado')
        ->and($copy->description)->toBe('Muestra los proveedores que corresponden al estado seleccionado.')
        ->and($copy->query_template_id)->toBe($template->id)
        ->and($copy->getAttribute('query_template_version_id'))->toBe($template->published_version_id)
        ->and($copy->parameters['q'])->toBe("Status = 'ACTIVE'");

    $this->actingAs($user)
        ->get(route('queries.edit', $copy))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/edit')
            ->where('query.source_template.slug', 'suppliers-by-status')
            ->where('query.source_template.name', 'Proveedores por estado'));
});

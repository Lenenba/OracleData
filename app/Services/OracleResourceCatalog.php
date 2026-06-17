<?php

namespace App\Services;

use Illuminate\Support\Str;

class OracleResourceCatalog
{
    /**
     * Oracle Fusion REST resources that can be selected from natural language.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     domain: string,
     *     method: string,
     *     path: string,
     *     keywords: list<string>,
     *     preview_fields: list<string>
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'suppliers',
                'label' => 'Liste des fournisseurs',
                'description' => 'Fournisseurs Oracle Procurement',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
                'keywords' => [
                    'fournisseur',
                    'fournisseurs',
                    'supplier',
                    'suppliers',
                    'vendeur',
                    'vendeurs',
                    'vendor',
                    'vendors',
                ],
                'preview_fields' => ['SupplierId', 'Supplier', 'SupplierNumber', 'Status'],
            ],
            [
                'key' => 'workers',
                'label' => 'Liste des employés',
                'description' => 'Employés Oracle HCM',
                'domain' => 'HCM',
                'method' => 'GET',
                'path' => '/hcmRestApi/resources/11.13.18.05/workers',
                'keywords' => [
                    'employe',
                    'employes',
                    'employé',
                    'employés',
                    'worker',
                    'workers',
                    'collaborateur',
                    'collaborateurs',
                    'personnel',
                    'salarie',
                    'salaries',
                    'salarié',
                    'salariés',
                ],
                'preview_fields' => ['PersonId', 'PersonNumber', 'DisplayName', 'WorkEmail'],
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     domain: string,
     *     method: string,
     *     path: string,
     *     keywords: list<string>,
     *     preview_fields: list<string>
     * }>
     */
    public function suggestions(): array
    {
        return array_map(
            fn (array $resource): array => $this->toSuggestion($resource),
            $this->all(),
        );
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     domain: string,
     *     method: string,
     *     path: string,
     *     keywords: list<string>,
     *     preview_fields: list<string>
     * }|null
     */
    public function match(string $intent): ?array
    {
        $normalizedIntent = $this->normalize($intent);

        if ($normalizedIntent === '') {
            return null;
        }

        foreach ($this->all() as $resource) {
            foreach ($resource['keywords'] as $keyword) {
                if (str_contains($normalizedIntent, $this->normalize($keyword))) {
                    return $resource;
                }
            }
        }

        return null;
    }

    /**
     * @return array{name: string, description: string, resource_path: string, parameters: array{limit: int}, visibility: string}|null
     */
    public function draft(string $intent, mixed $limit = null): ?array
    {
        $resource = $this->match($intent);

        if ($resource === null) {
            return null;
        }

        return [
            'name' => $resource['label'],
            'description' => trim($intent),
            'resource_path' => $resource['path'],
            'parameters' => ['limit' => $this->clampLimit($limit)],
            'visibility' => 'private',
        ];
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     domain: string,
     *     method: string,
     *     path: string,
     *     keywords: list<string>,
     *     preview_fields: list<string>
     * }  $resource
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     domain: string,
     *     method: string,
     *     path: string,
     *     keywords: list<string>,
     *     preview_fields: list<string>
     * }
     */
    public function toSuggestion(array $resource): array
    {
        return [
            'key' => $resource['key'],
            'label' => $resource['label'],
            'description' => $resource['description'],
            'domain' => $resource['domain'],
            'method' => $resource['method'],
            'path' => $resource['path'],
            'keywords' => $resource['keywords'],
            'preview_fields' => $resource['preview_fields'],
        ];
    }

    public function clampLimit(mixed $limit): int
    {
        $parsed = is_numeric($limit) ? (int) $limit : 25;

        return min(500, max(1, $parsed));
    }

    private function normalize(string $value): string
    {
        $normalized = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            Str::ascii(Str::lower($value)),
        );

        return trim($normalized ?? '');
    }
}

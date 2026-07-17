import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { QueryBuilder } from '@/components/queries/query-builder';
import { parseChildFields, parseCsv } from '@/lib/query-spec';
import type { ResourceSuggestion } from '@/lib/query-spec';
import queries from '@/routes/queries';

type EditQueryProps = {
    query: {
        id: number;
        name: string;
        description: string | null;
        resource_path: string | null;
        tenant_key: string | null;
        mode: 'single' | 'agent';
        parameters: Record<string, unknown>;
        visibility: 'private' | 'shared';
    };
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
};

export default function EditQuery({
    query,
    resourceSuggestions,
    tenants,
    defaultTenant,
}: EditQueryProps) {
    const params = query.parameters ?? {};

    const initialState = {
        queryId: query.id,
        name: query.name,
        visibility: query.visibility,
        resourceKey:
            typeof params.resource_key === 'string'
                ? params.resource_key
                : undefined,
        tenantKey: query.tenant_key ?? undefined,
        fields: parseCsv(params.fields),
        expand: parseCsv(params.expand),
        joins: parseCsv(params.joins),
        childFields: parseChildFields(params.child_fields),
        filterQ: typeof params.q === 'string' ? params.q : undefined,
        orderBy:
            typeof params.orderBy === 'string' ? params.orderBy : undefined,
        limit: typeof params.limit === 'number' ? params.limit : undefined,
    };

    return (
        <>
            <Head title={`Modifier — ${query.name}`} />

            <div className="px-6 py-6">
                <Heading
                    title={`Modifier : ${query.name}`}
                    description="Ajustez les paramètres — l'aperçu se met à jour en direct — puis enregistrez."
                />

                <QueryBuilder
                    resourceSuggestions={resourceSuggestions}
                    tenants={tenants}
                    defaultTenant={defaultTenant}
                    mode="edit"
                    initialState={initialState}
                />
            </div>
        </>
    );
}

EditQuery.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Modifier', href: '#' },
    ],
};

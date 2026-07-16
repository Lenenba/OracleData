import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import type { ResourceSuggestion } from '@/components/queries/query-form';
import { QueryWizard } from '@/components/queries/query-wizard';
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

/**
 * Parse CSV string stored in parameters.fields / parameters.expand back to string[].
 */
function parseCsv(value: unknown): string[] {
    if (typeof value === 'string' && value.trim()) {
        return value.split(',').map((s) => s.trim()).filter(Boolean);
    }

    if (Array.isArray(value)) {
        return (value as unknown[]).map(String).filter(Boolean);
    }

    return [];
}

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
        resourceKey: typeof params.resource_key === 'string' ? params.resource_key : undefined,
        tenantKey: query.tenant_key ?? undefined,
        fields: parseCsv(params.fields),
        expand: parseCsv(params.expand),
        filterQ: typeof params.q === 'string' ? params.q : undefined,
        orderBy: typeof params.orderBy === 'string' ? params.orderBy : undefined,
        limit: typeof params.limit === 'number' ? params.limit : undefined,
    };

    return (
        <>
            <Head title={`Modifier — ${query.name}`} />

            <div className="px-4 py-6">
                <Heading
                    title={`Modifier : ${query.name}`}
                    description="Ajustez les paramètres, testez, puis enregistrez les modifications."
                />

                <QueryWizard
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

import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { QueryBuilder } from '@/components/queries/query-builder';
import type { ResourceSuggestion } from '@/lib/query-spec';
import queries from '@/routes/queries';

type CreateQueryProps = {
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
};

export default function CreateQuery({
    resourceSuggestions,
    tenants,
    defaultTenant,
}: CreateQueryProps) {
    return (
        <>
            <Head title="Nouvelle requête" />

            <div className="px-6 py-6">
                <Heading
                    title="Nouvelle requête"
                    description="Configurez votre requête Oracle : l'aperçu se met à jour en direct à chaque modification."
                />

                <QueryBuilder
                    resourceSuggestions={resourceSuggestions}
                    tenants={tenants}
                    defaultTenant={defaultTenant}
                />
            </div>
        </>
    );
}

CreateQuery.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Nouvelle requête', href: queries.create() },
    ],
};

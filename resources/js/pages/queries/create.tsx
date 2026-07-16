import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import type { ResourceSuggestion } from '@/components/queries/query-form';
import { QueryWizard } from '@/components/queries/query-wizard';
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

            <div className="px-4 py-6">
                <Heading
                    title="Nouvelle requête"
                    description="Suivez les 3 étapes pour configurer, prévisualiser et enregistrer votre requête Oracle."
                />

                <QueryWizard
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

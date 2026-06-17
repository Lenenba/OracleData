import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { QueryForm } from '@/components/queries/query-form';
import type { ResourceSuggestion } from '@/components/queries/query-form';
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
                    description="Décrivez les données Oracle à consulter, puis vérifiez l'aperçu avant d'enregistrer."
                />

                <Form {...queries.store.form()} className="max-w-2xl">
                    {({ processing, errors }) => (
                        <QueryForm
                            errors={errors}
                            processing={processing}
                            submitLabel="Enregistrer"
                            resourceSuggestions={resourceSuggestions}
                            tenants={tenants}
                            defaultTenant={defaultTenant}
                        />
                    )}
                </Form>
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

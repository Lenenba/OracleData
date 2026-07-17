import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { QueryBuilder } from '@/components/queries/query-builder';
import { useI18n } from '@/i18n/i18n-context';
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
    const { t } = useI18n();

    return (
        <>
            <Head title={t('queries.create')} />

            <div className="px-6 py-6">
                <Heading
                    title={t('queries.create')}
                    description={t('queries.createDescription')}
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

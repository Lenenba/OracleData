import { Head, Link } from '@inertiajs/react';
import { CopyCheck } from 'lucide-react';
import Heading from '@/components/heading';
import { QueryBuilder } from '@/components/queries/query-builder';
import type {
    QueryCategoryOption,
    QueryTagOption,
} from '@/components/queries/query-builder';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useI18n } from '@/i18n/i18n-context';
import { parseChildFields, parseCsv } from '@/lib/query-spec';
import type { ResourceSuggestion } from '@/lib/query-spec';
import queries from '@/routes/queries';
import queryTemplates from '@/routes/query-templates';

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
        category_id: number | null;
        tags: string[];
        source_template: { slug: string; name: string } | null;
    };
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
    categories: QueryCategoryOption[];
    tags: QueryTagOption[];
};

export default function EditQuery({
    query,
    resourceSuggestions,
    tenants,
    defaultTenant,
    categories,
    tags,
}: EditQueryProps) {
    const { t } = useI18n();
    const params = query.parameters ?? {};

    const initialState = {
        queryId: query.id,
        name: query.name,
        description: query.description,
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
        categoryId: query.category_id,
        tags: query.tags,
    };

    return (
        <>
            <Head title={t('queries.editTitle', { name: query.name })} />

            <div className="px-6 py-6">
                <Heading
                    title={t('queries.editTitle', { name: query.name })}
                    description={t('queries.editDescription')}
                />

                {query.source_template && (
                    <Alert className="mb-6">
                        <CopyCheck />
                        <AlertTitle>
                            {t('templates.personalCopyTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('templates.personalCopyNotice', {
                                name: query.source_template.name,
                            })}{' '}
                            <Link
                                href={queryTemplates.show(
                                    query.source_template.slug,
                                )}
                                className="font-medium underline underline-offset-4"
                            >
                                {t('templates.viewOriginal')}
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                <QueryBuilder
                    resourceSuggestions={resourceSuggestions}
                    tenants={tenants}
                    defaultTenant={defaultTenant}
                    mode="edit"
                    initialState={initialState}
                    categories={categories}
                    tagSuggestions={tags}
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

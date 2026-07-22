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
import type { QueryAccessLevel } from '@/types/query-sharing';

type EditQueryProps = {
    query: {
        id: number;
        name: string;
        description: string | null;
        resource_path: string | null;
        tenant_key: string | null;
        mode: 'single' | 'agent';
        parameters: Record<string, unknown>;
        resolved_resource_key: string | null;
        access_level: QueryAccessLevel;
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

/**
 * Returns true when `path` is a valid Oracle HCM/FSCM REST path that is deeper
 * than a root resource — i.e. it contains additional segments after the resource
 * name (tenant-specific IDs, /child/ sub-paths, etc.).
 *
 * These paths are valid and safe but are not in the catalogue root, so the
 * FreeformEditor should show an informational banner rather than a warning.
 */
function isDeepOraclePath(path: string | null): boolean {
    if (path === null) return false;
    // Root catalogue paths have exactly 4 segments:
    //   /hcmRestApi/resources/<version>/<resourceName>
    // Deep paths have more segments (tenant IDs, /child/, etc.)
    return /^\/(?:hcm|fscm)RestApi\/resources\/(?:latest|\d+(?:\.\d+){3})\/[^/]+\/.+/.test(
        path,
    );
}

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

    // resource_key can come from parameters (wizard-created query) or from the
    // backend resolution against the catalogue (Postman/custom-imported query).
    const resolvedResourceKey =
        (typeof params.resource_key === 'string' ? params.resource_key : null) ??
        query.resolved_resource_key ??
        undefined;

    // Split expand into simple names (handled by wizard checkboxes) and nested
    // dot-paths like "workRelationships.assignments.managers" (passed verbatim
    // to Oracle; not editable in the wizard UI but must survive a save).
    const allExpand = parseCsv(params.expand);
    const wizardExpand = allExpand.filter((e) => !e.includes('.'));
    const nestedExpand = allExpand.filter((e) => e.includes('.'));

    const rawPath =
        resolvedResourceKey == null ? (query.resource_path ?? undefined) : undefined;

    const initialState = {
        queryId: query.id,
        name: query.name,
        description: query.description,
        accessLevel: query.access_level,
        resourceKey: resolvedResourceKey,
        rawResourcePath: rawPath,
        isDeepOraclePath: rawPath != null ? isDeepOraclePath(rawPath) : false,
        tenantKey: query.tenant_key ?? undefined,
        fields: parseCsv(params.fields),
        expand: wizardExpand,
        nestedExpand,
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

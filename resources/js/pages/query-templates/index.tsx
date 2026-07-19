import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FileSliders, Search, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/i18n/i18n-context';
import queryTemplates from '@/routes/query-templates';
import type { QueryTemplateSummary } from '@/types/query-template';

export default function QueryTemplateIndex({
    templates,
}: {
    templates: QueryTemplateSummary[];
}) {
    const { t } = useI18n();
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const categories = useMemo(
        () =>
            Array.from(
                new Map(
                    templates
                        .filter((template) => template.category !== null)
                        .map((template) => [
                            template.category!.slug,
                            template.category!.name,
                        ]),
                ),
            ).sort((left, right) => left[1].localeCompare(right[1])),
        [templates],
    );
    const needle = search.trim().toLocaleLowerCase();
    const filtered = templates.filter((template) => {
        const matchesCategory =
            category === 'all' || template.category?.slug === category;
        const haystack = [
            template.name,
            template.description,
            template.category?.name,
            template.resource?.label,
            template.resource?.domain,
        ]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase();

        return matchesCategory && (needle === '' || haystack.includes(needle));
    });

    return (
        <>
            <Head title={t('templates.title')} />

            <div className="space-y-6 px-6 py-6">
                <Heading
                    title={t('templates.title')}
                    description={t('templates.description')}
                />

                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('templates.search')}
                            aria-label={t('templates.search')}
                            className="pl-9"
                        />
                    </div>
                    <Select value={category} onValueChange={setCategory}>
                        <SelectTrigger
                            className="w-full sm:w-60"
                            aria-label={t('templates.allCategories')}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('templates.allCategories')}
                            </SelectItem>
                            {categories.map(([slug, name]) => (
                                <SelectItem key={slug} value={slug}>
                                    {name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                        {t('templates.empty')}
                    </div>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        {filtered.map((template) => (
                            <Card key={template.slug} className="gap-4">
                                <CardHeader className="gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">
                                            <FileSliders className="size-3" />
                                            {t('templates.badge')}
                                        </Badge>
                                        {template.is_certified && (
                                            <Badge>
                                                <ShieldCheck
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                                {t('templates.certified')}
                                            </Badge>
                                        )}
                                        {template.category && (
                                            <Badge
                                                variant="outline"
                                                style={
                                                    template.category.color
                                                        ? {
                                                              borderColor:
                                                                  template
                                                                      .category
                                                                      .color,
                                                              color: template
                                                                  .category
                                                                  .color,
                                                          }
                                                        : undefined
                                                }
                                            >
                                                {template.category.name}
                                            </Badge>
                                        )}
                                    </div>
                                    <CardTitle className="leading-snug">
                                        {template.name}
                                    </CardTitle>
                                    <CardDescription className="line-clamp-3">
                                        {template.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="mt-auto flex flex-wrap gap-2 text-xs text-muted-foreground">
                                    <span>
                                        {template.resource?.label ??
                                            template.resource_key}
                                    </span>
                                    <span>•</span>
                                    <span>
                                        {t('templates.parametersCount', {
                                            count: template
                                                .parameter_definitions.length,
                                        })}
                                    </span>
                                </CardContent>
                                <CardFooter>
                                    <Button asChild className="w-full">
                                        <Link
                                            href={queryTemplates.show(
                                                template.slug,
                                            )}
                                        >
                                            {t('templates.configure')}
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

QueryTemplateIndex.layout = {
    breadcrumbs: [
        { title: 'Modèles prédéfinis', href: queryTemplates.index() },
    ],
};

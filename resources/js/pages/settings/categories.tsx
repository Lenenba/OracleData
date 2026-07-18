import { Form, Head, router } from '@inertiajs/react';
import { FolderTree, Tags, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import categoriesRoutes from '@/routes/categories';
import tagsRoutes from '@/routes/tags';

type CategoryTranslation = {
    name?: string;
    description?: string | null;
};

type TagTranslation = {
    name?: string;
};

type CategoryItem = {
    id: number;
    slug: string;
    color: string | null;
    queries_count: number;
    translations: Partial<Record<'fr' | 'en' | 'es', CategoryTranslation>>;
};

type TagItem = {
    id: number;
    slug: string;
    name: string;
    queries_count: number;
    translations: Partial<Record<'fr' | 'en' | 'es', TagTranslation>>;
};

type TaxonomyProps = {
    categories: CategoryItem[];
    tags: TagItem[];
};

const LOCALES = [
    { value: 'fr', label: 'taxonomy.french' },
    { value: 'en', label: 'taxonomy.english' },
    { value: 'es', label: 'taxonomy.spanish' },
] as const;

function CategoryForm({ category }: { category?: CategoryItem }) {
    const { t } = useI18n();
    const form = category
        ? categoriesRoutes.update.form(category.id)
        : categoriesRoutes.store.form();
    const sourceName = category?.translations.fr?.name ?? category?.slug ?? '';

    function remove() {
        if (
            category &&
            confirm(t('taxonomy.deleteConfirm', { name: sourceName }))
        ) {
            router.delete(categoriesRoutes.destroy.url(category.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!category}
            className="space-y-4 rounded-xl border bg-card p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`category-slug-${category?.id ?? 'new'}`}
                            >
                                {t('taxonomy.slug')}
                            </Label>
                            <Input
                                id={`category-slug-${category?.id ?? 'new'}`}
                                name="slug"
                                defaultValue={category?.slug}
                                placeholder="finance"
                                pattern="[a-z0-9][a-z0-9-]*"
                                required
                            />
                            <InputError message={errors.slug} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`category-color-${category?.id ?? 'new'}`}
                            >
                                {t('taxonomy.color')}
                            </Label>
                            <Input
                                id={`category-color-${category?.id ?? 'new'}`}
                                name="color"
                                type="text"
                                defaultValue={category?.color ?? ''}
                                placeholder="#4f46e5"
                                pattern="#[0-9A-Fa-f]{6}"
                                maxLength={7}
                                className="w-32 font-mono"
                            />
                            <InputError message={errors.color} />
                        </div>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        {LOCALES.map((locale) => (
                            <div
                                key={locale.value}
                                className="space-y-3 rounded-lg border p-3"
                            >
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t(locale.label)}
                                </p>
                                <div className="grid gap-2">
                                    <Label
                                        htmlFor={`category-${category?.id ?? 'new'}-${locale.value}-name`}
                                    >
                                        {t('taxonomy.name')}
                                    </Label>
                                    <Input
                                        id={`category-${category?.id ?? 'new'}-${locale.value}-name`}
                                        name={`translations[${locale.value}][name]`}
                                        defaultValue={
                                            category?.translations[locale.value]
                                                ?.name ?? ''
                                        }
                                        required={locale.value === 'fr'}
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `translations.${locale.value}.name`
                                            ]
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label
                                        htmlFor={`category-${category?.id ?? 'new'}-${locale.value}-description`}
                                    >
                                        {t('taxonomy.descriptionLabel')}
                                    </Label>
                                    <Textarea
                                        id={`category-${category?.id ?? 'new'}-${locale.value}-description`}
                                        name={`translations[${locale.value}][description]`}
                                        defaultValue={
                                            category?.translations[locale.value]
                                                ?.description ?? ''
                                        }
                                        rows={2}
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `translations.${locale.value}.description`
                                            ]
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        <span className="text-xs text-muted-foreground">
                            {category
                                ? t('taxonomy.uses', {
                                      count: category.queries_count,
                                  })
                                : t('taxonomy.newCategory')}
                        </span>
                        <div className="flex gap-2">
                            {category && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={remove}
                                    className="text-destructive hover:text-destructive"
                                >
                                    <Trash2 />
                                    {t('common.delete')}
                                </Button>
                            )}
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {category
                                    ? t('taxonomy.update')
                                    : t('taxonomy.create')}
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}

function TagForm({ tag }: { tag?: TagItem }) {
    const { t } = useI18n();
    const form = tag ? tagsRoutes.update.form(tag.id) : tagsRoutes.store.form();
    const sourceName =
        tag?.translations.fr?.name ?? tag?.name ?? tag?.slug ?? '';

    function remove() {
        if (tag && confirm(t('taxonomy.deleteConfirm', { name: sourceName }))) {
            router.delete(tagsRoutes.destroy.url(tag.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={!tag}
            className="space-y-4 rounded-xl border bg-card p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`tag-slug-${tag?.id ?? 'new'}`}>
                            {t('taxonomy.slug')}
                        </Label>
                        <Input
                            id={`tag-slug-${tag?.id ?? 'new'}`}
                            name="slug"
                            defaultValue={tag?.slug}
                            placeholder="mensuel"
                            pattern="[a-z0-9][a-z0-9-]*"
                            required
                        />
                        <InputError message={errors.slug} />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        {LOCALES.map((locale) => (
                            <div
                                key={locale.value}
                                className="grid gap-2 rounded-lg border p-3"
                            >
                                <Label
                                    htmlFor={`tag-${tag?.id ?? 'new'}-${locale.value}-name`}
                                >
                                    {t(locale.label)}
                                </Label>
                                <Input
                                    id={`tag-${tag?.id ?? 'new'}-${locale.value}-name`}
                                    name={`translations[${locale.value}][name]`}
                                    defaultValue={
                                        tag?.translations[locale.value]?.name ??
                                        (locale.value === 'fr'
                                            ? tag?.name
                                            : '') ??
                                        ''
                                    }
                                    required={locale.value === 'fr'}
                                />
                                <InputError
                                    message={
                                        errors[
                                            `translations.${locale.value}.name`
                                        ]
                                    }
                                />
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        <span className="text-xs text-muted-foreground">
                            {tag
                                ? t('taxonomy.uses', {
                                      count: tag.queries_count,
                                  })
                                : t('taxonomy.newTag')}
                        </span>
                        <div className="flex gap-2">
                            {tag && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={remove}
                                    className="text-destructive hover:text-destructive"
                                >
                                    <Trash2 />
                                    {t('common.delete')}
                                </Button>
                            )}
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {tag
                                    ? t('taxonomy.update')
                                    : t('taxonomy.create')}
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function TaxonomySettings({ categories, tags }: TaxonomyProps) {
    const { t } = useI18n();

    return (
        <>
            <Head title={t('taxonomy.title')} />
            <h1 className="sr-only">{t('taxonomy.title')}</h1>

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title={t('taxonomy.title')}
                    description={t('taxonomy.description')}
                />

                <section className="space-y-4">
                    <div className="flex items-center gap-2">
                        <FolderTree className="size-5" />
                        <h2 className="font-semibold">
                            {t('taxonomy.categories')}
                        </h2>
                        <Badge variant="secondary">{categories.length}</Badge>
                    </div>
                    <CategoryForm />
                    {categories.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t('taxonomy.emptyCategories')}
                        </p>
                    ) : (
                        categories.map((category) => (
                            <CategoryForm
                                key={category.id}
                                category={category}
                            />
                        ))
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex items-center gap-2">
                        <Tags className="size-5" />
                        <h2 className="font-semibold">{t('taxonomy.tags')}</h2>
                        <Badge variant="secondary">{tags.length}</Badge>
                    </div>
                    <TagForm />
                    {tags.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t('taxonomy.emptyTags')}
                        </p>
                    ) : (
                        tags.map((tag) => <TagForm key={tag.id} tag={tag} />)
                    )}
                </section>
            </div>
        </>
    );
}

TaxonomySettings.layout = {
    breadcrumbs: [
        {
            title: 'Catégories et tags',
            href: categoriesRoutes.index(),
        },
    ],
};

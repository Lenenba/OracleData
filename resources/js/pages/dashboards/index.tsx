import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Clock3,
    LayoutGrid,
    LayoutPanelTop,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/i18n/i18n-context';
import dashboards from '@/routes/dashboards';

type DashboardRow = {
    id: number;
    name: string;
    description: string | null;
    widget_count: number;
    updated_at: string | null;
};

export default function DashboardsIndex({
    dashboards: items,
}: {
    dashboards: DashboardRow[];
}) {
    const { t, formatDate, formatNumber } = useI18n();
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const widgetCount = items.reduce(
        (total, item) => total + item.widget_count,
        0,
    );
    const populatedCount = items.filter((item) => item.widget_count > 0).length;

    function confirmDelete() {
        if (deleteId === null) {
            return;
        }

        router.delete(dashboards.destroy(deleteId), {
            preserveScroll: true,
            onFinish: () => setDeleteId(null),
        });
    }

    return (
        <>
            <Head title={t('dashboards.pageTitle')} />

            <div className="p-5">
                <Heading
                    title={t('dashboards.pageTitle')}
                    description="Organisez vos indicateurs Oracle dans des espaces de pilotage personnalisés."
                    actions={
                        <Button asChild>
                            <Link href={dashboards.create()}>
                                <Plus /> {t('dashboards.create')}
                            </Link>
                        </Button>
                    }
                />

                <div className="mb-5 grid gap-5 md:grid-cols-3">
                    {[
                        {
                            label: 'Tableaux de bord',
                            value: items.length,
                            icon: LayoutPanelTop,
                            tone: 'bg-primary/15 text-primary',
                        },
                        {
                            label: 'Widgets configurés',
                            value: widgetCount,
                            icon: LayoutGrid,
                            tone: 'bg-purple/15 text-purple',
                        },
                        {
                            label: 'Espaces actifs',
                            value: populatedCount,
                            icon: BarChart3,
                            tone: 'bg-success/15 text-success',
                        },
                    ].map(({ label, value, icon: Icon, tone }) => (
                        <div key={label} className="card">
                            <div className="card-body flex items-center gap-4">
                                <span
                                    className={`grid size-11 place-items-center rounded-full ${tone}`}
                                >
                                    <Icon className="size-5" />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        {label}
                                    </p>
                                    <strong className="mt-1 block text-2xl font-semibold tabular-nums">
                                        {formatNumber(value)}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <section className="card">
                    <div className="card-header justify-between">
                        <div>
                            <h2 className="card-title">
                                Mes espaces Analytics
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Ouvrez un tableau pour consulter et réorganiser
                                ses widgets.
                            </p>
                        </div>
                        <Badge variant="secondary">
                            {items.length} au total
                        </Badge>
                    </div>

                    {items.length === 0 ? (
                        <div className="card-body flex min-h-72 flex-col items-center justify-center text-center">
                            <span className="mb-4 grid size-14 place-items-center rounded-full bg-primary/10 text-primary">
                                <LayoutPanelTop className="size-7" />
                            </span>
                            <h3 className="text-base font-semibold">
                                {t('dashboards.empty')}
                            </h3>
                            <p className="mt-1 max-w-md text-xs text-muted-foreground">
                                {t('dashboards.emptyDescription')}
                            </p>
                            <Button asChild size="sm" className="mt-4">
                                <Link href={dashboards.create()}>
                                    <Plus /> {t('dashboards.create')}
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="card-body grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                            {items.map((item, index) => (
                                <article
                                    key={item.id}
                                    className="group overflow-hidden rounded border border-border bg-card transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <Link
                                        href={dashboards.show(item.id)}
                                        className="block"
                                    >
                                        <div
                                            className={`h-1.5 ${['bg-primary', 'bg-purple', 'bg-success', 'bg-info'][index % 4]}`}
                                        />
                                        <div className="p-4">
                                            <div className="flex items-start gap-3">
                                                <span className="grid size-10 shrink-0 place-items-center rounded bg-muted text-primary">
                                                    <LayoutPanelTop className="size-5" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <h3 className="truncate text-[15px] font-semibold group-hover:text-primary">
                                                        {item.name}
                                                    </h3>
                                                    <p className="mt-1 line-clamp-2 min-h-10 text-xs leading-5 text-muted-foreground">
                                                        {item.description ||
                                                            'Espace de visualisation personnalisé.'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </Link>
                                    <div className="flex items-center justify-between border-t border-dashed border-border px-4 py-3">
                                        <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <LayoutGrid className="size-3.5" />{' '}
                                                {t('dashboards.widgetCount', {
                                                    count: item.widget_count,
                                                })}
                                            </span>
                                            {item.updated_at && (
                                                <span className="flex items-center gap-1">
                                                    <Clock3 className="size-3.5" />{' '}
                                                    {formatDate(
                                                        item.updated_at,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex gap-1">
                                            <Button
                                                asChild
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                            >
                                                <Link
                                                    href={dashboards.edit(
                                                        item.id,
                                                    )}
                                                    aria-label={t(
                                                        'dashboards.editTitle',
                                                    )}
                                                >
                                                    <Pencil className="size-3.5" />
                                                </Link>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                onClick={() =>
                                                    setDeleteId(item.id)
                                                }
                                                aria-label={t(
                                                    'dashboards.delete',
                                                )}
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>

            <Dialog
                open={deleteId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteId(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('dashboards.deleteConfirm')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('dashboards.emptyDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleteId(null)}
                        >
                            {t('dashboards.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete}>
                            {t('dashboards.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

DashboardsIndex.layout = {
    breadcrumbs: [{ title: 'Dashboards', href: '/dashboards' }],
};

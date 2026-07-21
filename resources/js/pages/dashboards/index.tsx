import { Head, Link, router } from '@inertiajs/react';
import { LayoutPanelTop, Pencil, Plus, Trash2 } from 'lucide-react';
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
    const { t, formatDate } = useI18n();
    const [deleteId, setDeleteId] = useState<number | null>(null);

    function confirmDelete() {
        if (deleteId === null) return;
        router.delete(dashboards.destroy(deleteId), {
            preserveScroll: true,
            onFinish: () => setDeleteId(null),
        });
    }

    return (
        <>
            <Head title={t('dashboards.pageTitle')} />

            <div className="px-6 py-6">
                <Heading
                    title={t('dashboards.pageTitle')}
                    actions={
                        <Button asChild size="sm">
                            <Link href={dashboards.create()}>
                                <Plus className="size-4" />
                                {t('dashboards.create')}
                            </Link>
                        </Button>
                    }
                />

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <LayoutPanelTop className="size-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            {t('dashboards.empty')}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('dashboards.emptyDescription')}
                        </p>
                        <Button asChild size="sm" className="mt-2">
                            <Link href={dashboards.create()}>
                                <Plus className="size-4" />
                                {t('dashboards.create')}
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {items.map((item) => (
                            <Link
                                key={item.id}
                                href={dashboards.show(item.id)}
                                className="group block rounded-xl border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-accent/30"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium group-hover:text-primary">
                                            {item.name}
                                        </p>
                                        {item.description && (
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {item.description}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            onClick={(e) => e.preventDefault()}
                                        >
                                            <Link href={dashboards.edit(item.id)}>
                                                <Pencil className="size-3.5" />
                                            </Link>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                setDeleteId(item.id);
                                            }}
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                </div>

                                <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Badge variant="secondary" className="text-xs">
                                        {t('dashboards.widgetCount', { count: item.widget_count })}
                                    </Badge>
                                    {item.updated_at && (
                                        <span>{formatDate(item.updated_at)}</span>
                                    )}
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>

            {/* Delete confirmation */}
            <Dialog open={deleteId !== null} onOpenChange={(open) => { if (!open) setDeleteId(null); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('dashboards.deleteConfirm')}</DialogTitle>
                        <DialogDescription>
                            {t('dashboards.emptyDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteId(null)}>
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

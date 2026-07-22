import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LayoutDashboard, MonitorCog } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import dashboards from '@/routes/dashboards';

type DashboardEditProps = {
    dashboard: { id: number; name: string; description: string | null };
};

export default function DashboardsEdit({ dashboard }: DashboardEditProps) {
    const { t } = useI18n();
    const [name, setName] = useState(dashboard.name);
    const [description, setDescription] = useState(dashboard.description ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        router.put(
            dashboards.update.url(dashboard.id),
            { name, description: description || null },
            {
                onError: setErrors,
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <>
            <Head title={t('dashboards.editTitle')} />
            <div className="p-5">
                <Heading
                    title={t('dashboards.editTitle')}
                    description="Mettez à jour l’identité de votre espace Analytics."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={dashboards.show(dashboard.id)}>
                                <LayoutDashboard /> Ouvrir le tableau
                            </Link>
                        </Button>
                    }
                />
                <div className="grid gap-5 xl:grid-cols-12">
                    <form onSubmit={submit} className="card xl:col-span-8">
                        <div className="card-header">
                            <h2 className="card-title">Paramètres généraux</h2>
                        </div>
                        <div className="card-body space-y-5">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('dashboards.name')}
                                </Label>
                                <Input
                                    id="name"
                                    value={name}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    placeholder={t(
                                        'dashboards.namePlaceholder',
                                    )}
                                    required
                                />
                                {errors.name && (
                                    <p className="text-xs text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    {t('dashboards.description')}
                                </Label>
                                <Textarea
                                    id="description"
                                    value={description}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                    placeholder={t(
                                        'dashboards.descriptionPlaceholder',
                                    )}
                                    rows={5}
                                />
                            </div>
                        </div>
                        <div className="card-footer justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => window.history.back()}
                            >
                                <ArrowLeft /> {t('dashboards.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={saving || name.trim() === ''}
                            >
                                {t('dashboards.update')}
                            </Button>
                        </div>
                    </form>
                    <aside className="card h-fit xl:col-span-4">
                        <div className="card-body">
                            <span className="grid size-11 place-items-center rounded-full bg-info/15 text-info">
                                <MonitorCog className="size-5" />
                            </span>
                            <h3 className="mt-4 text-base font-semibold">
                                Composition conservée
                            </h3>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                Changer le nom ou la description ne modifie ni
                                les widgets ni leur disposition actuelle.
                            </p>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

DashboardsEdit.layout = {
    breadcrumbs: [
        { title: 'Dashboards', href: '/dashboards' },
        { title: 'Modifier', href: '#' },
    ],
};

import { Head, router } from '@inertiajs/react';
import { ArrowLeft, LayoutDashboard, Sparkles } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import dashboards from '@/routes/dashboards';

export default function DashboardsCreate() {
    const { t } = useI18n();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        router.post(
            dashboards.store.url(),
            { name, description: description || null },
            {
                onError: setErrors,
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <>
            <Head title={t('dashboards.createTitle')} />
            <div className="p-5">
                <Heading
                    title={t('dashboards.createTitle')}
                    description="Créez l’espace qui accueillera vos widgets, graphiques et résultats Oracle."
                />
                <div className="grid gap-5 xl:grid-cols-12">
                    <form onSubmit={submit} className="card xl:col-span-8">
                        <div className="card-header">
                            <h2 className="card-title">
                                Informations du tableau de bord
                            </h2>
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
                                    autoFocus
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
                                <p className="text-xs text-muted-foreground">
                                    Une description claire aide votre équipe à
                                    comprendre le rôle de cet espace.
                                </p>
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
                                {t('dashboards.save')}
                            </Button>
                        </div>
                    </form>
                    <aside className="card h-fit xl:col-span-4">
                        <div className="card-body text-center">
                            <span className="mx-auto grid size-16 place-items-center rounded-full bg-primary/10 text-primary">
                                <LayoutDashboard className="size-8" />
                            </span>
                            <h3 className="mt-4 text-base font-semibold">
                                Un espace prêt à composer
                            </h3>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                Après la création, ajoutez des widgets depuis
                                vos requêtes enregistrées et organisez-les
                                librement.
                            </p>
                            <div className="mt-5 flex items-center gap-2 rounded bg-purple/10 p-3 text-left text-xs text-purple">
                                <Sparkles className="size-5 shrink-0" /> Les
                                changements de disposition sont enregistrés
                                automatiquement.
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

DashboardsCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboards', href: '/dashboards' },
        { title: 'Créer', href: '#' },
    ],
};

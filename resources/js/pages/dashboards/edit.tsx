import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import dashboards from '@/routes/dashboards';

type DashboardEditProps = {
    dashboard: {
        id: number;
        name: string;
        description: string | null;
    };
};

export default function DashboardsEdit({ dashboard }: DashboardEditProps) {
    const { t } = useI18n();
    const [name, setName] = useState(dashboard.name);
    const [description, setDescription] = useState(dashboard.description ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function submit(e: React.FormEvent) {
        e.preventDefault();

        setSaving(true);
        setErrors({});

        router.put(
            dashboards.update.url(dashboard.id),
            { name, description: description || null },
            {
                onError: (errs) => setErrors(errs),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <>
            <Head title={t('dashboards.editTitle')} />

            <div className="px-6 py-6">
                <Heading title={t('dashboards.editTitle')} />

                <form onSubmit={submit} className="mt-6 max-w-lg space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('dashboards.name')}</Label>
                        <Input
                            id="name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder={t('dashboards.namePlaceholder')}
                            required
                        />
                        {errors.name && (
                            <p className="text-xs text-destructive">{errors.name}</p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">{t('dashboards.description')}</Label>
                        <Textarea
                            id="description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder={t('dashboards.descriptionPlaceholder')}
                            rows={3}
                        />
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={saving || name.trim() === ''}>
                            {t('dashboards.update')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            {t('dashboards.cancel')}
                        </Button>
                    </div>
                </form>
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

import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Save, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import oracleTenants from '@/routes/oracle-tenants';
import type { EditableOracleTenant } from '@/types';

type TenantEditProps = {
    tenant: EditableOracleTenant;
};

export default function EditOracleTenant({ tenant }: TenantEditProps) {
    const { t, formatDate } = useI18n();
    const [isDefault, setIsDefault] = useState(tenant.is_default);
    const [isActive, setIsActive] = useState(tenant.is_active);

    return (
        <>
            <Head title={t('connections.editTitle', { name: tenant.label })} />

            <div className="space-y-6">
                <Heading
                    title={t('connections.editTitle', { name: tenant.label })}
                    description={t('connections.editDescription')}
                />

                <Alert
                    className={
                        tenant.verified_at
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : 'border-amber-500/30 bg-amber-500/5'
                    }
                >
                    {tenant.verified_at ? <CheckCircle2 /> : <ShieldAlert />}
                    <AlertTitle>
                        {tenant.verified_at
                            ? t('connections.verified')
                            : t('connections.notVerified')}
                    </AlertTitle>
                    <AlertDescription>
                        {tenant.verified_at
                            ? t('connections.verifiedAt', {
                                  date: formatDate(tenant.verified_at, {
                                      dateStyle: 'long',
                                      timeStyle: 'short',
                                  }),
                              })
                            : t('connections.neverTested')}
                    </AlertDescription>
                </Alert>

                <Form
                    {...oracleTenants.update.form(tenant.id)}
                    className="max-w-2xl space-y-5"
                    disableWhileProcessing
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="tenant-key">
                                        {t('connections.immutableKey')}
                                    </Label>
                                    <Input
                                        id="tenant-key"
                                        value={tenant.key}
                                        disabled
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label>{t('connections.authType')}</Label>
                                    <div className="flex h-9 items-center gap-2 rounded-md border bg-muted/40 px-3 text-sm">
                                        <KeyRound className="size-4 text-muted-foreground" />
                                        <span>
                                            {tenant.auth_type.toLowerCase() ===
                                            'basic'
                                                ? t('connections.authBasic')
                                                : tenant.auth_type}
                                        </span>
                                        <Badge
                                            variant="secondary"
                                            className="ml-auto"
                                        >
                                            {t('connections.primary')}
                                        </Badge>
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="label">
                                    {t('connections.environmentLabel')}
                                </Label>
                                <Input
                                    id="label"
                                    name="label"
                                    defaultValue={tenant.label}
                                    required
                                    aria-invalid={Boolean(errors.label)}
                                />
                                <InputError message={errors.label} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="base_url">
                                    {t('connections.url')}
                                </Label>
                                <Input
                                    id="base_url"
                                    name="base_url"
                                    type="url"
                                    inputMode="url"
                                    defaultValue={tenant.base_url}
                                    required
                                    aria-invalid={Boolean(errors.base_url)}
                                />
                                <InputError message={errors.base_url} />
                            </div>

                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="username">
                                        {t('connections.username')}
                                    </Label>
                                    <Input
                                        id="username"
                                        name="username"
                                        defaultValue={tenant.username}
                                        autoComplete="username"
                                        required
                                        aria-invalid={Boolean(errors.username)}
                                    />
                                    <InputError message={errors.username} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('connections.passwordNew')}
                                    </Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder={t(
                                            'connections.passwordKeep',
                                        )}
                                        aria-invalid={Boolean(errors.password)}
                                    />
                                    <InputError message={errors.password} />
                                </div>
                            </div>

                            <InputError message={errors.connection} />

                            <input
                                name="is_default"
                                type="hidden"
                                value={isDefault ? '1' : '0'}
                            />
                            <input
                                name="is_active"
                                type="hidden"
                                value={isActive ? '1' : '0'}
                            />

                            <div className="flex flex-col gap-3">
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="is_default"
                                        checked={isDefault}
                                        onCheckedChange={(checked) =>
                                            setIsDefault(checked === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="is_default"
                                        className="font-normal"
                                    >
                                        {t('connections.makeDefault')}
                                    </Label>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="is_active"
                                        checked={isActive}
                                        onCheckedChange={(checked) =>
                                            setIsActive(checked === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="is_active"
                                        className="font-normal"
                                    >
                                        {t('connections.active')}
                                    </Label>
                                </div>
                                <InputError message={errors.is_active} />
                            </div>

                            <p className="text-xs text-muted-foreground">
                                {t('connections.serverRetestHint')}
                            </p>

                            <div className="flex gap-3">
                                <Button disabled={processing}>
                                    {processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Save data-icon="inline-start" />
                                    )}
                                    {processing
                                        ? t('common.saving')
                                        : t('connections.saveChanges')}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={oracleTenants.index()}>
                                        {t('common.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

EditOracleTenant.layout = {
    breadcrumbs: [
        { title: 'Connexions Oracle', href: oracleTenants.index() },
        { title: 'Modifier', href: '#' },
    ],
};

import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    DatabaseZap,
    KeyRound,
    Link2,
    MoreHorizontal,
    Pencil,
    Plus,
    Save,
    Server,
    ShieldAlert,
    Trash2,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { DataTable, StopClick, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OracleConnectionTestButton } from '@/components/oracle-connection-test-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import oracleTenants from '@/routes/oracle-tenants';
import type { OracleTenant } from '@/types';

type OracleTenantsIndexProps = {
    tenants: OracleTenant[];
    defaultTenant: string;
};

function TenantActionsMenu({
    tenant,
    canDelete,
    onDelete,
}: {
    tenant: OracleTenant;
    canDelete: boolean;
    onDelete: (id: number, label: string) => void;
}) {
    const { t } = useI18n();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={t('connections.actionLabel', {
                        name: tenant.label,
                    })}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem asChild className="cursor-pointer">
                    <Link href={oracleTenants.edit(tenant.id)}>
                        <Pencil className="size-4" />
                        {t('common.edit')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={!canDelete}
                    className="cursor-pointer text-destructive focus:text-destructive"
                    title={
                        canDelete ? undefined : t('connections.keepOneActive')
                    }
                    onSelect={(event) => {
                        event.preventDefault();
                        onDelete(tenant.id, tenant.label);
                    }}
                >
                    <Trash2 className="size-4 text-destructive" />
                    {t('common.delete')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function OracleTenantsIndex({
    tenants,
    defaultTenant,
}: OracleTenantsIndexProps) {
    const { t, formatDate } = useI18n();
    const [addDialogOpen, setAddDialogOpen] = useState(false);
    const [isDefault, setIsDefault] = useState(false);
    const [testRevision, setTestRevision] = useState(0);
    const activeConnectionCount = tenants.filter(
        (tenant) => tenant.is_active,
    ).length;

    function deleteTenant(id: number, label: string) {
        if (!confirm(t('connections.deleteConfirm', { name: label }))) {
            return;
        }

        router.delete(oracleTenants.destroy(id));
    }

    const columns: DataTableColumn<OracleTenant>[] = [
        {
            key: 'environment',
            header: t('connections.environment'),
            icon: Server,
            cell: (tenant) => (
                <div className="flex items-center gap-3">
                    <TableAvatar label={tenant.label} />
                    <div className="min-w-0">
                        <div className="font-medium">{tenant.label}</div>
                        <code className="text-xs text-muted-foreground">
                            {tenant.key}
                        </code>
                    </div>
                </div>
            ),
        },
        {
            key: 'url',
            header: t('connections.url'),
            icon: Link2,
            cellClassName: 'text-muted-foreground',
            cell: (tenant) => (
                <span className="break-all">{tenant.base_url || '—'}</span>
            ),
        },
        {
            key: 'authentication',
            header: t('connections.authentication'),
            icon: User,
            cell: (tenant) => (
                <div className="space-y-0.5">
                    <div className="font-medium">{tenant.username || '—'}</div>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span>
                            {tenant.auth_type.toLowerCase() === 'basic'
                                ? t('connections.authBasic')
                                : tenant.auth_type}
                        </span>
                        <span aria-hidden="true">·</span>
                        <span>
                            {t('connections.authMethods', {
                                count: tenant.connection_count,
                            })}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'verification',
            header: t('connections.verification'),
            icon: KeyRound,
            cell: (tenant) => {
                const testedAt = tenant.last_tested_at ?? tenant.verified_at;

                return (
                    <div className="space-y-0.5">
                        <span
                            className={`inline-flex items-center gap-1.5 text-sm font-medium ${
                                tenant.verified_at
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-amber-600 dark:text-amber-400'
                            }`}
                        >
                            {tenant.verified_at ? (
                                <CheckCircle2 className="size-4" />
                            ) : (
                                <ShieldAlert className="size-4" />
                            )}
                            {tenant.verified_at
                                ? t('connections.verified')
                                : t('connections.notVerified')}
                        </span>
                        {testedAt && (
                            <div className="text-xs text-muted-foreground">
                                {t('connections.lastTested', {
                                    date: formatDate(testedAt, {
                                        dateStyle: 'medium',
                                        timeStyle: 'short',
                                    }),
                                })}
                            </div>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'status',
            header: t('connections.status'),
            icon: Activity,
            cell: (tenant) => (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="inline-flex items-center gap-1.5 text-sm">
                        <span
                            className={`inline-block size-2 rounded-full ${
                                tenant.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-red-400'
                            }`}
                        />
                        {tenant.is_active
                            ? t('common.active')
                            : t('common.inactive')}
                    </span>
                    {(tenant.is_default || tenant.key === defaultTenant) && (
                        <Badge variant="secondary">{t('common.default')}</Badge>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: t('common.actions'),
            align: 'right',
            width: 'w-24',
            cell: (tenant) => (
                <StopClick>
                    <TenantActionsMenu
                        tenant={tenant}
                        canDelete={
                            !tenant.is_active || activeConnectionCount > 1
                        }
                        onDelete={deleteTenant}
                    />
                </StopClick>
            ),
        },
    ];

    return (
        <>
            <Head title={t('connections.title')} />

            <div className="space-y-6">
                <Heading
                    title={t('connections.title')}
                    description={t('connections.description')}
                    actions={
                        <Button onClick={() => setAddDialogOpen(true)}>
                            <Plus />
                            {t('connections.add')}
                        </Button>
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card">
                    <DataTable
                        columns={columns}
                        rows={tenants}
                        rowKey={(tenant) => tenant.id}
                        empty={
                            <div className="space-y-3">
                                <div>
                                    <p className="font-medium text-foreground">
                                        {t('connections.empty')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {t('connections.emptyDescription')}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() => setAddDialogOpen(true)}
                                >
                                    <Plus />
                                    {t('connections.add')}
                                </Button>
                            </div>
                        }
                    />
                </div>

                <Dialog
                    open={addDialogOpen}
                    onOpenChange={(open) => {
                        setAddDialogOpen(open);

                        if (!open) {
                            setIsDefault(false);
                        }
                    }}
                >
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <DatabaseZap className="size-5" />
                                {t('connections.add')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('connections.addDescription')}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...oracleTenants.store.form()}
                            className="space-y-5"
                            onChange={() =>
                                setTestRevision((revision) => revision + 1)
                            }
                            onSuccess={() => {
                                setAddDialogOpen(false);
                                setIsDefault(false);
                            }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="label">
                                                {t(
                                                    'connections.environmentLabel',
                                                )}
                                            </Label>
                                            <Input
                                                id="label"
                                                name="label"
                                                placeholder={t(
                                                    'connections.environmentPlaceholder',
                                                )}
                                                required
                                                aria-invalid={Boolean(
                                                    errors.label,
                                                )}
                                            />
                                            <InputError
                                                message={errors.label}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="key">
                                                {t('connections.key')}
                                            </Label>
                                            <Input
                                                id="key"
                                                name="key"
                                                placeholder={t(
                                                    'connections.keyPlaceholder',
                                                )}
                                                aria-invalid={Boolean(
                                                    errors.key,
                                                )}
                                            />
                                            <InputError message={errors.key} />
                                        </div>
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
                                            placeholder={t(
                                                'connections.urlPlaceholder',
                                            )}
                                            required
                                            aria-invalid={Boolean(
                                                errors.base_url,
                                            )}
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
                                                autoComplete="username"
                                                required
                                                aria-invalid={Boolean(
                                                    errors.username,
                                                )}
                                            />
                                            <InputError
                                                message={errors.username}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                {t('connections.password')}
                                            </Label>
                                            <Input
                                                id="password"
                                                name="password"
                                                type="password"
                                                autoComplete="new-password"
                                                required
                                                aria-invalid={Boolean(
                                                    errors.password,
                                                )}
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                    </div>

                                    <InputError message={errors.connection} />

                                    <input
                                        name="is_default"
                                        type="hidden"
                                        value={isDefault ? '1' : '0'}
                                    />

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

                                    <p className="text-xs text-muted-foreground">
                                        {t('connections.serverRetestHint')}
                                    </p>

                                    <DialogFooter className="gap-2 sm:items-start">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                        </DialogClose>
                                        <OracleConnectionTestButton
                                            key={testRevision}
                                        />
                                        <Button disabled={processing}>
                                            {processing ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <Save data-icon="inline-start" />
                                            )}
                                            {processing
                                                ? t('common.saving')
                                                : t('common.save')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

OracleTenantsIndex.layout = {
    breadcrumbs: [{ title: 'Connexions Oracle', href: oracleTenants.index() }],
};

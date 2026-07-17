import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Database,
    DatabaseZap,
    Link2,
    Pencil,
    Save,
    Server,
    Trash2,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { DataTable, StopClick, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { readCsrfToken } from '@/lib/csrf';
import oracleTenants from '@/routes/oracle-tenants';

type OracleTenant = {
    id: number;
    key: string;
    label: string;
    base_url: string;
    username: string;
    source: 'config' | 'database';
    is_default: boolean;
    is_active: boolean;
};

type OracleTenantsIndexProps = {
    tenants: OracleTenant[];
    defaultTenant: string;
};

function sourceLabel(source: OracleTenant['source']): string {
    return source === 'database' ? 'Base' : 'Config';
}

function TestConnectionButton() {
    const [status, setStatus] = useState<'idle' | 'loading' | 'ok' | 'error'>(
        'idle',
    );
    const [message, setMessage] = useState('');

    async function test(form: HTMLFormElement) {
        const data = new FormData(form);
        const payload = {
            base_url: data.get('base_url'),
            username: data.get('username'),
            password: data.get('password'),
        };

        if (!payload.base_url || !payload.username || !payload.password) {
            setStatus('error');
            setMessage(
                "Renseignez l'URL, le nom d'utilisateur et le mot de passe avant de tester.",
            );

            return;
        }

        setStatus('loading');
        setMessage('');

        try {
            const res = await fetch(oracleTenants.test.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const json = (await res.json()) as { ok: boolean; message: string };
            setStatus(json.ok ? 'ok' : 'error');
            setMessage(json.message);
        } catch {
            setStatus('error');
            setMessage('Erreur réseau.');
        }
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                variant="outline"
                onClick={(e) => {
                    const form = (e.target as HTMLElement).closest('form');

                    if (form) {
                        void test(form);
                    }
                }}
                disabled={status === 'loading'}
            >
                {status === 'loading' && <Spinner data-icon="inline-start" />}
                Tester la connexion
            </Button>
            {message && (
                <p
                    className={`text-sm ${status === 'ok' ? 'text-emerald-600' : 'text-destructive'}`}
                >
                    {message}
                </p>
            )}
        </div>
    );
}

export default function OracleTenantsIndex({
    tenants,
    defaultTenant,
}: OracleTenantsIndexProps) {
    const [isDefault, setIsDefault] = useState(false);

    function deleteTenant(id: number, label: string) {
        if (
            !confirm(
                `Supprimer le tenant "${label}" ? Cette action est irréversible.`,
            )
        ) {
            return;
        }

        router.delete(oracleTenants.destroy(id));
    }

    const columns: DataTableColumn<OracleTenant>[] = [
        {
            key: 'tenant',
            header: 'Tenant',
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
            header: 'URL',
            icon: Link2,
            cellClassName: 'text-muted-foreground',
            cell: (tenant) => (
                <span className="break-all">{tenant.base_url || '—'}</span>
            ),
        },
        {
            key: 'account',
            header: 'Compte',
            icon: User,
            cellClassName: 'text-muted-foreground',
            cell: (tenant) => tenant.username || '—',
        },
        {
            key: 'source',
            header: 'Source',
            icon: Database,
            cell: (tenant) => (
                <Badge
                    variant={
                        tenant.source === 'database' ? 'default' : 'secondary'
                    }
                >
                    {sourceLabel(tenant.source)}
                </Badge>
            ),
        },
        {
            key: 'status',
            header: 'Statut',
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
                        {tenant.is_active ? 'Actif' : 'Inactif'}
                    </span>
                    {(tenant.is_default || tenant.key === defaultTenant) && (
                        <Badge variant="secondary">Défaut</Badge>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            cell: (tenant) =>
                tenant.source === 'database' ? (
                    <StopClick>
                        <Button asChild size="sm" variant="outline">
                            <Link href={oracleTenants.edit(tenant.id)}>
                                <Pencil className="size-3.5" />
                                Modifier
                            </Link>
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="text-destructive hover:text-destructive"
                            onClick={() =>
                                deleteTenant(tenant.id, tenant.label)
                            }
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </StopClick>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Lecture seule
                    </span>
                ),
        },
    ];

    return (
        <>
            <Head title="Tenants Oracle" />

            <div className="space-y-6 px-6 py-6">
                <Heading
                    title="Tenants Oracle"
                    description="Connexions Oracle Fusion disponibles pour les requêtes."
                />

                <div className="overflow-hidden rounded-xl border bg-card">
                    <DataTable
                        columns={columns}
                        rows={tenants}
                        rowKey={(tenant) => tenant.key}
                        empty="Aucun tenant configuré."
                    />
                </div>

                <Card className="max-w-3xl rounded-lg">
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <DatabaseZap className="size-5" />
                            <CardTitle>Ajouter un tenant</CardTitle>
                        </div>
                        <CardDescription>
                            Les identifiants sont chiffrés avant sauvegarde.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...oracleTenants.store.form()}
                            resetOnSuccess={[
                                'key',
                                'label',
                                'base_url',
                                'username',
                                'password',
                                'is_default',
                            ]}
                            className="space-y-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="label">
                                                Libellé
                                            </Label>
                                            <Input
                                                id="label"
                                                name="label"
                                                placeholder="Client X Production"
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
                                            <Label htmlFor="key">Clé</Label>
                                            <Input
                                                id="key"
                                                name="key"
                                                placeholder="client_x_prod"
                                                aria-invalid={Boolean(
                                                    errors.key,
                                                )}
                                            />
                                            <InputError message={errors.key} />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="base_url">
                                            URL Oracle
                                        </Label>
                                        <Input
                                            id="base_url"
                                            name="base_url"
                                            type="url"
                                            placeholder="https://client.fa.oraclecloud.com"
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
                                                Nom d'utilisateur
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
                                                Mot de passe
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
                                            Utiliser par défaut
                                        </Label>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-4">
                                        <Button disabled={processing}>
                                            {processing ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <Save data-icon="inline-start" />
                                            )}
                                            Enregistrer
                                        </Button>
                                        <TestConnectionButton />
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OracleTenantsIndex.layout = {
    breadcrumbs: [{ title: 'Tenants Oracle', href: oracleTenants.index() }],
};

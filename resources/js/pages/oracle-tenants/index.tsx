import { Form, Head } from '@inertiajs/react';
import { DatabaseZap, Save } from 'lucide-react';
import { useState } from 'react';
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
import oracleTenants from '@/routes/oracle-tenants';

type OracleTenant = {
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

export default function OracleTenantsIndex({
    tenants,
    defaultTenant,
}: OracleTenantsIndexProps) {
    const [isDefault, setIsDefault] = useState(false);

    return (
        <>
            <Head title="Tenants Oracle" />

            <div className="space-y-6 px-4 py-6">
                <Heading
                    title="Tenants Oracle"
                    description="Connexions Oracle Fusion disponibles pour les requêtes."
                />

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Tenant
                                </th>
                                <th className="px-4 py-3 font-medium">URL</th>
                                <th className="px-4 py-3 font-medium">
                                    Compte
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Source
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Statut
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {tenants.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        Aucun tenant configuré.
                                    </td>
                                </tr>
                            ) : (
                                tenants.map((tenant) => (
                                    <tr
                                        key={tenant.key}
                                        className="hover:bg-muted/40"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {tenant.label}
                                            </div>
                                            <code className="text-xs text-muted-foreground">
                                                {tenant.key}
                                            </code>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="break-all text-muted-foreground">
                                                {tenant.base_url || '-'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {tenant.username || '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    tenant.source === 'database'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {sourceLabel(tenant.source)}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                <Badge variant="outline">
                                                    {tenant.is_active
                                                        ? 'Actif'
                                                        : 'Inactif'}
                                                </Badge>
                                                {(tenant.is_default ||
                                                    tenant.key ===
                                                        defaultTenant) && (
                                                    <Badge>Défaut</Badge>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
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
                                                Username
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
                                                Password
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

                                    <Button disabled={processing}>
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Save data-icon="inline-start" />
                                        )}
                                        Enregistrer
                                    </Button>
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
    breadcrumbs: [
        { title: 'Tenants Oracle', href: oracleTenants.index() },
        { title: 'Ajouter', href: oracleTenants.index() },
    ],
};

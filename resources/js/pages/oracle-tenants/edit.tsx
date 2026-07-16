import { Form, Head, Link } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import oracleTenants from '@/routes/oracle-tenants';

type TenantEditProps = {
    tenant: {
        id: number;
        key: string;
        label: string;
        base_url: string;
        username: string;
        is_default: boolean;
        is_active: boolean;
    };
};

export default function EditOracleTenant({ tenant }: TenantEditProps) {
    const [isDefault, setIsDefault] = useState(tenant.is_default);
    const [isActive, setIsActive] = useState(tenant.is_active);

    return (
        <>
            <Head title={`Modifier — ${tenant.label}`} />

            <div className="space-y-6 px-4 py-6">
                <Heading
                    title={`Modifier ${tenant.label}`}
                    description="Mettez à jour les informations de connexion Oracle. Laissez le mot de passe vide pour le conserver."
                />

                <Form
                    {...oracleTenants.update.form(tenant.id)}
                    className="max-w-2xl space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label>Clé (non modifiable)</Label>
                                <Input value={tenant.key} disabled />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="label">Libellé</Label>
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
                                <Label htmlFor="base_url">URL Oracle</Label>
                                <Input
                                    id="base_url"
                                    name="base_url"
                                    type="url"
                                    defaultValue={tenant.base_url}
                                    required
                                    aria-invalid={Boolean(errors.base_url)}
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
                                        defaultValue={tenant.username}
                                        required
                                        aria-invalid={Boolean(errors.username)}
                                    />
                                    <InputError message={errors.username} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        Nouveau mot de passe
                                    </Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder="Laisser vide pour ne pas changer"
                                        aria-invalid={Boolean(errors.password)}
                                    />
                                    <InputError message={errors.password} />
                                </div>
                            </div>

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
                                        onCheckedChange={(c) =>
                                            setIsDefault(c === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="is_default"
                                        className="font-normal"
                                    >
                                        Utiliser par défaut
                                    </Label>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="is_active"
                                        checked={isActive}
                                        onCheckedChange={(c) =>
                                            setIsActive(c === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="is_active"
                                        className="font-normal"
                                    >
                                        Actif
                                    </Label>
                                </div>
                            </div>

                            <div className="flex gap-3">
                                <Button disabled={processing}>
                                    {processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Save data-icon="inline-start" />
                                    )}
                                    Enregistrer
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={oracleTenants.index()}>
                                        Annuler
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
        { title: 'Tenants Oracle', href: oracleTenants.index() },
        { title: 'Modifier', href: '#' },
    ],
};

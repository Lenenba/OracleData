import { Form, Head } from '@inertiajs/react';
import { KeyRound, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

type Props = { passwordRules: string } & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Paramètres de sécurité" />
            <h1 className="sr-only">Paramètres de sécurité</h1>

            <div className="space-y-5">
                <div className="grid gap-5 xl:grid-cols-12">
                    <Form
                        {...SecurityController.update.form()}
                        options={{ preserveScroll: true }}
                        resetOnError={[
                            'password',
                            'password_confirmation',
                            'current_password',
                        ]}
                        resetOnSuccess
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }

                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="card xl:col-span-8"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="card-header">
                                    <Heading
                                        variant="small"
                                        title="Modifier le mot de passe"
                                        description="Utilisez un mot de passe long et unique pour protéger votre compte."
                                    />
                                </div>
                                <div className="card-body grid gap-5 md:grid-cols-2">
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label htmlFor="current_password">
                                            Mot de passe actuel
                                        </Label>
                                        <PasswordInput
                                            id="current_password"
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            autoComplete="current-password"
                                            placeholder="Mot de passe actuel"
                                        />
                                        <InputError
                                            message={errors.current_password}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Nouveau mot de passe
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            ref={passwordInput}
                                            name="password"
                                            autoComplete="new-password"
                                            placeholder="Nouveau mot de passe"
                                            passwordrules={props.passwordRules}
                                        />
                                        <InputError message={errors.password} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Confirmer le mot de passe
                                        </Label>
                                        <PasswordInput
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            autoComplete="new-password"
                                            placeholder="Confirmer le mot de passe"
                                            passwordrules={props.passwordRules}
                                        />
                                        <InputError
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="card-footer justify-end">
                                    <Button
                                        disabled={processing}
                                        data-test="update-password-button"
                                    >
                                        <LockKeyhole /> Enregistrer le mot de
                                        passe
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>

                    <aside className="card h-fit xl:col-span-4">
                        <div className="card-header">
                            <h2 className="card-title">
                                État de la protection
                            </h2>
                        </div>
                        <div className="card-body space-y-4">
                            <span className="grid size-14 place-items-center rounded-full bg-success/15 text-success">
                                <ShieldCheck className="size-7" />
                            </span>
                            <div>
                                <h3 className="text-base font-semibold">
                                    Compte protégé
                                </h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Combinez mot de passe, double
                                    authentification et clé d'accès pour
                                    renforcer chaque connexion.
                                </p>
                            </div>
                            <div className="space-y-2 border-t border-dashed border-border pt-4 text-[13px]">
                                <p className="flex items-center gap-2">
                                    <LockKeyhole className="size-4 text-primary" />{' '}
                                    Mot de passe configuré
                                </p>
                                <p className="flex items-center gap-2">
                                    <ShieldCheck className="size-4 text-purple" />{' '}
                                    Double authentification disponible
                                </p>
                                <p className="flex items-center gap-2">
                                    <KeyRound className="size-4 text-info" />{' '}
                                    Clés d'accès compatibles
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>

                <div className="grid gap-5 xl:grid-cols-2">
                    <ManageTwoFactor
                        canManageTwoFactor={props.canManageTwoFactor}
                        requiresConfirmation={props.requiresConfirmation}
                        twoFactorEnabled={props.twoFactorEnabled}
                    />
                    <ManagePasskeys
                        canManagePasskeys={props.canManagePasskeys}
                        passkeys={props.passkeys}
                    />
                </div>
            </div>
        </>
    );
}

Security.layout = { breadcrumbs: [{ title: 'Sécurité', href: edit() }] };

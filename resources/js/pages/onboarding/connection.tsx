import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowRight, KeyRound, LogOut, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OracleConnectionTestButton } from '@/components/oracle-connection-test-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import { logout } from '@/routes';
import onboardingConnection from '@/routes/onboarding/connection';

export default function OnboardingConnection() {
    const { t } = useI18n();
    const [connectionVerified, setConnectionVerified] = useState(false);
    const [testRevision, setTestRevision] = useState(0);

    function invalidateTest() {
        setConnectionVerified(false);
        setTestRevision((revision) => revision + 1);
    }

    return (
        <>
            <Head title={t('onboarding.title')} />

            <div className="space-y-6">
                <div className="space-y-2">
                    <div className="flex items-center justify-between gap-3 text-xs">
                        <span className="font-medium text-primary">
                            {t('onboarding.eyebrow')}
                        </span>
                        <span className="text-muted-foreground">
                            {t('onboarding.step')}
                        </span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div className="h-full w-full rounded-full bg-primary" />
                    </div>
                </div>

                <Alert className="border-primary/20 bg-primary/5">
                    <KeyRound />
                    <AlertTitle className="flex items-center gap-2">
                        {t('onboarding.authRequired')}
                        <Badge variant="secondary">
                            {t('connections.authBasic')}
                        </Badge>
                    </AlertTitle>
                    <AlertDescription>
                        {t('onboarding.authRequiredDescription')}
                    </AlertDescription>
                </Alert>

                <Form
                    {...onboardingConnection.store.form()}
                    className="space-y-5"
                    disableWhileProcessing
                    onChange={invalidateTest}
                    onError={invalidateTest}
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="auth_type"
                                value="basic"
                            />
                            <input type="hidden" name="is_default" value="1" />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="label">
                                        {t('connections.environmentLabel')}
                                    </Label>
                                    <Input
                                        id="label"
                                        name="label"
                                        placeholder={t(
                                            'connections.environmentPlaceholder',
                                        )}
                                        autoFocus
                                        required
                                        aria-invalid={Boolean(errors.label)}
                                    />
                                    <InputError message={errors.label} />
                                </div>

                                <div className="grid content-start gap-2">
                                    <Label htmlFor="key">
                                        {t('connections.key')}
                                    </Label>
                                    <Input
                                        id="key"
                                        name="key"
                                        placeholder={t(
                                            'connections.keyPlaceholder',
                                        )}
                                        aria-invalid={Boolean(errors.key)}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('connections.keyHint')}
                                    </p>
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
                                    aria-invalid={Boolean(errors.base_url)}
                                />
                                <InputError message={errors.base_url} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="username">
                                        {t('connections.username')}
                                    </Label>
                                    <Input
                                        id="username"
                                        name="username"
                                        autoComplete="username"
                                        required
                                        aria-invalid={Boolean(errors.username)}
                                    />
                                    <InputError message={errors.username} />
                                </div>

                                <div className="grid content-start gap-2">
                                    <Label htmlFor="password">
                                        {t('connections.password')}
                                    </Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        aria-invalid={Boolean(errors.password)}
                                    />
                                    <InputError message={errors.password} />
                                </div>
                            </div>

                            <InputError message={errors.connection} />

                            <div className="space-y-3 rounded-xl border bg-muted/20 p-4">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium">
                                            {connectionVerified
                                                ? t('onboarding.verifiedReady')
                                                : t(
                                                      'onboarding.verifyBeforeContinue',
                                                  )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {t('onboarding.serverRetest')}
                                        </p>
                                    </div>
                                </div>

                                <OracleConnectionTestButton
                                    key={testRevision}
                                    className="w-full"
                                    onVerifiedChange={setConnectionVerified}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={!connectionVerified || processing}
                                data-test="complete-onboarding-button"
                            >
                                {processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <ArrowRight data-icon="inline-start" />
                                )}
                                {processing
                                    ? t('onboarding.completing')
                                    : t('onboarding.continue')}
                            </Button>

                            <div className="text-center">
                                <Link
                                    href={logout()}
                                    as="button"
                                    type="button"
                                    className="inline-flex cursor-pointer items-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                    onClick={() => router.flushAll()}
                                    data-test="onboarding-logout-button"
                                >
                                    <LogOut className="size-4" />
                                    {t('nav.logout')}
                                </Link>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

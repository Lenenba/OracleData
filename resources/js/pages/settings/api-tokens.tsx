import { Head, router } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, Key, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n/i18n-context';
import apiTokensRoutes from '@/routes/api-tokens';

type ApiToken = {
    id: number;
    name: string;
    scopes: string[];
    is_active: boolean;
    daily_limit: number;
    requests_today: number;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
};

type PageProps = {
    tokens: ApiToken[];
    allowed_scopes: string[];
    plain_token: string | null;
};

function CreateTokenDialog({ allowedScopes }: { allowedScopes: string[] }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<string[]>([allowedScopes[0] ?? '']);
    const [dailyLimit, setDailyLimit] = useState('1000');
    const [expiresAt, setExpiresAt] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function toggleScope(scope: string, checked: boolean) {
        setScopes((prev) =>
            checked ? [...prev, scope] : prev.filter((s) => s !== scope),
        );
    }

    function submit() {
        if (name.trim() === '' || scopes.length === 0 || submitting) {
            return;
        }

        setSubmitting(true);
        router.post(
            apiTokensRoutes.store.url(),
            {
                name: name.trim(),
                scopes,
                daily_limit: Number(dailyLimit) || 1000,
                expires_at: expiresAt || null,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setName('');
                    setScopes([allowedScopes[0] ?? '']);
                    setExpiresAt('');
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus className="size-4" />
                    {t('apiTokens.create')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('apiTokens.create')}</DialogTitle>
                    <DialogDescription>
                        {t('apiTokens.description')}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="token-name">
                            {t('apiTokens.name')}
                        </Label>
                        <Input
                            id="token-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder={t('apiTokens.namePlaceholder')}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label>{t('apiTokens.scopes')}</Label>
                        {allowedScopes.map((scope) => (
                            <label
                                key={scope}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    checked={scopes.includes(scope)}
                                    onCheckedChange={(checked) =>
                                        toggleScope(scope, checked === true)
                                    }
                                />
                                <span className="font-mono text-xs">
                                    {scope}
                                </span>
                            </label>
                        ))}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="token-limit">
                            {t('apiTokens.dailyLimit')}
                        </Label>
                        <Input
                            id="token-limit"
                            type="number"
                            min={10}
                            max={100000}
                            value={dailyLimit}
                            onChange={(e) => setDailyLimit(e.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="token-expires">
                            {t('apiTokens.expiresAt')}
                        </Label>
                        <Input
                            id="token-expires"
                            type="date"
                            value={expiresAt}
                            onChange={(e) => setExpiresAt(e.target.value)}
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        disabled={
                            name.trim() === '' ||
                            scopes.length === 0 ||
                            submitting
                        }
                        onClick={submit}
                    >
                        {t('apiTokens.create')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SecretAlert({ secret }: { secret: string }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);
    const [visible, setVisible] = useState(false);

    function copy() {
        void navigator.clipboard.writeText(secret).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <div className="mb-4 rounded-lg border border-emerald-500/40 bg-emerald-50 p-4 dark:bg-emerald-950/30">
            <p className="mb-1 text-sm font-semibold text-emerald-800 dark:text-emerald-300">
                {t('apiTokens.secretTitle')}
            </p>
            <p className="mb-3 text-xs text-emerald-700 dark:text-emerald-400">
                {t('apiTokens.secretHint')}
            </p>
            <div className="flex items-center gap-2">
                <code className="flex-1 overflow-x-auto rounded bg-white px-3 py-2 font-mono text-xs dark:bg-black">
                    {visible ? secret : '•'.repeat(Math.min(secret.length, 32))}
                </code>
                <Button
                    size="icon"
                    variant="ghost"
                    onClick={() => setVisible((v) => !v)}
                >
                    {visible ? (
                        <EyeOff className="size-4" />
                    ) : (
                        <Eye className="size-4" />
                    )}
                </Button>
                <Button size="icon" variant="ghost" onClick={copy}>
                    {copied ? (
                        <Check className="size-4 text-emerald-600" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                </Button>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                {t('apiTokens.usageHint')}
            </p>
        </div>
    );
}

export default function ApiTokensPage({
    tokens,
    allowed_scopes,
    plain_token,
}: PageProps) {
    const { t, formatDate, formatNumber } = useI18n();
    const activeTokens = tokens.filter((token) => token.is_active).length;
    const requestsToday = tokens.reduce(
        (total, token) => total + token.requests_today,
        0,
    );

    function revoke(id: number) {
        if (!confirm(t('apiTokens.revokeConfirm'))) {
            return;
        }

        router.delete(apiTokensRoutes.destroy.url(id));
    }

    return (
        <>
            <Head title={t('apiTokens.pageTitle')} />
            <div className="space-y-5">
                <Heading
                    title={t('apiTokens.title')}
                    description={t('apiTokens.description')}
                    actions={
                        <CreateTokenDialog allowedScopes={allowed_scopes} />
                    }
                />

                <div className="grid gap-5 md:grid-cols-3">
                    {[
                        [
                            'Jetons créés',
                            tokens.length,
                            'bg-primary/15 text-primary',
                        ],
                        [
                            'Jetons actifs',
                            activeTokens,
                            'bg-success/15 text-success',
                        ],
                        [
                            "Requêtes aujourd'hui",
                            requestsToday,
                            'bg-purple/15 text-purple',
                        ],
                    ].map(([label, value, tone]) => (
                        <div key={String(label)} className="card">
                            <div className="card-body flex items-center gap-4">
                                <span
                                    className={`grid size-10 place-items-center rounded-full ${tone}`}
                                >
                                    <Key className="size-4.5" />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        {label}
                                    </p>
                                    <strong className="mt-1 block text-2xl font-semibold tabular-nums">
                                        {formatNumber(Number(value))}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                {plain_token !== null && <SecretAlert secret={plain_token} />}

                {tokens.length === 0 ? (
                    <div className="card card-body py-14 text-center">
                        <Key className="mx-auto mb-3 size-9 text-muted-foreground/50" />
                        <p className="text-sm text-muted-foreground">
                            {t('apiTokens.empty')}
                        </p>
                    </div>
                ) : (
                    <section className="card">
                        <div className="card-header justify-between">
                            <div>
                                <h2 className="card-title">Clés d'accès API</h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Accès programmatiques et limites de
                                    consommation
                                </p>
                            </div>
                            <Badge variant="secondary">
                                {tokens.length} jeton(s)
                            </Badge>
                        </div>
                        <div className="divide-y divide-border">
                            {tokens.map((token) => (
                                <div
                                    key={token.id}
                                    className="flex flex-wrap items-start justify-between gap-3 p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Key className="size-4 text-muted-foreground" />
                                            <span className="font-medium">
                                                {token.name}
                                            </span>
                                            {!token.is_active && (
                                                <Badge variant="secondary">
                                                    {t('common.inactive')}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {token.scopes.map((s) => (
                                                <Badge
                                                    key={s}
                                                    variant="outline"
                                                    className="font-mono text-xs"
                                                >
                                                    {s}
                                                </Badge>
                                            ))}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {t('apiTokens.requestsToday', {
                                                count: token.requests_today,
                                                limit: token.daily_limit,
                                            })}
                                            {' · '}
                                            {token.last_used_at
                                                ? t('apiTokens.lastUsed', {
                                                      date: formatDate(
                                                          token.last_used_at,
                                                      ),
                                                  })
                                                : t('apiTokens.neverUsed')}
                                            {' · '}
                                            {token.expires_at
                                                ? t('apiTokens.expires', {
                                                      date: formatDate(
                                                          token.expires_at,
                                                      ),
                                                  })
                                                : t('apiTokens.noExpiry')}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => revoke(token.id)}
                                    >
                                        <Trash2 className="size-4" />
                                        {t('apiTokens.revoke')}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

ApiTokensPage.layout = {
    breadcrumbs: [
        {
            title: 'API & Tokens',
            href: apiTokensRoutes.index(),
        },
    ],
};

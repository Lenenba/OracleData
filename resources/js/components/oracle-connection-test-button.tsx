import { CheckCircle2, PlugZap } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import oracleTenants from '@/routes/oracle-tenants';

type TestResponse = {
    ok?: boolean;
    message?: string;
    errors?: Record<string, string[]>;
};

type OracleConnectionTestButtonProps = {
    onVerifiedChange?: (verified: boolean) => void;
    className?: string;
};

export function OracleConnectionTestButton({
    onVerifiedChange,
    className,
}: OracleConnectionTestButtonProps) {
    const { t } = useI18n();
    const abortController = useRef<AbortController | null>(null);
    const isMounted = useRef(true);
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const [message, setMessage] = useState('');

    useEffect(() => {
        isMounted.current = true;

        return () => {
            isMounted.current = false;
            abortController.current?.abort();
        };
    }, []);

    async function test(form: HTMLFormElement) {
        const data = new FormData(form);
        const payload = {
            base_url: String(data.get('base_url') ?? '').trim(),
            username: String(data.get('username') ?? '').trim(),
            password: String(data.get('password') ?? ''),
            type: String(data.get('type') ?? 'fusion'),
        };

        if (!payload.base_url || !payload.username || !payload.password) {
            setStatus('error');
            setMessage(t('connections.testMissing'));
            onVerifiedChange?.(false);

            return;
        }

        abortController.current?.abort();
        abortController.current = new AbortController();
        setStatus('loading');
        setMessage('');
        onVerifiedChange?.(false);

        try {
            const response = await fetch(oracleTenants.test.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: abortController.current.signal,
            });
            const json = (await response.json()) as TestResponse;
            const succeeded = response.ok && json.ok === true;
            const validationMessage = Object.values(json.errors ?? {})
                .flat()
                .find(Boolean);

            if (!isMounted.current) {
                return;
            }

            setStatus(succeeded ? 'success' : 'error');
            setMessage(
                json.message ??
                    validationMessage ??
                    t(
                        succeeded
                            ? 'connections.testSuccessFallback'
                            : 'connections.testErrorFallback',
                    ),
            );
            onVerifiedChange?.(succeeded);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            if (!isMounted.current) {
                return;
            }

            setStatus('error');
            setMessage(t('connections.networkError'));
            onVerifiedChange?.(false);
        }
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                variant="outline"
                className={className}
                disabled={status === 'loading'}
                onClick={(event) => {
                    const form = event.currentTarget.closest('form');

                    if (form) {
                        void test(form);
                    }
                }}
            >
                {status === 'loading' ? (
                    <Spinner data-icon="inline-start" />
                ) : status === 'success' ? (
                    <CheckCircle2 data-icon="inline-start" />
                ) : (
                    <PlugZap data-icon="inline-start" />
                )}
                {status === 'loading'
                    ? t('connections.testing')
                    : t('connections.test')}
            </Button>
            {message && (
                <p
                    role="status"
                    aria-live="polite"
                    className={`text-sm ${
                        status === 'success'
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-destructive'
                    }`}
                >
                    {message}
                </p>
            )}
        </div>
    );
}

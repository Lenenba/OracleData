import { usePage } from '@inertiajs/react';
import { useI18n } from '@/i18n/i18n-context';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { component } = usePage();
    const { t } = useI18n();
    const isOnboarding = component.startsWith('onboarding/');
    const resolvedTitle =
        title || (isOnboarding ? t('onboarding.title') : title);
    const resolvedDescription =
        description ||
        (isOnboarding ? t('onboarding.description') : description);

    return (
        <AuthLayoutTemplate
            title={resolvedTitle}
            description={resolvedDescription}
            wide={isOnboarding}
        >
            {children}
        </AuthLayoutTemplate>
    );
}

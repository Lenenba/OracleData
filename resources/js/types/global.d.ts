import type { Auth } from '@/types/auth';
import type { NotificationSummary } from '@/types/notifications';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: 'fr' | 'en' | 'es';
            locales: Record<'fr' | 'en' | 'es', string>;
            notificationSummary: NotificationSummary;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

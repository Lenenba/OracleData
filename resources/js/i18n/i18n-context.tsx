import { usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useMemo } from 'react';
import en from '@/locales/en.json';
import es from '@/locales/es.json';
import fr from '@/locales/fr.json';

export type Locale = 'fr' | 'en' | 'es';
export type TranslationKey = keyof typeof fr;

const catalogs: Record<Locale, Record<TranslationKey, string>> = { fr, en, es };

type I18nValue = {
    locale: Locale;
    t: (
        key: TranslationKey,
        replacements?: Record<string, string | number>,
    ) => string;
    formatDate: (
        value: string | Date,
        options?: Intl.DateTimeFormatOptions,
    ) => string;
    formatNumber: (value: number, options?: Intl.NumberFormatOptions) => string;
};

const I18nContext = createContext<I18nValue | null>(null);

export function I18nProvider({ children }: { children: React.ReactNode }) {
    const { locale, auth } = usePage().props;
    const activeLocale = (locale in catalogs ? locale : 'fr') as Locale;
    const timezone = auth.user?.timezone ?? 'America/Toronto';

    useEffect(() => {
        document.documentElement.lang = activeLocale;
    }, [activeLocale]);

    const value = useMemo<I18nValue>(
        () => ({
            locale: activeLocale,
            t(key, replacements = {}) {
                return Object.entries(replacements).reduce(
                    (message, [name, replacement]) =>
                        message.replaceAll(`:${name}`, String(replacement)),
                    catalogs[activeLocale][key] ?? catalogs.fr[key] ?? key,
                );
            },
            formatDate(date, options = {}) {
                return new Intl.DateTimeFormat(activeLocale, {
                    timeZone: timezone,
                    ...options,
                }).format(new Date(date));
            },
            formatNumber(number, options = {}) {
                return new Intl.NumberFormat(activeLocale, options).format(
                    number,
                );
            },
        }),
        [activeLocale, timezone],
    );

    return (
        <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
    );
}

export function useI18n(): I18nValue {
    const context = useContext(I18nContext);

    if (!context) {
        throw new Error('useI18n must be used within I18nProvider');
    }

    return context;
}

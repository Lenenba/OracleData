import { router, usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/i18n/i18n-context';
import type { Locale } from '@/i18n/i18n-context';

export function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
    const { locale, t } = useI18n();
    const { locales } = usePage().props;

    return (
        <div
            className={
                compact ? 'flex items-center' : 'flex items-center gap-2'
            }
        >
            <Languages
                className={
                    compact
                        ? 'mr-1 size-4 text-muted-foreground'
                        : 'size-4 text-muted-foreground'
                }
                aria-hidden="true"
            />
            {!compact && (
                <span className="text-sm">{t('common.language')}</span>
            )}
            <Select
                value={locale}
                onValueChange={(value: Locale) =>
                    router.patch(
                        '/locale',
                        { locale: value },
                        {
                            preserveScroll: true,
                            preserveState: false,
                        },
                    )
                }
            >
                <SelectTrigger
                    size="sm"
                    className={
                        compact
                            ? 'h-9! w-auto min-w-14 border-0 bg-transparent px-1.5 shadow-none hover:border-0'
                            : 'min-w-24'
                    }
                    aria-label={t('common.language')}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries(locales).map(([code, label]) => (
                        <SelectItem key={code} value={code}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

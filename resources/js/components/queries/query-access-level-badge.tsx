import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/i18n/i18n-context';
import type { QueryAccessLevel } from '@/types/query-sharing';

const variants: Record<QueryAccessLevel, 'default' | 'secondary' | 'outline'> =
    {
        private: 'secondary',
        restricted: 'outline',
        organization: 'default',
    };

export function QueryAccessLevelBadge({
    accessLevel,
    className,
}: {
    accessLevel: QueryAccessLevel;
    className?: string;
}) {
    const { t } = useI18n();
    const labels = {
        private: t('queries.accessLevelPrivate'),
        restricted: t('queries.accessLevelRestricted'),
        organization: t('queries.accessLevelOrganization'),
    } satisfies Record<QueryAccessLevel, string>;

    return (
        <Badge variant={variants[accessLevel]} className={className}>
            {labels[accessLevel]}
        </Badge>
    );
}

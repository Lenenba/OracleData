import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/i18n/i18n-context';
import type { QueryChangeRequestStatus } from '@/types/change-requests';

const variants: Record<
    QueryChangeRequestStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    accepted: 'default',
    rejected: 'destructive',
    completed: 'secondary',
    cancelled: 'secondary',
};

export function ChangeRequestStatusBadge({
    status,
}: {
    status: QueryChangeRequestStatus;
}) {
    const { t } = useI18n();
    const labels: Record<QueryChangeRequestStatus, string> = {
        pending: t('changeRequests.statusPending'),
        accepted: t('changeRequests.statusAccepted'),
        rejected: t('changeRequests.statusRejected'),
        completed: t('changeRequests.statusCompleted'),
        cancelled: t('changeRequests.statusCancelled'),
    };

    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}

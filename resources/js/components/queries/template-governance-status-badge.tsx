import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/i18n/i18n-context';
import type {
    QueryTemplateGovernanceStatus,
    QueryTemplateVersionStatus,
} from '@/types/query-template-governance';

type TemplateStatus =
    | QueryTemplateGovernanceStatus
    | QueryTemplateVersionStatus;

const variants: Record<
    TemplateStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'outline',
    review: 'secondary',
    published: 'default',
    archived: 'destructive',
    superseded: 'outline',
};

export function TemplateGovernanceStatusBadge({
    status,
}: {
    status: TemplateStatus;
}) {
    const { t } = useI18n();
    const labels: Record<TemplateStatus, string> = {
        draft: t('templateGovernance.statusDraft'),
        review: t('templateGovernance.statusReview'),
        published: t('templateGovernance.statusPublished'),
        archived: t('templateGovernance.statusArchived'),
        superseded: t('templateGovernance.statusSuperseded'),
    };

    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}

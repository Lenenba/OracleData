import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CircleHelp,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import certifications from '@/routes/query-template-governance/certifications';
import type { QueryTemplateGovernanceDetail } from '@/types/query-template-governance';

type Props = {
    template: QueryTemplateGovernanceDetail;
    canCertify: boolean;
    canRevoke: boolean;
};

type CertificationAction = 'certify' | 'revoke' | null;
type Feedback = { type: 'success' | 'error'; message: string } | null;

function firstError(errors: Record<string, string>, fallback: string) {
    return Object.values(errors)[0] ?? fallback;
}

export function TemplateCertificationCard({
    template,
    canCertify: isCertificationAuthorized,
    canRevoke,
}: Props) {
    const { t, formatDate } = useI18n();
    const [confirmation, setConfirmation] = useState<CertificationAction>(null);
    const [publicNote, setPublicNote] = useState('');
    const [pending, setPending] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);

    const publishedVersion = template.published_version;
    const certification = template.certification;
    const hasOwner = template.business_owner !== null;
    const hasCurrentReviewDate =
        template.review_due_at !== null && !template.is_review_overdue;
    const isPublishedAndActive =
        template.governance_status === 'published' &&
        template.is_active &&
        publishedVersion !== null;
    const meetsCertificationPrerequisites =
        certification === null &&
        isPublishedAndActive &&
        hasOwner &&
        hasCurrentReviewDate;
    const canCertify =
        isCertificationAuthorized && meetsCertificationPrerequisites;

    function submitAction() {
        if (confirmation === null || publishedVersion === null) {
            return;
        }

        const action = confirmation;
        const isCertification = action === 'certify';

        if (
            (isCertification && !isCertificationAuthorized) ||
            (!isCertification && !canRevoke)
        ) {
            return;
        }

        let route: string;
        let payload: Record<string, number | string | null>;

        if (isCertification) {
            route = certifications.store.url(template.slug);
            payload = {
                template_lock_version: template.lock_version,
                published_version_id: publishedVersion.id,
                public_note: publicNote.trim() || null,
            };
        } else {
            if (certification === null) {
                return;
            }

            route = certifications.revoke.url({
                queryTemplate: template.slug,
                queryTemplateCertification: certification.id,
            });
            payload = {
                template_lock_version: template.lock_version,
                certification_lock_version: certification.lock_version,
            };
        }

        setFeedback(null);
        router.post(route, payload, {
            preserveScroll: true,
            onStart: () => setPending(true),
            onError: (errors) => {
                setConfirmation(null);
                setFeedback({
                    type: 'error',
                    message: firstError(
                        errors,
                        t('templateGovernance.certificationActionError'),
                    ),
                });
            },
            onSuccess: () => {
                setConfirmation(null);
                setPublicNote('');
                setFeedback({
                    type: 'success',
                    message: isCertification
                        ? t('templateGovernance.certificationSuccess')
                        : t('templateGovernance.revocationSuccess'),
                });
            },
            onFinish: () => setPending(false),
        });
    }

    return (
        <>
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck aria-hidden="true" />
                                {t('templateGovernance.certificationTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'templateGovernance.certificationDescription',
                                )}
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                {t('templateGovernance.officialTemplateBadge')}
                            </Badge>
                            {certification?.is_effective && (
                                <Badge>
                                    <ShieldCheck aria-hidden="true" />
                                    {t('templateGovernance.certifiedBadge')}
                                </Badge>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-5">
                    {feedback && (
                        <Alert
                            variant={
                                feedback.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                            aria-live={
                                feedback.type === 'error'
                                    ? 'assertive'
                                    : 'polite'
                            }
                        >
                            <CircleHelp aria-hidden="true" />
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('templateGovernance.errorTitle')
                                    : t('templateGovernance.successTitle')}
                            </AlertTitle>
                            <AlertDescription className="space-y-3">
                                <p>{feedback.message}</p>
                                {feedback.type === 'error' && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setFeedback(null);
                                            router.reload();
                                        }}
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        {t('templateGovernance.reloadState')}
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}

                    {certification ? (
                        <div className="space-y-4">
                            {!certification.is_effective && (
                                <Alert variant="destructive">
                                    <AlertTriangle aria-hidden="true" />
                                    <AlertTitle>
                                        {t(
                                            'templateGovernance.certificationExpiredTitle',
                                        )}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {t(
                                            'templateGovernance.certificationExpiredDescription',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            )}
                            <dl className="grid gap-4 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="font-medium">
                                        {t(
                                            'templateGovernance.certifiedVersion',
                                        )}
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        v{certification.version_number}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-medium">
                                        {t('templateGovernance.certifiedBy')}
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        {certification.certified_by?.name ??
                                            '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="font-medium">
                                        {t('templateGovernance.certifiedAt')}
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        {formatDate(
                                            certification.certified_at,
                                            {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            },
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            {certification.public_note && (
                                <div className="rounded-lg border bg-muted/30 p-4">
                                    <p className="text-sm font-medium">
                                        {t(
                                            'templateGovernance.publicNoteLabel',
                                        )}
                                    </p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                        {certification.public_note}
                                    </p>
                                </div>
                            )}
                            <Alert>
                                <AlertTriangle aria-hidden="true" />
                                <AlertTitle>
                                    {t(
                                        'templateGovernance.certificationVersionBound',
                                    )}
                                </AlertTitle>
                                <AlertDescription>
                                    {t(
                                        'templateGovernance.certificationVersionBoundDescription',
                                    )}
                                </AlertDescription>
                            </Alert>
                            {canRevoke && (
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setConfirmation('revoke')
                                        }
                                    >
                                        {t(
                                            'templateGovernance.revokeCertification',
                                        )}
                                    </Button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'templateGovernance.notCertifiedDescription',
                                )}
                            </p>
                            <ul className="grid gap-2 text-sm sm:grid-cols-3">
                                <li className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            isPublishedAndActive
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {isPublishedAndActive ? '✓' : '—'}
                                    </Badge>
                                    {t(
                                        'templateGovernance.certificationPrerequisitePublished',
                                    )}
                                </li>
                                <li className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            hasOwner ? 'default' : 'outline'
                                        }
                                    >
                                        {hasOwner ? '✓' : '—'}
                                    </Badge>
                                    {t(
                                        'templateGovernance.certificationPrerequisiteOwner',
                                    )}
                                </li>
                                <li className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            hasCurrentReviewDate
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {hasCurrentReviewDate ? '✓' : '—'}
                                    </Badge>
                                    {t(
                                        'templateGovernance.certificationPrerequisiteReview',
                                    )}
                                </li>
                            </ul>
                            {!meetsCertificationPrerequisites && (
                                <Alert>
                                    <CircleHelp aria-hidden="true" />
                                    <AlertTitle>
                                        {t(
                                            'templateGovernance.certificationUnavailableTitle',
                                        )}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {t(
                                            'templateGovernance.certificationUnavailableDescription',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {isCertificationAuthorized && (
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            setConfirmation('certify')
                                        }
                                        disabled={!canCertify}
                                    >
                                        <ShieldCheck aria-hidden="true" />
                                        {t(
                                            'templateGovernance.certifyPublishedVersion',
                                        )}
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={confirmation !== null}
                onOpenChange={(open) =>
                    !open && !pending && setConfirmation(null)
                }
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirmation === 'certify'
                                ? t('templateGovernance.certifyConfirmTitle')
                                : t('templateGovernance.revokeConfirmTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {confirmation === 'certify'
                                ? t(
                                      'templateGovernance.certifyConfirmDescription',
                                      {
                                          version:
                                              publishedVersion?.version_number ??
                                              '',
                                      },
                                  )
                                : t(
                                      'templateGovernance.revokeConfirmDescription',
                                  )}
                        </DialogDescription>
                    </DialogHeader>
                    {confirmation === 'certify' && (
                        <div className="grid gap-2">
                            <Label htmlFor="certification-public-note">
                                {t('templateGovernance.publicNoteLabel')}
                            </Label>
                            <Textarea
                                id="certification-public-note"
                                value={publicNote}
                                onChange={(event) =>
                                    setPublicNote(event.target.value)
                                }
                                maxLength={500}
                                rows={4}
                                disabled={pending}
                                placeholder={t(
                                    'templateGovernance.publicNotePlaceholder',
                                )}
                            />
                            <p className="text-right text-xs text-muted-foreground">
                                {t('templateGovernance.characterCount', {
                                    count: publicNote.length,
                                    max: 500,
                                })}
                            </p>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmation(null)}
                            disabled={pending}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant={
                                confirmation === 'revoke'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={submitAction}
                            disabled={pending}
                        >
                            {pending && (
                                <Spinner aria-label={t('common.loading')} />
                            )}
                            {confirmation === 'certify'
                                ? t(
                                      'templateGovernance.certifyPublishedVersion',
                                  )
                                : t('templateGovernance.revokeCertification')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

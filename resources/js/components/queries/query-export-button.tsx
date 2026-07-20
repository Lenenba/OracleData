import { Download, FileDown, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useQueryExport } from '@/hooks/use-query-export';
import { useI18n } from '@/i18n/i18n-context';
import { download as downloadExport } from '@/routes/exports';

type Props = {
    queryId: number;
    tenant: string;
    disabled?: boolean;
};

/**
 * Coexists with the in-browser CSV export: dispatches a server-side export of
 * the full dataset, polls its progress, and offers the file once ready.
 */
export function QueryExportButton({
    queryId,
    tenant,
    disabled = false,
}: Props) {
    const { t } = useI18n();
    const exporter = useQueryExport(queryId);
    const record = exporter.record;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                variant="outline"
                onClick={() => void exporter.start(tenant)}
                disabled={disabled || exporter.isActive}
            >
                {exporter.isActive ? (
                    <Spinner />
                ) : (
                    <FileDown className="size-4" />
                )}
                {t('queries.exportServer')}
            </Button>

            {exporter.isActive && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => void exporter.cancel()}
                >
                    <X className="size-3.5" />
                    {t('queries.cancelExport')}
                </Button>
            )}

            {record?.status === 'queued' && (
                <span className="text-xs text-muted-foreground">
                    {t('queries.exportQueued')}
                </span>
            )}
            {record?.status === 'running' && (
                <span className="text-xs text-muted-foreground">
                    {t('queries.exportProgress', { rows: record.row_count })}
                </span>
            )}

            {record?.status === 'completed' && record.downloadable && (
                <Button asChild variant="secondary" size="sm">
                    <a href={downloadExport.url(record.id)}>
                        <Download className="size-3.5" />
                        {t('queries.exportDownload', {
                            rows: record.row_count,
                        })}
                    </a>
                </Button>
            )}
            {record?.status === 'completed' && record.truncated && (
                <span className="text-xs text-muted-foreground">
                    {t('queries.exportTruncated')}
                </span>
            )}

            {(record?.status === 'failed' || exporter.error) && (
                <span className="text-xs text-destructive">
                    {exporter.error ?? t('queries.exportFailed')}
                </span>
            )}
            {record?.status === 'cancelled' && (
                <span className="text-xs text-muted-foreground">
                    {t('queries.exportCancelled')}
                </span>
            )}
        </div>
    );
}

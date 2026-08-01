import { Download, FileDown, FileJson, FileSpreadsheet, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useQueryExport } from '@/hooks/use-query-export';
import { useI18n } from '@/i18n/i18n-context';
import { download as downloadExport } from '@/routes/exports';

type ExportFormat = 'csv' | 'xlsx' | 'json';

type Props = {
    queryId: number;
    tenant: string;
    disabled?: boolean;
};

const FORMAT_ICONS: Record<ExportFormat, React.ReactNode> = {
    csv: <FileDown className="size-3.5" />,
    xlsx: <FileSpreadsheet className="size-3.5" />,
    json: <FileJson className="size-3.5" />,
};

/**
 * Server-side export button with format selection (CSV, XLSX, JSON — lot 10D).
 * Dispatches a background job, polls progress and offers the download once ready.
 */
export function QueryExportButton({
    queryId,
    tenant,
    disabled = false,
}: Props) {
    const { t } = useI18n();
    const exporter = useQueryExport(queryId);
    const record = exporter.record;
    const [format, setFormat] = useState<ExportFormat>('csv');

    function startExport(selectedFormat: ExportFormat) {
        setFormat(selectedFormat);
        void exporter.start(tenant, selectedFormat);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {/* Primary trigger: last-used format, with dropdown to change */}
            <div className="flex">
                <Button
                    type="button"
                    variant="outline"
                    className="rounded-r-none border-r-0"
                    onClick={() => startExport(format)}
                    disabled={disabled || exporter.isActive}
                >
                    {exporter.isActive ? <Spinner /> : FORMAT_ICONS[format]}
                    {t('queries.exportServer')}
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="rounded-l-none px-2"
                            disabled={disabled || exporter.isActive}
                            aria-label={t('queries.exportFormatChoose')}
                        >
                            <span className="sr-only">
                                {t('queries.exportFormatChoose')}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                ▾
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => startExport('csv')}>
                            <FileDown className="mr-2 size-3.5" />
                            {t('queries.exportFormatCsv')}
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => startExport('xlsx')}>
                            <FileSpreadsheet className="mr-2 size-3.5" />
                            {t('queries.exportFormatXlsx')}
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => startExport('json')}>
                            <FileJson className="mr-2 size-3.5" />
                            {t('queries.exportFormatJson')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

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

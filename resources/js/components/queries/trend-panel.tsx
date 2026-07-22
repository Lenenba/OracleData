import { TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n/i18n-context';

type AggregatePoint = {
    date: string;
    run_count: number;
    rows_min: number;
    rows_max: number;
    duration_min_ms: number;
    duration_max_ms: number;
    duration_avg_ms: number;
    last_run_at: string | null;
};

type Props = {
    queryId: number;
    aggregatesUrl: string;
};

const SVG_H = 80;
const SVG_W = 480;
const PADDING = { top: 6, right: 12, bottom: 24, left: 44 };

function lerp(
    value: number,
    inMin: number,
    inMax: number,
    outMin: number,
    outMax: number,
): number {
    if (inMax === inMin) {
        return (outMin + outMax) / 2;
    }

    return outMin + ((value - inMin) / (inMax - inMin)) * (outMax - outMin);
}

function ChartLine({
    points,
    color,
}: {
    points: Array<{ x: number; y: number }>;
    color: string;
}) {
    if (points.length < 2) {
        return null;
    }

    const d = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`,
        )
        .join(' ');

    return (
        <path
            d={d}
            fill="none"
            stroke={color}
            strokeWidth={1.5}
            strokeLinejoin="round"
        />
    );
}

/**
 * Lot 10C — mini trend chart showing daily row counts and durations over the
 * last 90 days. Data is fetched once on mount via the aggregates endpoint.
 */
export function TrendPanel({ aggregatesUrl }: Props) {
    const { t, formatNumber } = useI18n();
    const [data, setData] = useState<AggregatePoint[] | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;

        fetch(aggregatesUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((json: unknown) => {
                if (cancelled || !Array.isArray(json)) {
                    return;
                }

                setData(json as AggregatePoint[]);
            })
            .catch(() => {
                if (!cancelled) {
                    setData([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [aggregatesUrl]);

    if (loading) {
        return (
            <div className="h-32 animate-pulse rounded-xl border bg-muted/20" />
        );
    }

    if (!data || data.length === 0) {
        return (
            <div className="flex items-center gap-2 rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                <TrendingUp className="size-4 shrink-0" />
                {t('queries.trendNoData')}
            </div>
        );
    }

    const chartW = SVG_W - PADDING.left - PADDING.right;
    const chartH = SVG_H - PADDING.top - PADDING.bottom;

    const maxRows = Math.max(...data.map((d) => d.rows_max), 1);
    const maxDur = Math.max(...data.map((d) => d.duration_avg_ms), 1);

    const rowPoints = data.map((d, i) => ({
        x: PADDING.left + lerp(i, 0, data.length - 1, 0, chartW),
        y: PADDING.top + lerp(d.rows_max, 0, maxRows, chartH, 0),
    }));

    const durPoints = data.map((d, i) => ({
        x: PADDING.left + lerp(i, 0, data.length - 1, 0, chartW),
        y: PADDING.top + lerp(d.duration_avg_ms, 0, maxDur, chartH, 0),
    }));

    const lastPoint = data[data.length - 1];
    const totalRuns = data.reduce((s, d) => s + d.run_count, 0);

    return (
        <div className="card card-body space-y-2 !p-4">
            <div className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-1.5 text-sm font-medium">
                    <TrendingUp className="size-4 text-muted-foreground" />
                    {t('queries.trendTitle')}
                </div>
                <div className="flex items-center gap-3 text-xs text-muted-foreground">
                    <span>{t('queries.trendDays', { n: data.length })}</span>
                    <span>{t('queries.trendRuns', { n: totalRuns })}</span>
                    {lastPoint && (
                        <span>
                            {t('queries.trendLastRows', {
                                n: formatNumber(lastPoint.rows_max),
                            })}
                        </span>
                    )}
                </div>
            </div>

            <svg
                viewBox={`0 0 ${SVG_W} ${SVG_H}`}
                className="w-full"
                aria-label={t('queries.trendChartLabel')}
                role="img"
            >
                {/* Y-axis tick labels */}
                <text
                    x={PADDING.left - 4}
                    y={PADDING.top + 4}
                    textAnchor="end"
                    fontSize={9}
                    fill="currentColor"
                    className="text-muted-foreground"
                >
                    {formatNumber(maxRows)}
                </text>
                <text
                    x={PADDING.left - 4}
                    y={PADDING.top + chartH + 4}
                    textAnchor="end"
                    fontSize={9}
                    fill="currentColor"
                    className="text-muted-foreground"
                >
                    0
                </text>

                {/* Zero baseline */}
                <line
                    x1={PADDING.left}
                    x2={SVG_W - PADDING.right}
                    y1={PADDING.top + chartH}
                    y2={PADDING.top + chartH}
                    stroke="currentColor"
                    strokeOpacity={0.12}
                    strokeWidth={1}
                />

                {/* Row-count line (accent blue) */}
                <ChartLine
                    points={rowPoints}
                    color="var(--color-primary, #3b82d4)"
                />

                {/* Duration line (muted purple) */}
                <ChartLine
                    points={durPoints}
                    color="var(--color-muted-foreground, #7c5cd8)"
                />

                {/* X-axis first/last date labels */}
                {data.length > 0 && (
                    <>
                        <text
                            x={PADDING.left}
                            y={SVG_H - 4}
                            fontSize={9}
                            fill="currentColor"
                            className="text-muted-foreground"
                        >
                            {data[0].date}
                        </text>
                        <text
                            x={SVG_W - PADDING.right}
                            y={SVG_H - 4}
                            textAnchor="end"
                            fontSize={9}
                            fill="currentColor"
                            className="text-muted-foreground"
                        >
                            {data[data.length - 1].date}
                        </text>
                    </>
                )}
            </svg>

            {/* Legend */}
            <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span className="flex items-center gap-1">
                    <span className="inline-block h-0.5 w-5 rounded bg-primary" />
                    {t('queries.trendLegendRows')}
                </span>
                <span className="flex items-center gap-1">
                    <span className="inline-block h-0.5 w-5 rounded bg-muted-foreground opacity-60" />
                    {t('queries.trendLegendDuration')}
                </span>
            </div>
        </div>
    );
}

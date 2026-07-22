import { TrendingDown, TrendingUp } from 'lucide-react';

/**
 * Sparkline SVG maison (aucune librairie) : trace la série normalisée dans un
 * viewBox fixe. Rendue uniquement quand une vraie série est fournie.
 */
function Sparkline({ series }: { series: number[] }) {
    const width = 112;
    const height = 36;
    const pad = 2;

    const max = Math.max(...series);
    const min = Math.min(...series);
    const range = max - min || 1;
    const step =
        series.length > 1 ? (width - pad * 2) / (series.length - 1) : 0;

    const points = series
        .map((value, i) => {
            const x = pad + i * step;
            const y =
                height - pad - ((value - min) / range) * (height - pad * 2);

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className="h-9 w-28 shrink-0 text-foreground/70"
            aria-hidden="true"
        >
            <polyline
                points={points}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export type StatTrend = {
    direction: 'up' | 'down';
    label: string;
};

/**
 * Carte de statistique Paces : titre compact, valeur forte et tendance légère.
 */
export function StatCard({
    label,
    value,
    caption,
    trend,
    series,
}: {
    label: string;
    value: string | number;
    caption?: string;
    trend?: StatTrend;
    series?: number[];
}) {
    return (
        <div className="card h-full">
            <div className="card-body flex h-full flex-col gap-3">
                <p className="text-[13px] font-bold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>

                <div className="flex items-end justify-between gap-3">
                    <span className="text-2xl font-semibold tracking-tight text-foreground tabular-nums">
                        {value}
                    </span>
                    {series && series.length > 1 && (
                        <Sparkline series={series} />
                    )}
                </div>

                {(trend || caption) && (
                    <div className="flex items-center gap-2 text-xs">
                        {trend && (
                            <span
                                className={[
                                    'inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-semibold',
                                    trend.direction === 'up'
                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
                                        : 'bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-400',
                                ].join(' ')}
                            >
                                {trend.direction === 'up' ? (
                                    <TrendingUp className="size-3" />
                                ) : (
                                    <TrendingDown className="size-3" />
                                )}
                                {trend.label}
                            </span>
                        )}
                        {caption && (
                            <span className="text-muted-foreground">
                                {caption}
                            </span>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

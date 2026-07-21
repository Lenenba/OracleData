import { Link } from '@inertiajs/react';
import { BarChart3, Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import { recommendations as queriesRecommendations } from '@/routes/queries';

type Recommendation = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string | null;
    mode: string;
    access_level: string;
    execution_count: number;
    last_run_at: string | null;
};

/**
 * Lot 12E — Moteur de recommandations.
 * Panneau compact affiché sur la page dashboard ou la bibliothèque de requêtes.
 */
export function QueryRecommendations() {
    const { t, formatDate } = useI18n();
    const [recommendations, setRecommendations] = useState<Recommendation[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetch(queriesRecommendations.url(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data: { recommendations: Recommendation[] }) => {
                setRecommendations(data.recommendations ?? []);
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return null;
    }

    if (recommendations.length === 0) {
        return null;
    }

    return (
        <section className="space-y-3">
            <div>
                <h3 className="text-sm font-semibold">{t('recommendations.title')}</h3>
                <p className="text-xs text-muted-foreground">{t('recommendations.description')}</p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {recommendations.map((rec) => (
                    <Link
                        key={rec.id}
                        href={`/queries/${rec.id}`}
                        className="flex flex-col gap-1.5 rounded-lg border bg-card p-3 text-sm transition-colors hover:border-primary/40 hover:bg-primary/5"
                    >
                        <span className="flex items-start gap-1.5 font-medium leading-snug">
                            <BarChart3 className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                            <span className="line-clamp-2">{rec.name}</span>
                        </span>
                        {rec.description && (
                            <span className="line-clamp-2 text-xs text-muted-foreground">
                                {rec.description}
                            </span>
                        )}
                        <div className="mt-auto flex items-center gap-2 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <BarChart3 className="size-3" />
                                {t('recommendations.runCount', { count: rec.execution_count })}
                            </span>
                            <span className="flex items-center gap-1">
                                <Clock className="size-3" />
                                {rec.last_run_at
                                    ? t('recommendations.lastRun', { date: formatDate(rec.last_run_at) })
                                    : t('recommendations.neverRun')}
                            </span>
                        </div>
                        {rec.access_level === 'organization' && (
                            <Badge variant="outline" className="w-fit text-[10px]">
                                {rec.access_level}
                            </Badge>
                        )}
                    </Link>
                ))}
            </div>
        </section>
    );
}

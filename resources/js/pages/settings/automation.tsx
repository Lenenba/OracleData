import { Head, router, useForm } from '@inertiajs/react';
import { CalendarClock, Plus, Trash2, Webhook } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/i18n/i18n-context';
import type { TranslationKey } from '@/i18n/i18n-context';
import alertsRoutes from '@/routes/alerts';
import automationRoutes from '@/routes/automation';
import schedulesRoutes from '@/routes/schedules';
import webhooksRoutes from '@/routes/webhooks';

type Frequency = 'hourly' | 'daily' | 'weekly';
type Condition = 'row_count_above' | 'row_count_below' | 'run_failed';

type Alert = {
    id: number;
    name: string;
    condition: Condition;
    threshold: number | null;
    is_active: boolean;
    last_triggered_at: string | null;
};

type Schedule = {
    id: number;
    name: string;
    query: { id: number; name: string };
    tenant_key: string;
    frequency: Frequency;
    time_of_day: string | null;
    day_of_week: number | null;
    is_active: boolean;
    last_status: string | null;
    last_run_at: string | null;
    next_run_at: string | null;
    last_run: { status: string; row_count: number; ran_at: string } | null;
    alerts: Alert[];
};

type WebhookEndpoint = {
    id: number;
    name: string;
    url: string;
    events: string[];
    is_active: boolean;
    last_delivered_at: string | null;
};

type AutomationProps = {
    schedules: Schedule[];
    schedulableQueries: Array<{ id: number; name: string }>;
    tenants: Record<string, string>;
    defaultTenant: string;
    webhooks: WebhookEndpoint[];
    webhookEvents: string[];
};

const DAYS = [0, 1, 2, 3, 4, 5, 6] as const;
const DAY_KEYS = [
    'automation.daySunday',
    'automation.dayMonday',
    'automation.dayTuesday',
    'automation.dayWednesday',
    'automation.dayThursday',
    'automation.dayFriday',
    'automation.daySaturday',
] as TranslationKey[];

function NewScheduleForm({
    queries,
    tenants,
    defaultTenant,
}: {
    queries: Array<{ id: number; name: string }>;
    tenants: Record<string, string>;
    defaultTenant: string;
}) {
    const { t } = useI18n();
    const tenantKeys = Object.keys(tenants);
    const { data, setData, post, processing, errors, reset } = useForm({
        query_id: queries[0]?.id ?? 0,
        name: '',
        tenant: tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (tenantKeys[0] ?? ''),
        frequency: 'daily' as Frequency,
        time_of_day: '08:00',
        day_of_week: 1,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        post(schedulesRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => reset('name'),
        });
    }

    if (queries.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('automation.noSchedulableQueries')}
            </p>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
        >
            <div className="grid gap-1.5">
                <Label>{t('automation.query')}</Label>
                <Select
                    value={String(data.query_id)}
                    onValueChange={(value) =>
                        setData('query_id', Number(value))
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {queries.map((query) => (
                            <SelectItem key={query.id} value={String(query.id)}>
                                {query.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.query_id} />
            </div>

            <div className="grid gap-1.5">
                <Label>{t('automation.scheduleName')}</Label>
                <Input
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-1.5">
                <Label>{t('automation.environment')}</Label>
                <Select
                    value={data.tenant}
                    onValueChange={(value) => setData('tenant', value)}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {tenantKeys.map((key) => (
                            <SelectItem key={key} value={key}>
                                {tenants[key]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.tenant} />
            </div>

            <div className="grid gap-1.5">
                <Label>{t('automation.frequency')}</Label>
                <Select
                    value={data.frequency}
                    onValueChange={(value) =>
                        setData('frequency', value as Frequency)
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="hourly">
                            {t('automation.frequencyHourly')}
                        </SelectItem>
                        <SelectItem value="daily">
                            {t('automation.frequencyDaily')}
                        </SelectItem>
                        <SelectItem value="weekly">
                            {t('automation.frequencyWeekly')}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            {data.frequency !== 'hourly' && (
                <div className="grid gap-1.5">
                    <Label>{t('automation.timeOfDay')}</Label>
                    <Input
                        type="time"
                        value={data.time_of_day}
                        onChange={(event) =>
                            setData('time_of_day', event.target.value)
                        }
                    />
                    <InputError message={errors.time_of_day} />
                </div>
            )}

            {data.frequency === 'weekly' && (
                <div className="grid gap-1.5">
                    <Label>{t('automation.dayOfWeek')}</Label>
                    <Select
                        value={String(data.day_of_week)}
                        onValueChange={(value) =>
                            setData('day_of_week', Number(value))
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {DAYS.map((day) => (
                                <SelectItem key={day} value={String(day)}>
                                    {t(DAY_KEYS[day])}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.day_of_week} />
                </div>
            )}

            <div className="sm:col-span-2">
                <Button type="submit" size="sm" disabled={processing}>
                    <Plus className="size-3.5" />
                    {t('automation.createSchedule')}
                </Button>
            </div>
        </form>
    );
}

function AlertForm({ scheduleId }: { scheduleId: number }) {
    const { t } = useI18n();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        condition: 'row_count_above' as Condition,
        threshold: 100,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        post(alertsRoutes.store.url(scheduleId), {
            preserveScroll: true,
            onSuccess: () => reset('name'),
        });
    }

    return (
        <form
            onSubmit={submit}
            className="flex flex-wrap items-end gap-2 border-t pt-3"
        >
            <div className="grid flex-1 gap-1.5">
                <Label className="text-xs">{t('automation.alertName')}</Label>
                <Input
                    className="h-8"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label className="text-xs">{t('automation.condition')}</Label>
                <Select
                    value={data.condition}
                    onValueChange={(value) =>
                        setData('condition', value as Condition)
                    }
                >
                    <SelectTrigger className="h-8 w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="row_count_above">
                            {t('automation.conditionAbove')}
                        </SelectItem>
                        <SelectItem value="row_count_below">
                            {t('automation.conditionBelow')}
                        </SelectItem>
                        <SelectItem value="run_failed">
                            {t('automation.conditionFailed')}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            {data.condition !== 'run_failed' && (
                <div className="grid gap-1.5">
                    <Label className="text-xs">
                        {t('automation.threshold')}
                    </Label>
                    <Input
                        type="number"
                        className="h-8 w-24"
                        value={data.threshold}
                        onChange={(event) =>
                            setData('threshold', Number(event.target.value))
                        }
                    />
                    <InputError message={errors.threshold} />
                </div>
            )}
            <Button
                type="submit"
                size="sm"
                variant="outline"
                disabled={processing}
            >
                <Plus className="size-3.5" />
                {t('automation.addAlert')}
            </Button>
        </form>
    );
}

function ScheduleCard({ schedule }: { schedule: Schedule }) {
    const { t, formatDate } = useI18n();

    function toggleActive() {
        router.patch(
            schedulesRoutes.update.url(schedule.id),
            {
                name: schedule.name,
                frequency: schedule.frequency,
                time_of_day: schedule.time_of_day,
                day_of_week: schedule.day_of_week,
                is_active: !schedule.is_active,
            },
            { preserveScroll: true },
        );
    }

    function removeSchedule() {
        if (confirm(t('automation.deleteScheduleConfirm'))) {
            router.delete(schedulesRoutes.destroy.url(schedule.id), {
                preserveScroll: true,
            });
        }
    }

    function removeAlert(alertId: number) {
        router.delete(alertsRoutes.destroy.url(alertId), {
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex items-center gap-2">
                        <span className="font-medium">{schedule.name}</span>
                        <Badge
                            variant={
                                schedule.is_active ? 'default' : 'secondary'
                            }
                        >
                            {schedule.is_active
                                ? t('automation.active')
                                : t('automation.paused')}
                        </Badge>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {schedule.query.name} · {schedule.tenant_key}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={toggleActive}
                    >
                        {schedule.is_active
                            ? t('automation.pause')
                            : t('automation.resume')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={removeSchedule}
                        className="text-destructive hover:text-destructive"
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                </div>
            </div>

            <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground">
                <span>
                    {t('automation.nextRun')}:{' '}
                    {schedule.next_run_at
                        ? formatDate(schedule.next_run_at, {
                              dateStyle: 'medium',
                              timeStyle: 'short',
                          })
                        : '—'}
                </span>
                {schedule.last_run && (
                    <span>
                        {t('automation.lastRun')}:{' '}
                        {t(
                            schedule.last_run.status === 'succeeded'
                                ? 'automation.runSucceeded'
                                : 'automation.runFailed',
                        )}{' '}
                        ({schedule.last_run.row_count})
                    </span>
                )}
            </div>

            {schedule.alerts.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {schedule.alerts.map((alert) => (
                        <Badge
                            key={alert.id}
                            variant="outline"
                            className="gap-1.5"
                        >
                            {alert.name}
                            <button
                                type="button"
                                onClick={() => removeAlert(alert.id)}
                                className="text-muted-foreground hover:text-destructive"
                                aria-label={t('common.delete')}
                            >
                                <Trash2 className="size-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}

            <AlertForm scheduleId={schedule.id} />
        </div>
    );
}

function NewWebhookForm({ events }: { events: string[] }) {
    const { t } = useI18n();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
        secret: '',
        events: events,
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        post(webhooksRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'url', 'secret'),
        });
    }

    function toggleEvent(name: string, checked: boolean) {
        setData(
            'events',
            checked
                ? [...data.events, name]
                : data.events.filter((event) => event !== name),
        );
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
        >
            <div className="grid gap-1.5">
                <Label>{t('automation.webhookName')}</Label>
                <Input
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label>{t('automation.webhookUrl')}</Label>
                <Input
                    type="url"
                    placeholder="https://"
                    value={data.url}
                    onChange={(event) => setData('url', event.target.value)}
                />
                <InputError message={errors.url} />
            </div>
            <div className="grid gap-1.5 sm:col-span-2">
                <Label>{t('automation.webhookSecret')}</Label>
                <Input
                    value={data.secret}
                    onChange={(event) => setData('secret', event.target.value)}
                />
                <InputError message={errors.secret} />
                <p className="text-xs text-muted-foreground">
                    {t('automation.webhookSecretHint')}
                </p>
            </div>
            <div className="grid gap-1.5 sm:col-span-2">
                <Label>{t('automation.webhookEvents')}</Label>
                <div className="flex flex-wrap gap-3">
                    {events.map((name) => (
                        <label
                            key={name}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={data.events.includes(name)}
                                onCheckedChange={(checked) =>
                                    toggleEvent(name, checked === true)
                                }
                            />
                            {name}
                        </label>
                    ))}
                </div>
                <InputError message={errors.events} />
            </div>
            <div className="sm:col-span-2">
                <Button type="submit" size="sm" disabled={processing}>
                    <Plus className="size-3.5" />
                    {t('automation.createWebhook')}
                </Button>
            </div>
        </form>
    );
}

function WebhookCard({ endpoint }: { endpoint: WebhookEndpoint }) {
    const { t } = useI18n();

    function removeEndpoint() {
        if (confirm(t('automation.deleteWebhookConfirm'))) {
            router.delete(webhooksRoutes.destroy.url(endpoint.id), {
                preserveScroll: true,
            });
        }
    }

    return (
        <div className="flex flex-wrap items-start justify-between gap-2 rounded-xl border p-4">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <span className="font-medium">{endpoint.name}</span>
                    <Badge
                        variant={endpoint.is_active ? 'default' : 'secondary'}
                    >
                        {endpoint.is_active
                            ? t('automation.active')
                            : t('automation.paused')}
                    </Badge>
                </div>
                <p className="truncate font-mono text-xs text-muted-foreground">
                    {endpoint.url}
                </p>
                <div className="mt-1 flex flex-wrap gap-1">
                    {endpoint.events.map((event) => (
                        <Badge key={event} variant="outline">
                            {event}
                        </Badge>
                    ))}
                </div>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={removeEndpoint}
                className="text-destructive hover:text-destructive"
            >
                <Trash2 className="size-3.5" />
            </Button>
        </div>
    );
}

export default function AutomationSettings({
    schedules,
    schedulableQueries,
    tenants,
    defaultTenant,
    webhooks,
    webhookEvents,
}: AutomationProps) {
    const { t } = useI18n();
    const [showWebhookForm, setShowWebhookForm] = useState(false);

    return (
        <>
            <Head title={t('automation.title')} />
            <h1 className="sr-only">{t('automation.title')}</h1>

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title={t('automation.title')}
                    description={t('automation.description')}
                />

                <section className="space-y-4">
                    <div className="flex items-center gap-2">
                        <CalendarClock className="size-5" />
                        <h2 className="font-semibold">
                            {t('automation.schedules')}
                        </h2>
                    </div>

                    <NewScheduleForm
                        queries={schedulableQueries}
                        tenants={tenants}
                        defaultTenant={defaultTenant}
                    />

                    {schedules.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('automation.noSchedules')}
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {schedules.map((schedule) => (
                                <ScheduleCard
                                    key={schedule.id}
                                    schedule={schedule}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Webhook className="size-5" />
                            <h2 className="font-semibold">
                                {t('automation.webhooks')}
                            </h2>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setShowWebhookForm((value) => !value)
                            }
                        >
                            <Plus className="size-3.5" />
                            {t('automation.newWebhook')}
                        </Button>
                    </div>

                    {showWebhookForm && (
                        <NewWebhookForm events={webhookEvents} />
                    )}

                    {webhooks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('automation.noWebhooks')}
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {webhooks.map((endpoint) => (
                                <WebhookCard
                                    key={endpoint.id}
                                    endpoint={endpoint}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

AutomationSettings.layout = {
    breadcrumbs: [
        {
            title: 'Automatisation',
            href: automationRoutes.index(),
        },
    ],
};

import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Clock3,
    Globe2,
    Mail,
    MapPin,
    Palette,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { useI18n } from '@/i18n/i18n-context';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = { auth: Auth };

function InfoRow({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Mail;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-3">
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-light text-foreground">
                <Icon className="size-4" aria-hidden="true" />
            </span>
            <p className="min-w-0 text-[13px] text-muted-foreground">
                {label}{' '}
                <strong className="font-semibold text-foreground">
                    {value}
                </strong>
            </p>
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const { t, formatDate } = useI18n();
    const initials = useInitials();
    const language = {
        fr: 'Français',
        en: 'English',
        es: 'Español',
    }[auth.user.locale];

    const tabs = [
        {
            title: t('settings.profile'),
            href: edit(),
            icon: UserRound,
            active: true,
        },
        {
            title: t('settings.security'),
            href: editSecurity(),
            icon: ShieldCheck,
            active: false,
        },
        {
            title: t('settings.appearance'),
            href: editAppearance(),
            icon: Palette,
            active: false,
        },
    ];

    return (
        <>
            <Head title={t('profile.title')} />

            <div className="p-5">
                <Heading title={t('settings.profile')} />

                <div
                    className="relative min-h-[300px] overflow-hidden rounded bg-cover bg-center"
                    style={{
                        backgroundImage: "url('/images/paces/profile-bg.jpg')",
                    }}
                >
                    <div className="absolute inset-0 flex flex-col items-center justify-center bg-gradient-to-t from-[#313a46] via-[#313a46cc] to-[#313a4680] p-8 text-center">
                        <h2 className="max-w-3xl text-2xl font-semibold text-white italic">
                            “{t('profile.heroQuote')}”
                        </h2>
                        <p className="mt-2 text-sm text-white">— OracleData</p>
                    </div>
                </div>

                <div className="relative z-10 -mt-[30px] px-4 sm:px-6">
                    <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
                        <aside className="card lg:sticky lg:top-[85px]">
                            <div className="card-body">
                                <div className="mb-[30px] flex items-center gap-4">
                                    <Avatar className="size-[72px] shrink-0 ring-4 ring-card">
                                        <AvatarImage
                                            src={auth.user.avatar}
                                            alt={auth.user.name}
                                            className="object-cover"
                                        />
                                        <AvatarFallback className="bg-primary text-lg font-bold text-primary-foreground">
                                            {initials(auth.user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <h2 className="truncate text-[15px] font-semibold">
                                            {auth.user.name}
                                        </h2>
                                        <p className="truncate text-[13px] text-muted-foreground">
                                            {auth.user.email}
                                        </p>
                                        <Badge
                                            variant="secondary"
                                            className="mt-2"
                                        >
                                            {auth.user.is_super_admin
                                                ? t('profile.administrator')
                                                : t('profile.member')}
                                        </Badge>
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <InfoRow
                                        icon={Mail}
                                        label={t('profile.email')}
                                        value={auth.user.email}
                                    />
                                    <InfoRow
                                        icon={Globe2}
                                        label={t('profile.language')}
                                        value={language}
                                    />
                                    <InfoRow
                                        icon={Clock3}
                                        label={t('profile.timezone')}
                                        value={auth.user.timezone}
                                    />
                                    <InfoRow
                                        icon={CalendarDays}
                                        label={t('profile.memberSince')}
                                        value={formatDate(
                                            auth.user.created_at,
                                            {
                                                month: 'long',
                                                year: 'numeric',
                                            },
                                        )}
                                    />
                                </div>

                                <h3 className="mt-[30px] mb-4 text-[15px] font-semibold">
                                    {t('profile.workspace')}
                                </h3>
                                <div className="flex flex-wrap gap-1.5">
                                    <Badge variant="secondary">
                                        Oracle Fusion
                                    </Badge>
                                    <Badge variant="secondary">
                                        {language}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {t('profile.secureAccess')}
                                    </Badge>
                                </div>
                            </div>
                        </aside>

                        <section className="card lg:col-span-2">
                            <div className="card-header min-h-14">
                                <h2 className="card-title">
                                    {t('profile.myAccount')}
                                </h2>
                                <nav
                                    className="-my-[15px] -mr-3 flex min-h-14 items-stretch gap-1 overflow-x-auto"
                                    aria-label={t('profile.myAccount')}
                                >
                                    {tabs.map((tab) => {
                                        const Icon = tab.icon;

                                        return (
                                            <Link
                                                key={tab.title}
                                                href={tab.href}
                                                aria-current={
                                                    tab.active
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className={cn(
                                                    'flex shrink-0 items-center gap-2 border-b-2 border-transparent px-4 text-[13px] font-semibold text-muted-foreground transition-colors hover:text-primary',
                                                    tab.active &&
                                                        'border-primary text-primary',
                                                )}
                                            >
                                                <Icon
                                                    className="size-4 md:hidden"
                                                    aria-hidden="true"
                                                />
                                                <span>{tab.title}</span>
                                            </Link>
                                        );
                                    })}
                                </nav>
                            </div>

                            <div className="card-body space-y-8">
                                <Form
                                    {...ProfileController.update.form()}
                                    options={{ preserveScroll: true }}
                                    className="space-y-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="flex items-center justify-center gap-2 rounded border border-dashed border-border bg-muted/45 px-3 py-1.5 text-[11px] font-bold tracking-[0.08em] uppercase">
                                                <UserRound
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('profile.personalInfo')}
                                            </div>

                                            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                                <div>
                                                    <Label
                                                        htmlFor="name"
                                                        className="form-label"
                                                    >
                                                        {t('profile.name')}
                                                    </Label>
                                                    <Input
                                                        id="name"
                                                        name="name"
                                                        defaultValue={
                                                            auth.user.name
                                                        }
                                                        autoComplete="name"
                                                        placeholder={t(
                                                            'profile.fullName',
                                                        )}
                                                        required
                                                    />
                                                    <InputError
                                                        className="mt-1.5"
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <div>
                                                    <Label
                                                        htmlFor="email"
                                                        className="form-label"
                                                    >
                                                        {t('profile.email')}
                                                    </Label>
                                                    <Input
                                                        id="email"
                                                        type="email"
                                                        name="email"
                                                        defaultValue={
                                                            auth.user.email
                                                        }
                                                        autoComplete="username"
                                                        required
                                                    />
                                                    <InputError
                                                        className="mt-1.5"
                                                        message={errors.email}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-center gap-2 rounded border border-dashed border-border bg-muted/45 px-3 py-1.5 text-[11px] font-bold tracking-[0.08em] uppercase">
                                                <MapPin
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t(
                                                    'profile.regionalPreferences',
                                                )}
                                            </div>

                                            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                                <div>
                                                    <Label
                                                        htmlFor="locale"
                                                        className="form-label"
                                                    >
                                                        {t('profile.language')}
                                                    </Label>
                                                    <select
                                                        id="locale"
                                                        name="locale"
                                                        defaultValue={
                                                            auth.user.locale
                                                        }
                                                        className="form-select"
                                                    >
                                                        <option value="fr">
                                                            Français
                                                        </option>
                                                        <option value="en">
                                                            English
                                                        </option>
                                                        <option value="es">
                                                            Español
                                                        </option>
                                                    </select>
                                                    <InputError
                                                        className="mt-1.5"
                                                        message={errors.locale}
                                                    />
                                                </div>
                                                <div>
                                                    <Label
                                                        htmlFor="timezone"
                                                        className="form-label"
                                                    >
                                                        {t('profile.timezone')}
                                                    </Label>
                                                    <select
                                                        id="timezone"
                                                        name="timezone"
                                                        defaultValue={
                                                            auth.user.timezone
                                                        }
                                                        className="form-select"
                                                    >
                                                        <option value="America/Toronto">
                                                            America/Toronto
                                                        </option>
                                                        <option value="UTC">
                                                            UTC
                                                        </option>
                                                        <option value="Europe/Paris">
                                                            Europe/Paris
                                                        </option>
                                                        <option value="Europe/Madrid">
                                                            Europe/Madrid
                                                        </option>
                                                    </select>
                                                    <InputError
                                                        className="mt-1.5"
                                                        message={
                                                            errors.timezone
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            {mustVerifyEmail &&
                                                auth.user.email_verified_at ===
                                                    null && (
                                                    <div className="rounded border border-warning/30 bg-warning/10 p-3 text-[13px] text-warning-foreground">
                                                        {t(
                                                            'profile.emailUnverified',
                                                        )}{' '}
                                                        <Link
                                                            href={send()}
                                                            as="button"
                                                            className="font-semibold underline underline-offset-4"
                                                        >
                                                            {t(
                                                                'profile.resendVerification',
                                                            )}
                                                        </Link>
                                                        {status ===
                                                            'verification-link-sent' && (
                                                            <p className="mt-2 font-semibold text-success">
                                                                {t(
                                                                    'profile.verificationSent',
                                                                )}
                                                            </p>
                                                        )}
                                                    </div>
                                                )}

                                            <div className="flex justify-end border-t pt-5">
                                                <Button
                                                    disabled={processing}
                                                    data-test="update-profile-button"
                                                >
                                                    {processing
                                                        ? t('common.saving')
                                                        : t('common.save')}
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>

                                <div className="border-t border-border pt-7">
                                    <DeleteUser />
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};

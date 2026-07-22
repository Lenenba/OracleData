import { Link, router, usePage } from '@inertiajs/react';
import {
    AppWindow,
    Database,
    Expand,
    Gauge,
    Grid2X2,
    LayoutPanelTop,
    Menu,
    Moon,
    Search,
    Server,
    Settings,
    Sun,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { LanguageSwitcher } from '@/components/language-switcher';
import { NotificationBell } from '@/components/notifications/notification-bell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';
import { useI18n } from '@/i18n/i18n-context';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import dashboards from '@/routes/dashboards';
import groupRoutes from '@/routes/groups';
import oracleTenants from '@/routes/oracle-tenants';
import { edit as editProfile } from '@/routes/profile';
import queries from '@/routes/queries';
import queryTemplates from '@/routes/query-templates';
import type { BreadcrumbItem as BreadcrumbItemType, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItemType[];
    collapsed: boolean;
    onToggle: () => void;
};

type QuickLink = NavItem & {
    icon: LucideIcon;
    description?: string;
};

export function AppSidebarHeader({
    breadcrumbs = [],
    collapsed,
    onToggle,
}: Props) {
    const { auth } = usePage().props;
    const { t } = useI18n();
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const getInitials = useInitials();
    const [search, setSearch] = useState('');
    const [searchFocused, setSearchFocused] = useState(false);

    const quickLinks = useMemo<QuickLink[]>(
        () => [
            {
                title: t('nav.dashboard'),
                href: dashboard(),
                icon: Gauge,
            },
            {
                title: t('queries.title'),
                href: queries.index(),
                icon: Database,
            },
            {
                title: t('nav.queryTemplates'),
                href: queryTemplates.index(),
                icon: AppWindow,
            },
            {
                title: t('nav.dashboards'),
                href: dashboards.index(),
                icon: LayoutPanelTop,
            },
            {
                title: t('nav.groups'),
                href: groupRoutes.index(),
                icon: UsersRound,
            },
            {
                title: t('nav.oracleConnections'),
                href: oracleTenants.index(),
                icon: Server,
            },
        ],
        [t],
    );

    const filteredLinks = quickLinks.filter((item) =>
        item.title.toLocaleLowerCase().includes(search.toLocaleLowerCase()),
    );

    function submitSearch(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const target = filteredLinks[0];

        if (target) {
            router.visit(toUrl(target.href));
            setSearch('');
            setSearchFocused(false);
        }
    }

    async function toggleFullscreen() {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await document.documentElement.requestFullscreen();
        }
    }

    const isDark = resolvedAppearance === 'dark';

    return (
        <header className="paces-app-header">
            <div className="paces-topbar-container">
                <div className="paces-topbar-left">
                    <button
                        type="button"
                        className="paces-topbar-icon is-menu-toggle"
                        onClick={onToggle}
                        aria-label={t('nav.platform')}
                        aria-expanded={!collapsed}
                    >
                        <Menu aria-hidden="true" />
                    </button>

                    <div className="paces-search-wrap">
                        <form onSubmit={submitSearch}>
                            <Search
                                className="paces-search-icon"
                                aria-hidden="true"
                            />
                            <input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                onFocus={() => setSearchFocused(true)}
                                onBlur={() =>
                                    window.setTimeout(
                                        () => setSearchFocused(false),
                                        120,
                                    )
                                }
                                className="paces-search-input"
                                placeholder={t('queries.search')}
                                aria-label={t('queries.search')}
                            />
                        </form>

                        {searchFocused && search.trim() !== '' && (
                            <div className="paces-search-results">
                                {filteredLinks.length > 0 ? (
                                    filteredLinks.map((item) => {
                                        const Icon = item.icon;

                                        return (
                                            <Link
                                                key={item.title}
                                                href={item.href}
                                                className="paces-search-result"
                                                onClick={() => {
                                                    setSearch('');
                                                    setSearchFocused(false);
                                                }}
                                            >
                                                <span>
                                                    <Icon aria-hidden="true" />
                                                </span>
                                                {item.title}
                                            </Link>
                                        );
                                    })
                                ) : (
                                    <p className="paces-search-empty">
                                        {t('queries.emptySearch')}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="paces-topbar-text-button hidden lg:flex"
                            >
                                Mega Menu
                                <span aria-hidden="true">⌄</span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            className="paces-mega-menu"
                        >
                            <DropdownMenuLabel>
                                {t('nav.platform')}
                            </DropdownMenuLabel>
                            <div className="grid gap-1 p-1 sm:grid-cols-2">
                                {quickLinks.slice(0, 4).map((item) => {
                                    const Icon = item.icon;

                                    return (
                                        <Link
                                            key={item.title}
                                            href={item.href}
                                            className="paces-mega-link"
                                        >
                                            <Icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="paces-topbar-text-button hidden xl:flex"
                            >
                                Apps <span aria-hidden="true">⌄</span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-64 p-2">
                            <div className="grid grid-cols-2 gap-2">
                                {quickLinks.slice(2).map((item) => {
                                    const Icon = item.icon;

                                    return (
                                        <Link
                                            key={item.title}
                                            href={item.href}
                                            className="paces-app-grid-link"
                                        >
                                            <Icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    {breadcrumbs.length > 0 && (
                        <div className="paces-topbar-breadcrumbs hidden 2xl:block">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                </div>

                <div className="paces-topbar-actions">
                    <button
                        type="button"
                        className="paces-topbar-icon"
                        onClick={() =>
                            updateAppearance(isDark ? 'light' : 'dark')
                        }
                        aria-label={t('settings.appearance')}
                    >
                        {isDark ? (
                            <Sun aria-hidden="true" />
                        ) : (
                            <Moon aria-hidden="true" />
                        )}
                    </button>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="paces-topbar-icon hidden sm:flex"
                                aria-label="Apps"
                            >
                                <Grid2X2 aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64 p-2">
                            <DropdownMenuLabel>Apps</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <div className="grid grid-cols-2 gap-2 pt-1">
                                {quickLinks.slice(0, 4).map((item) => {
                                    const Icon = item.icon;

                                    return (
                                        <Link
                                            key={item.title}
                                            href={item.href}
                                            className="paces-app-grid-link"
                                        >
                                            <Icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <div className="paces-notification-button">
                        <NotificationBell />
                    </div>

                    <button
                        type="button"
                        className="paces-topbar-icon hidden md:flex"
                        onClick={() => void toggleFullscreen()}
                        aria-label="Fullscreen"
                    >
                        <Expand aria-hidden="true" />
                    </button>

                    <Link
                        href={editProfile()}
                        className="paces-topbar-icon hidden md:flex"
                        aria-label={t('nav.settings')}
                    >
                        <Settings aria-hidden="true" />
                        <span className="paces-settings-dot" />
                    </Link>

                    <div className="hidden lg:block">
                        <LanguageSwitcher compact />
                    </div>

                    {auth.user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className="paces-user-trigger"
                                    data-test="sidebar-menu-button"
                                >
                                    <Avatar className="size-8">
                                        <AvatarImage
                                            src={auth.user.avatar ?? undefined}
                                            alt={auth.user.name}
                                        />
                                        <AvatarFallback className="bg-primary text-xs font-semibold text-primary-foreground">
                                            {getInitials(auth.user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="hidden min-w-0 text-left xl:block">
                                        <strong>{auth.user.name}</strong>
                                        <small>
                                            {auth.user.is_super_admin
                                                ? 'Admin'
                                                : 'Member'}
                                        </small>
                                    </span>
                                    <span
                                        className="hidden text-xs xl:block"
                                        aria-hidden="true"
                                    >
                                        ⌄
                                    </span>
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64">
                                <UserMenuContent user={auth.user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>
        </header>
    );
}

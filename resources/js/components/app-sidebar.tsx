import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    BookOpenCheck,
    Bot,
    ChevronDown,
    Database,
    FileSliders,
    FileStack,
    Gauge,
    KeyRound,
    LayoutPanelTop,
    Palette,
    Plus,
    Server,
    Settings,
    Share2,
    ShieldCheck,
    Tags,
    UserRound,
    UsersRound,
    Workflow,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { useI18n } from '@/i18n/i18n-context';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import apiTokens from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import automation from '@/routes/automation';
import categories from '@/routes/categories';
import dashboards from '@/routes/dashboards';
import groupRoutes from '@/routes/groups';
import notifications from '@/routes/notifications';
import oracleTenants from '@/routes/oracle-tenants';
import { edit as editProfile } from '@/routes/profile';
import queries from '@/routes/queries';
import queryTemplateGovernance from '@/routes/query-template-governance';
import queryTemplates from '@/routes/query-templates';
import { edit as editSecurity } from '@/routes/security';
import semanticCatalog from '@/routes/semantic-catalog';
import type { NavItem } from '@/types';

type PacesNavItem = NavItem & {
    key: string;
    children?: PacesNavItem[];
};

type PacesNavSection = {
    label: string;
    items: PacesNavItem[];
};

type Props = {
    collapsed: boolean;
    mobileOpen: boolean;
    onNavigate: () => void;
};

function currentPath(url: string): string {
    return url.split('?')[0].replace(/\/$/, '') || '/';
}

function isItemActive(item: PacesNavItem, path: string): boolean {
    const itemPath = currentPath(toUrl(item.href));

    return (
        path === itemPath ||
        (itemPath !== '/' && path.startsWith(`${itemPath}/`)) ||
        (item.children?.some((child) => isItemActive(child, path)) ?? false)
    );
}

function getActiveChildKey(
    children: PacesNavItem[],
    path: string,
): string | null {
    let activeKey: string | null = null;
    let bestMatchLength = -1;

    for (const child of children) {
        const childPath = currentPath(toUrl(child.href));
        const matches =
            path === childPath ||
            (childPath !== '/' && path.startsWith(`${childPath}/`));

        if (matches && childPath.length > bestMatchLength) {
            activeKey = child.key;
            bestMatchLength = childPath.length;
        }
    }

    return activeKey;
}

function MenuItem({
    item,
    path,
    collapsed,
    expanded,
    onToggle,
    onNavigate,
}: {
    item: PacesNavItem;
    path: string;
    collapsed: boolean;
    expanded: boolean;
    onToggle: () => void;
    onNavigate: () => void;
}) {
    const active = isItemActive(item, path);
    const activeChildKey = item.children
        ? getActiveChildKey(item.children, path)
        : null;
    const Icon = item.icon;

    if (item.children?.length) {
        return (
            <li className={cn('paces-menu-item', active && 'is-active')}>
                <button
                    type="button"
                    className={cn(
                        'paces-menu-link w-full',
                        active && 'is-active',
                    )}
                    onClick={onToggle}
                    aria-expanded={expanded}
                    title={collapsed ? item.title : undefined}
                >
                    {Icon && (
                        <span className="paces-menu-icon">
                            <Icon aria-hidden="true" />
                        </span>
                    )}
                    <span className="paces-menu-text">{item.title}</span>
                    <ChevronDown
                        className={cn(
                            'paces-menu-arrow',
                            expanded && 'rotate-180',
                        )}
                        aria-hidden="true"
                    />
                </button>

                {expanded && !collapsed && (
                    <ul className="paces-sub-menu">
                        {item.children.map((child) => {
                            const childActive = child.key === activeChildKey;

                            return (
                                <li key={child.key}>
                                    <Link
                                        href={child.href}
                                        prefetch
                                        onClick={onNavigate}
                                        aria-current={
                                            childActive ? 'page' : undefined
                                        }
                                        className={cn(
                                            'paces-sub-menu-link',
                                            childActive && 'is-active',
                                        )}
                                    >
                                        <span className="paces-sub-menu-dot" />
                                        <span>{child.title}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </li>
        );
    }

    return (
        <li className={cn('paces-menu-item', active && 'is-active')}>
            <Link
                href={item.href}
                prefetch
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                title={collapsed ? item.title : undefined}
                className={cn('paces-menu-link', active && 'is-active')}
            >
                {Icon && (
                    <span className="paces-menu-icon">
                        <Icon aria-hidden="true" />
                    </span>
                )}
                <span className="paces-menu-text">{item.title}</span>
            </Link>
        </li>
    );
}

export function AppSidebar({ collapsed, mobileOpen, onNavigate }: Props) {
    const { t } = useI18n();
    const { url, props } = usePage();
    const path = currentPath(url);

    const sections = useMemo<PacesNavSection[]>(() => {
        const oicTenants: Array<{ id: number; label: string; key: string }> =
            (props.oicTenants as Array<{ id: number; label: string; key: string }> | undefined) ?? [];

        const settingsChildren: PacesNavItem[] = [
            {
                key: 'profile',
                title: t('settings.profile'),
                href: editProfile(),
                icon: UserRound,
            },
            {
                key: 'security',
                title: t('settings.security'),
                href: editSecurity(),
                icon: ShieldCheck,
            },
            {
                key: 'appearance',
                title: t('settings.appearance'),
                href: editAppearance(),
                icon: Palette,
            },
            {
                key: 'automation',
                title: t('settings.automation'),
                href: automation.index(),
                icon: Bot,
            },
            {
                key: 'api-tokens',
                title: t('settings.apiTokens'),
                href: apiTokens.index(),
                icon: KeyRound,
            },
        ];

        if (props.auth.user.is_super_admin) {
            settingsChildren.push(
                {
                    key: 'taxonomy',
                    title: t('settings.taxonomy'),
                    href: categories.index(),
                    icon: Tags,
                },
                {
                    key: 'semantic-catalog',
                    title: t('settings.semanticCatalog'),
                    href: semanticCatalog.index(),
                    icon: BookOpenCheck,
                },
            );
        }

        if (props.auth.can_view_query_template_governance) {
            settingsChildren.push({
                key: 'template-governance',
                title: t('settings.templateGovernance'),
                href: queryTemplateGovernance.index(),
                icon: FileStack,
            });
        }

        return [
            {
                label: t('nav.platform'),
                items: [
                    {
                        key: 'dashboard',
                        title: t('nav.dashboard'),
                        href: dashboard(),
                        icon: Gauge,
                    },
                    {
                        key: 'queries',
                        title: t('nav.queries'),
                        href: queries.index(),
                        icon: Database,
                        children: [
                            {
                                key: 'query-library',
                                title: t('queries.title'),
                                href: queries.index(),
                            },
                            {
                                key: 'query-new',
                                title: t('queries.create'),
                                href: queries.create(),
                                icon: Plus,
                            },
                            {
                                key: 'query-shared',
                                title: t('nav.sharedQueries'),
                                href: queries.shared(),
                                icon: Share2,
                            },
                        ],
                    },
                    {
                        key: 'templates',
                        title: t('nav.queryTemplates'),
                        href: queryTemplates.index(),
                        icon: FileSliders,
                    },
                    {
                        key: 'dashboards',
                        title: t('nav.dashboards'),
                        href: dashboards.index(),
                        icon: LayoutPanelTop,
                    },
                    ...(oicTenants.length > 0
                        ? [
                              {
                                  key: 'oic-monitor',
                                  title: t('nav.oicMonitor'),
                                  href: `/oracle-tenants/${oicTenants[0].id}/oic-monitor`,
                                  icon: Workflow,
                                  ...(oicTenants.length > 1
                                      ? {
                                            children: oicTenants.map((ot) => ({
                                                key: `oic-${ot.id}`,
                                                title: ot.label,
                                                href: `/oracle-tenants/${ot.id}/oic-monitor`,
                                            })),
                                        }
                                      : {}),
                              },
                          ]
                        : []),
                ],
            },
            {
                label: t('nav.collaboration'),
                items: [
                    {
                        key: 'groups',
                        title: t('nav.groups'),
                        href: groupRoutes.index(),
                        icon: UsersRound,
                    },
                    {
                        key: 'notifications',
                        title: t('notifications.pageTitle'),
                        href: notifications.index(),
                        icon: Bell,
                    },
                ],
            },
            {
                label: t('settings.title'),
                items: [
                    {
                        key: 'connections',
                        title: t('nav.oracleConnections'),
                        href: oracleTenants.index(),
                        icon: Server,
                    },
                    {
                        key: 'settings',
                        title: t('nav.settings'),
                        href: editProfile(),
                        icon: Settings,
                        children: settingsChildren,
                    },
                ],
            },
        ];
    }, [props.auth, t]);
    const routeOpenMenuKey =
        sections
            .flatMap((section) => section.items)
            .find(
                (item) =>
                    Boolean(item.children?.length) && isItemActive(item, path),
            )?.key ?? null;
    const [accordionState, setAccordionState] = useState<{
        path: string;
        key: string | null;
    } | null>(null);
    const openMenuKey =
        accordionState?.path === path ? accordionState.key : routeOpenMenuKey;

    const handleNavigate = () => {
        setAccordionState({ path, key: null });
        onNavigate();
    };

    return (
        <aside
            className={cn(
                'paces-app-menu',
                collapsed && 'is-collapsed',
                mobileOpen && 'is-mobile-open',
            )}
            aria-label={t('nav.platform')}
        >
            <Link
                href={dashboard()}
                prefetch
                className="paces-logo-box"
                onClick={handleNavigate}
                aria-label="OracleData"
            >
                <span className="paces-brand-mark">
                    <AppLogoIcon aria-hidden="true" />
                </span>
                <span className="paces-brand-name">
                    Oracle<span>Data</span>
                </span>
            </Link>

            <nav className="paces-sidenav-scroll">
                <ul className="paces-side-nav">
                    {sections.map((section) => (
                        <li key={section.label} className="contents">
                            <div className="paces-menu-title">
                                {section.label}
                            </div>
                            <ul className="contents">
                                {section.items.map((item) => (
                                    <MenuItem
                                        key={item.key}
                                        item={item}
                                        path={path}
                                        collapsed={collapsed}
                                        expanded={openMenuKey === item.key}
                                        onToggle={() =>
                                            setAccordionState({
                                                path,
                                                key:
                                                    openMenuKey === item.key
                                                        ? null
                                                        : item.key,
                                            })
                                        }
                                        onNavigate={handleNavigate}
                                    />
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            </nav>
        </aside>
    );
}

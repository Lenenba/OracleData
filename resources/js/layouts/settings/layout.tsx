import { Link, usePage } from '@inertiajs/react';
import {
    Bot,
    BookOpenCheck,
    Database,
    FileStack,
    KeyRound,
    Palette,
    ShieldCheck,
    Tags,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useI18n } from '@/i18n/i18n-context';
import { cn, toUrl } from '@/lib/utils';
import apiTokens from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import automation from '@/routes/automation';
import categories from '@/routes/categories';
import oracleTenants from '@/routes/oracle-tenants';
import { edit } from '@/routes/profile';
import queryTemplateGovernance from '@/routes/query-template-governance';
import { edit as editSecurity } from '@/routes/security';
import semanticCatalog from '@/routes/semantic-catalog';
import type { NavItem } from '@/types';

type SettingsNavItem = NavItem & { icon: LucideIcon };

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { component, props } = usePage();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useI18n();

    if (component === 'settings/profile') {
        return <>{children}</>;
    }

    const navItems: SettingsNavItem[] = [
        { title: t('settings.profile'), href: edit(), icon: UserRound },
        {
            title: t('settings.security'),
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: t('settings.appearance'),
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: t('settings.connections'),
            href: oracleTenants.index(),
            icon: Database,
        },
        {
            title: t('settings.automation'),
            href: automation.index(),
            icon: Bot,
        },
        {
            title: t('settings.apiTokens'),
            href: apiTokens.index(),
            icon: KeyRound,
        },
        ...(props.auth.user.is_super_admin
            ? [
                  {
                      title: t('settings.taxonomy'),
                      href: categories.index(),
                      icon: Tags,
                  },
                  {
                      title: t('settings.semanticCatalog'),
                      href: semanticCatalog.index(),
                      icon: BookOpenCheck,
                  },
              ]
            : []),
        ...(props.auth.can_view_query_template_governance
            ? [
                  {
                      title: t('settings.templateGovernance'),
                      href: queryTemplateGovernance.index(),
                      icon: FileStack,
                  },
              ]
            : []),
    ];

    return (
        <div className="space-y-5 p-5">
            <nav
                className="card flex min-h-14 flex-row items-stretch gap-1 overflow-x-auto px-3"
                aria-label={t('settings.title')}
            >
                {navItems.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);
                    const Icon = item.icon;

                    return (
                        <Link
                            key={toUrl(item.href)}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'relative flex shrink-0 items-center gap-2 border-b-2 border-transparent px-3 text-[13px] font-semibold text-muted-foreground transition-colors hover:text-primary',
                                active && 'border-primary text-primary',
                            )}
                        >
                            <Icon className="size-4" aria-hidden="true" />
                            <span>{item.title}</span>
                        </Link>
                    );
                })}
            </nav>

            <section>{children}</section>
        </div>
    );
}

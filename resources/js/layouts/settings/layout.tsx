import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useI18n } from '@/i18n/i18n-context';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import categories from '@/routes/categories';
import oracleTenants from '@/routes/oracle-tenants';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { component, props } = usePage();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useI18n();
    const isWideSettingsPage =
        component.startsWith('oracle-tenants/') ||
        component === 'settings/categories';
    const sidebarNavItems: NavItem[] = [
        { title: t('settings.profile'), href: edit(), icon: null },
        { title: t('settings.security'), href: editSecurity(), icon: null },
        { title: t('settings.appearance'), href: editAppearance(), icon: null },
        {
            title: t('settings.connections'),
            href: oracleTenants.index(),
            icon: null,
        },
        ...(props.auth.user.is_super_admin
            ? [
                  {
                      title: t('settings.taxonomy'),
                      href: categories.index(),
                      icon: null,
                  },
              ]
            : []),
    ];

    return (
        <div className="px-4 py-6">
            <Heading
                title={t('settings.title')}
                description={t('settings.description')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div
                    className={cn(
                        'min-w-0 flex-1',
                        !isWideSettingsPage && 'md:max-w-2xl',
                    )}
                >
                    <section
                        className={cn(
                            'space-y-12',
                            !isWideSettingsPage && 'max-w-xl',
                        )}
                    >
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

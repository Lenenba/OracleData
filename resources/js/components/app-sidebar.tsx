import { Link } from '@inertiajs/react';
import {
    Database,
    FileSliders,
    LayoutGrid,
    Server,
    Share2,
    UsersRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useI18n } from '@/i18n/i18n-context';
import { dashboard } from '@/routes';
import groupRoutes from '@/routes/groups';
import oracleTenants from '@/routes/oracle-tenants';
import queries from '@/routes/queries';
import queryTemplates from '@/routes/query-templates';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { t } = useI18n();
    const mainNavItems: NavItem[] = [
        { title: t('nav.dashboard'), href: dashboard(), icon: LayoutGrid },
        { title: t('nav.queries'), href: queries.index(), icon: Database },
        {
            title: t('nav.queryTemplates'),
            href: queryTemplates.index(),
            icon: FileSliders,
        },
        { title: t('nav.sharedQueries'), href: queries.shared(), icon: Share2 },
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
    ];

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

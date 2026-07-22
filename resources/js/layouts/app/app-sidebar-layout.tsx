import { useEffect, useState } from 'react';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

const STORAGE_KEY = 'paces-sidebar-collapsed';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [collapsed, setCollapsed] = useState(
        () =>
            typeof window !== 'undefined' &&
            localStorage.getItem(STORAGE_KEY) === 'true',
    );
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        document.body.style.overflow = mobileOpen ? 'hidden' : '';

        return () => {
            document.body.style.overflow = '';
        };
    }, [mobileOpen]);

    function toggleNavigation() {
        if (window.matchMedia('(max-width: 1023px)').matches) {
            setMobileOpen((value) => !value);

            return;
        }

        setCollapsed((value) => {
            const next = !value;
            localStorage.setItem(STORAGE_KEY, String(next));

            return next;
        });
    }

    return (
        <div
            className={cn(
                'paces-wrapper',
                collapsed && 'is-sidebar-collapsed',
                mobileOpen && 'is-sidebar-mobile-open',
            )}
        >
            <AppSidebar
                collapsed={collapsed}
                mobileOpen={mobileOpen}
                onNavigate={() => setMobileOpen(false)}
            />

            {mobileOpen && (
                <button
                    type="button"
                    className="paces-sidebar-backdrop"
                    onClick={() => setMobileOpen(false)}
                    aria-label="Close navigation"
                />
            )}

            <AppSidebarHeader
                breadcrumbs={breadcrumbs}
                collapsed={collapsed}
                onToggle={toggleNavigation}
            />

            <div className="paces-page-content">
                <main>
                    <div className="paces-container">{children}</div>
                </main>
                <footer className="paces-footer">
                    <span>© {new Date().getFullYear()} OracleData</span>
                    <span>Oracle intelligence workspace</span>
                </footer>
            </div>
        </div>
    );
}

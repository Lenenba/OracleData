import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import notificationRoutes from '@/routes/notifications';

export function NotificationBell() {
    const { t } = useI18n();
    const { notificationSummary } = usePage().props;
    const unreadCount = notificationSummary?.unread_count ?? 0;
    const visibleCount = unreadCount > 99 ? '99+' : String(unreadCount);

    return (
        <Button asChild variant="ghost" size="icon" className="relative">
            <Link
                href={notificationRoutes.index()}
                aria-label={
                    unreadCount > 0
                        ? t('notifications.openWithUnread', {
                              count: unreadCount,
                          })
                        : t('notifications.open')
                }
                prefetch
            >
                <Bell />
                {unreadCount > 0 && (
                    <span
                        className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-5 font-semibold text-white"
                        aria-hidden="true"
                    >
                        {visibleCount}
                    </span>
                )}
            </Link>
        </Button>
    );
}

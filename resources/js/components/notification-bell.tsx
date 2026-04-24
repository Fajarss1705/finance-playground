import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { index as fetchNotifications, go } from '@/routes/notifications';
import type { Auth, NotificationGroup, NotificationItem } from '@/types';

export function NotificationBell() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [unreadCount, setUnreadCount] = useState(auth.unreadNotificationsCount ?? 0);

    // Poll unread count via plain fetch — avoids Inertia visits that clear form errors
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    useEffect(() => {
        setUnreadCount(auth.unreadNotificationsCount ?? 0);
    }, [auth.unreadNotificationsCount]);

    useEffect(() => {
        intervalRef.current = setInterval(async () => {
            try {
                const res = await fetch(fetchNotifications.url(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    const data = await res.json();
                    const count = (data.currentRole?.unreadCount ?? 0) + (data.allRoles?.unreadCount ?? 0);
                    setUnreadCount(count);
                }
            } catch {
                // silently ignore network errors
            }
        }, 15000);
        return () => {
            if (intervalRef.current) clearInterval(intervalRef.current);
        };
    }, []);

    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [currentRole, setCurrentRole] = useState<NotificationGroup | null>(null);
    const [allRoles, setAllRoles] = useState<NotificationGroup | null>(null);

    const loadNotifications = useCallback(async () => {
        setLoading(true);
        try {
            const response = await fetch(fetchNotifications.url(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            setCurrentRole(data.currentRole);
            setAllRoles(data.allRoles);
            setUnreadCount((data.currentRole?.unreadCount ?? 0) + (data.allRoles?.unreadCount ?? 0));
        } finally {
            setLoading(false);
        }
    }, []);

    const handleOpenChange = (isOpen: boolean) => {
        setOpen(isOpen);
        if (isOpen) {
            loadNotifications();
        }
    };

    const handleClick = (notification: NotificationItem) => {
        setOpen(false);
        router.get(go.url(notification.id));
    };

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <button className="relative flex size-12 items-center justify-center rounded-full bg-blue-600 text-white shadow-[0_4px_20px_rgba(59,130,246,0.4)] transition-all hover:bg-blue-700 hover:shadow-[0_6px_28px_rgba(59,130,246,0.5)] active:scale-95 dark:bg-blue-500 dark:hover:bg-blue-600" aria-label="Notifikasi">
                    {unreadCount > 0 && (
                        <span className="absolute inset-0 animate-ping rounded-full bg-blue-400 opacity-30" />
                    )}
                    <Bell className="relative size-5" />
                    {unreadCount > 0 && (
                        <>
                            <span className="absolute -top-1 -right-1 size-5 animate-ping rounded-full bg-red-500 opacity-50" />
                            <span className="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white ring-2 ring-white dark:ring-gray-900">
                                {unreadCount > 99 ? '99+' : unreadCount}
                            </span>
                        </>
                    )}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-96 p-0" align="end">
                {loading ? (
                    <div className="flex items-center justify-center p-8">
                        <div className="size-5 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
                    </div>
                ) : (
                    <div className="max-h-[28rem] overflow-y-auto">
                        <NotificationSection
                            title="Role Aktif"
                            group={currentRole}
                            onClickItem={handleClick}
                        />
                        <Separator />
                        <NotificationSection
                            title="Semua Role"
                            group={allRoles}
                            onClickItem={handleClick}
                        />
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

function NotificationSection({
    title,
    group,
    onClickItem,
}: {
    title: string;
    group: NotificationGroup | null;
    onClickItem: (item: NotificationItem) => void;
}) {
    const items = group?.items ?? [];
    const unreadCount = group?.unreadCount ?? 0;

    return (
        <div>
            <div className="px-4 py-2">
                <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    {title}
                    {unreadCount > 0 && (
                        <span className="ml-1.5 rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white normal-case">
                            {unreadCount}
                        </span>
                    )}
                </h3>
            </div>
            {items.length === 0 ? (
                <p className="px-4 pb-3 text-sm text-muted-foreground">Tidak ada notifikasi</p>
            ) : (
                <div>
                    {items.map((item) => (
                        <button
                            key={item.id}
                            onClick={() => onClickItem(item)}
                            className="flex w-full gap-3 px-4 py-2.5 text-left hover:bg-accent transition-colors"
                        >
                            <div className="mt-1.5 shrink-0">
                                {!item.is_read && (
                                    <span className="block size-2 rounded-full bg-primary" />
                                )}
                                {item.is_read && <span className="block size-2" />}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-baseline justify-between gap-2">
                                    <p className={`truncate text-sm ${!item.is_read ? 'font-semibold' : ''}`}>
                                        {item.subject}
                                    </p>
                                    <span className="shrink-0 text-[11px] text-muted-foreground">
                                        {formatRelativeTime(item.created_at)}
                                    </span>
                                </div>
                                <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                    {item.body}
                                </p>
                                <p className="mt-1 text-[11px] text-muted-foreground">
                                    {item.role.name} &middot; {item.role.team.name}
                                </p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function formatRelativeTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);

    if (diffMin < 1) return 'baru saja';
    if (diffMin < 60) return `${diffMin}m`;
    if (diffHour < 24) return `${diffHour}j`;
    if (diffDay < 7) return `${diffDay}h`;
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', timeZone: 'Asia/Jakarta' });
}

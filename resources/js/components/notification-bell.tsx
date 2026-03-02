import { useCallback, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { index as fetchNotifications, go, markRead } from '@/routes/notifications';
import type { Auth, NotificationGroup, NotificationItem } from '@/types';

export function NotificationBell() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const unreadCount = auth.unreadNotificationsCount ?? 0;

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

    const handleMarkAllRead = (items: NotificationItem[]) => {
        const unread = items.filter((n) => !n.is_read);
        unread.forEach((n) => {
            fetch(markRead.url(n.id), {
                method: 'PATCH',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
            });
        });

        // Optimistically update local state
        if (currentRole) {
            setCurrentRole({
                items: currentRole.items.map((n) => ({ ...n, is_read: true })),
                unreadCount: 0,
            });
        }
        if (allRoles) {
            setAllRoles({
                items: allRoles.items.map((n) => ({ ...n, is_read: true })),
                unreadCount: 0,
            });
        }
    };

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <button className="relative rounded-md p-2 hover:bg-accent transition-colors" aria-label="Notifikasi">
                    <Bell className="size-5" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex size-5 items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
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
                            onMarkAllRead={handleMarkAllRead}
                        />
                        <Separator />
                        <NotificationSection
                            title="Semua Role"
                            group={allRoles}
                            onClickItem={handleClick}
                            onMarkAllRead={handleMarkAllRead}
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
    onMarkAllRead,
}: {
    title: string;
    group: NotificationGroup | null;
    onClickItem: (item: NotificationItem) => void;
    onMarkAllRead: (items: NotificationItem[]) => void;
}) {
    const items = group?.items ?? [];
    const unreadCount = group?.unreadCount ?? 0;

    return (
        <div>
            <div className="flex items-center justify-between px-4 py-2">
                <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    {title}
                    {unreadCount > 0 && (
                        <span className="ml-1.5 rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold text-destructive-foreground normal-case">
                            {unreadCount}
                        </span>
                    )}
                </h3>
                {unreadCount > 0 && (
                    <button
                        onClick={() => onMarkAllRead(items)}
                        className="text-xs text-primary hover:underline"
                    >
                        Tandai dibaca
                    </button>
                )}
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
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
}

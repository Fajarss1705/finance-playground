import { Form, Head, router, usePage } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as adminNotificationsIndex, markAllRead } from '@/routes/admin/notifications';
import { go } from '@/routes/notifications';
import type { Auth, BreadcrumbItem } from '@/types';

type AdminNotificationRow = {
    id: number;
    subject: string;
    body: string;
    link: string | null;
    is_read: boolean;
    user: { id: number; name: string };
    role: { id: number; name: string; team: { name: string } };
    created_at: string;
};

type PaginatedNotifications = {
    data: AdminNotificationRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    notifications: PaginatedNotifications;
    filters: {
        search: string | null;
        status: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Semua Notifikasi', href: adminNotificationsIndex() },
];

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function AdminNotificationsIndex({ notifications, filters }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const currentUserId = auth.user.id;

    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? 'all');

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        applyFilters(search, status);
    }

    function handleStatusChange(value: string) {
        setStatus(value);
        applyFilters(search, value);
    }

    function applyFilters(searchVal: string, statusVal: string) {
        const params: Record<string, string> = {};
        if (searchVal) params.search = searchVal;
        if (statusVal && statusVal !== 'all') params.status = statusVal;
        router.get(adminNotificationsIndex().url, params, { preserveState: true });
    }

    const hasOwnUnread = notifications.data.some((n) => n.user.id === currentUserId && !n.is_read);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Semua Notifikasi" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title="Semua Notifikasi" description="Semua notifikasi di workspace ini" />
                    {hasOwnUnread && (
                        <Form {...markAllRead.form()} options={{ preserveScroll: true }}>
                            {({ processing }) => (
                                <Button type="submit" variant="outline" size="sm" disabled={processing}>
                                    Tandai Notifikasi Saya Dibaca
                                </Button>
                            )}
                        </Form>
                    )}
                </div>

                <div className="flex items-center gap-4">
                    <form onSubmit={handleSearch} className="flex items-center gap-2">
                        <Input
                            type="search"
                            placeholder="Cari notifikasi..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-64"
                        />
                        <Button type="submit" variant="outline" size="sm">
                            Cari
                        </Button>
                    </form>
                    <Select value={status} onValueChange={handleStatusChange}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua</SelectItem>
                            <SelectItem value="unread">Belum Dibaca</SelectItem>
                            <SelectItem value="read">Sudah Dibaca</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="w-8 px-4 py-3" />
                                <th className="px-4 py-3 text-left font-medium">Subjek</th>
                                <th className="px-4 py-3 text-left font-medium">Pengguna</th>
                                <th className="px-4 py-3 text-left font-medium">Role</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {notifications.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                        <BellOff className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Tidak ada notifikasi.
                                    </td>
                                </tr>
                            ) : (
                                notifications.data.map((notification) => {
                                    const isOwn = notification.user.id === currentUserId;
                                    return (
                                        <tr
                                            key={notification.id}
                                            className={`border-b last:border-0 ${isOwn ? 'cursor-pointer hover:bg-muted/30' : ''}`}
                                            onClick={isOwn ? () => router.get(go.url(notification.id)) : undefined}
                                        >
                                            <td className="px-4 py-3 text-center">
                                                {!notification.is_read && (
                                                    <span className="inline-block h-2 w-2 rounded-full bg-blue-500" />
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className={notification.is_read ? 'text-muted-foreground' : 'font-medium'}>
                                                    {notification.subject}
                                                </div>
                                                <div className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                    {notification.body}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{notification.user.name}</td>
                                            <td className="px-4 py-3">
                                                <Badge variant="secondary">
                                                    {notification.role.name} &middot; {notification.role.team.name}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                                {formatDate(notification.created_at)}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={notifications.links}
                    currentPage={notifications.current_page}
                    lastPage={notifications.last_page}
                    total={notifications.total}
                />
            </div>
        </AppLayout>
    );
}

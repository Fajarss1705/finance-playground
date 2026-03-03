import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as personalIndex, notifications as personalNotifications } from '@/routes/personal';
import type { BreadcrumbItem } from '@/types';

type NotificationDetail = {
    id: number;
    subject: string;
    body: string;
    email_html: string | null;
    link: string | null;
    is_read: boolean;
    role: { id: number; name: string; team: { name: string } };
    created_at: string;
};

type Props = {
    notification: NotificationDetail;
};

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function NotificationShow({ notification }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Personal', href: personalIndex() },
        { title: 'Notifikasi Saya', href: personalNotifications() },
        { title: notification.subject },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={notification.subject} />
            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <Heading title={notification.subject} description={`${notification.role.name} · ${notification.role.team.name}`} />

                <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <span>{formatDate(notification.created_at)}</span>
                    <Badge variant={notification.is_read ? 'secondary' : 'default'}>
                        {notification.is_read ? 'Sudah Dibaca' : 'Belum Dibaca'}
                    </Badge>
                    <Badge variant="outline">
                        {notification.role.name} &middot; {notification.role.team.name}
                    </Badge>
                </div>

                <div className="rounded-lg border p-4">
                    <p className="whitespace-pre-line text-sm">{notification.body}</p>
                </div>

                {notification.email_html && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">Preview Email</h3>
                        <div className="overflow-hidden rounded-lg border">
                            <iframe
                                srcDoc={notification.email_html}
                                sandbox=""
                                className="w-full"
                                style={{ minHeight: '400px' }}
                                title="Email preview"
                                onLoad={(e) => {
                                    const iframe = e.target as HTMLIFrameElement;
                                    if (iframe.contentDocument) {
                                        iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                                    }
                                }}
                            />
                        </div>
                    </div>
                )}

                <div className="flex gap-3">
                    <Button variant="outline" asChild>
                        <Link href={personalNotifications().url}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                    {notification.link && (
                        <Button asChild>
                            <a href={notification.link} target="_blank" rel="noopener noreferrer">
                                Buka Link
                                <ExternalLink className="ml-2 h-4 w-4" />
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

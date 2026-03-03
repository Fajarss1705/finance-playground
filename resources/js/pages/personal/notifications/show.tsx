import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
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
                <Heading title={notification.subject} />

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

                <div className="space-y-1 text-sm">
                    <p className="text-muted-foreground">
                        <span className="font-medium text-foreground">Tanggal:</span> {formatDate(notification.created_at)}
                    </p>
                    <p className="text-muted-foreground">
                        <span className="font-medium text-foreground">Status:</span>{' '}
                        <span className={notification.is_read ? 'font-medium text-green-600 dark:text-green-400' : 'font-medium text-red-600 dark:text-red-400'}>
                            {notification.is_read ? 'Sudah Dibaca' : 'Belum Dibaca'}
                        </span>
                    </p>
                    <p className="text-muted-foreground">
                        <span className="font-medium text-foreground">Role:</span> {notification.role.name}
                    </p>
                    <p className="text-muted-foreground">
                        <span className="font-medium text-foreground">Tim:</span> {notification.role.team.name}
                    </p>
                </div>

                <div className="rounded-lg border p-4">
                    <p className="whitespace-pre-line text-sm">{notification.body}</p>
                </div>

                {notification.email_html && (
                    <>
                        <Separator />
                        <div className="space-y-2">
                            <div className="flex items-baseline justify-between">
                                <h3 className="text-sm font-medium">Preview Email</h3>
                                <p className="text-xs text-muted-foreground">Link di dalam preview tidak aktif</p>
                            </div>
                            <div className="overflow-hidden rounded-lg border">
                                <iframe
                                    srcDoc={notification.email_html}
                                    sandbox=""
                                    className="w-full"
                                    style={{ minHeight: '80vh' }}
                                    title="Email preview"
                                    onLoad={(e) => {
                                        const iframe = e.target as HTMLIFrameElement;
                                        if (iframe.contentDocument) {
                                            const contentHeight = iframe.contentDocument.body.scrollHeight;
                                            iframe.style.height = Math.max(contentHeight, window.innerHeight * 0.8) + 'px';
                                        }
                                    }}
                                />
                            </div>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

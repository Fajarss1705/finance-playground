import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { index as personalIndex } from '@/routes/personal';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Personal', href: personalIndex() },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Personal" />
            <div className="space-y-6 p-6">
                <Heading title="Personal" description="Dashboard personal" />
            </div>
        </AppLayout>
    );
}

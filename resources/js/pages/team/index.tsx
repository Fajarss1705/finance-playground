import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { index as teamIndex } from '@/routes/team';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tim', href: teamIndex() },
];

export default function TeamDashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tim" />
            <div className="space-y-6 p-6">
                <Heading title="Tim" description="Dashboard tim" />
            </div>
        </AppLayout>
    );
}

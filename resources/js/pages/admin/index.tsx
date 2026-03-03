import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
];

export default function AdminDashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manajemen" />
            <div className="space-y-6 p-6">
                <Heading title="Manajemen" description="Dashboard manajemen aplikasi" />
            </div>
        </AppLayout>
    );
}

import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Props = {
    workflow: { id: number; label: string; status: string };
    scope: 'team' | 'admin';
};

export default function PkShow({ workflow, scope }: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Perencanaan Kegiatan', href: `${scopeBase}/workflows/pk` },
        { title: workflow.label, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workflow.label} />
            <div className="p-6">
                <Heading title={workflow.label} description="Detail workflow PK" />
                <p className="mt-4 text-sm text-muted-foreground">Halaman ini akan diimplementasikan pada tahap berikutnya.</p>
            </div>
        </AppLayout>
    );
}

import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Props = {
    scope: 'team' | 'admin';
};

export default function PkIndex({ scope }: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Perencanaan Kegiatan', href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Perencanaan Kegiatan" />
            <div className="p-6">
                <Heading title="Perencanaan Kegiatan" description="Daftar workflow PK (Perencanaan Kegiatan dan Anggaran)" />
                <p className="mt-4 text-sm text-muted-foreground">Halaman ini akan diimplementasikan pada tahap berikutnya.</p>
            </div>
        </AppLayout>
    );
}

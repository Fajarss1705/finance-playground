import { Form, Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import OrganizationController from '@/actions/App/Http/Controllers/Admin/OrganizationController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeShort } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as organizationsIndex } from '@/routes/admin/organizations';
import type { BreadcrumbItem } from '@/types';

type OrganizationRow = {
    id: number;
    name: string;
    description: string | null;
    deleted_at: string;
};

type PaginatedOrganizations = {
    data: OrganizationRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    organizations: PaginatedOrganizations;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Organisasi', href: organizationsIndex() },
    { title: 'Sampah', href: OrganizationController.trash() },
];

export default function OrganizationsTrash({ organizations }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sampah Organisasi" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Sampah Organisasi"
                        description="Data organisasi yang telah dihapus"
                    />
                    <Button variant="outline" asChild>
                        <Link href={organizationsIndex().url} prefetch>
                            Kembali
                        </Link>
                    </Button>
                </div>

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">
                                    Nama
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Deskripsi
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Dihapus pada
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {organizations.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Building2 className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Tidak ada data di sampah.
                                    </td>
                                </tr>
                            ) : (
                                organizations.data.map((org) => (
                                    <tr
                                        key={org.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {org.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {org.description || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDateTimeShort(org.deleted_at)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Can permission="admin.organizations.restore">
                                                <Form
                                                    {...OrganizationController.restore.form(
                                                        org.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                            asChild
                                                        >
                                                            <button type="submit">
                                                                Pulihkan
                                                            </button>
                                                        </Button>
                                                    )}
                                                </Form>
                                            </Can>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={organizations.links}
                    currentPage={organizations.current_page}
                    lastPage={organizations.last_page}
                    total={organizations.total}
                />
            </div>
        </AppLayout>
    );
}

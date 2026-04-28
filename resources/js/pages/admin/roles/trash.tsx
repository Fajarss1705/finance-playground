import { Form, Head, Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeShort } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { BreadcrumbItem } from '@/types';

type RoleRow = {
    id: number;
    name: string;
    team: { name: string } | null;
    deleted_at: string;
};

type PaginatedRoles = {
    data: RoleRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    roles: PaginatedRoles;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Role', href: rolesIndex() },
    { title: 'Sampah', href: RoleController.trash() },
];

export default function RolesTrash({ roles }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sampah Role" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Sampah Role"
                        description="Data role yang telah dihapus"
                    />
                    <Button variant="outline" asChild>
                        <Link href={rolesIndex().url} prefetch>
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
                                    Tim
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
                            {roles.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Shield className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Tidak ada data di sampah.
                                    </td>
                                </tr>
                            ) : (
                                roles.data.map((role) => (
                                    <tr
                                        key={role.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {role.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {role.team?.name || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDateTimeShort(role.deleted_at)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Can permission="admin.roles.restore">
                                                <Form
                                                    {...RoleController.restore.form(
                                                        role.id,
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
                    links={roles.links}
                    currentPage={roles.current_page}
                    lastPage={roles.last_page}
                    total={roles.total}
                />
            </div>
        </AppLayout>
    );
}

import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import OrganizationController from '@/actions/App/Http/Controllers/Admin/OrganizationController';
import AlertError from '@/components/alert-error';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index as organizationsIndex } from '@/routes/admin/organizations';
import type { BreadcrumbItem } from '@/types';

type Organization = {
    id: number;
    name: string;
    description: string | null;
    teams_count: number;
};

type PaginatedOrganizations = {
    data: Organization[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    organizations: PaginatedOrganizations;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Organisasi', href: organizationsIndex() },
];

export default function OrganizationsIndex({ organizations }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organisasi" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Organisasi"
                        description="Kelola data organisasi"
                    />
                    <Can permission="admin.organizations.create">
                        <Button asChild>
                            <Link
                                href={OrganizationController.create().url}
                                prefetch
                            >
                                Tambah Organisasi
                            </Link>
                        </Button>
                    </Can>
                </div>

                {errors.delete && (
                    <AlertError
                        errors={[errors.delete]}
                        title="Gagal menghapus"
                    />
                )}

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
                                <th className="px-4 py-3 text-center font-medium">
                                    Jumlah Tim
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
                                        Belum ada organisasi.
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
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="secondary">
                                                {org.teams_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Can permission="admin.organizations.edit">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={OrganizationController.edit(
                                                                org.id,
                                                            ).url}
                                                            prefetch
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </Can>
                                                <Can permission="admin.organizations.destroy">
                                                    <DeleteOrganizationDialog
                                                        organization={org}
                                                    />
                                                </Can>
                                            </div>
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

function DeleteOrganizationDialog({
    organization,
}: {
    organization: Organization;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus Organisasi</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus organisasi &quot;
                    {organization.name}&quot;? Tindakan ini tidak dapat
                    dibatalkan.
                </DialogDescription>
                <Form
                    {...OrganizationController.destroy.form(organization.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Batal</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                asChild
                            >
                                <button type="submit">Hapus</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

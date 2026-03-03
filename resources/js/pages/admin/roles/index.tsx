import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { BreadcrumbItem } from '@/types';

type Team = {
    id: number;
    name: string;
};

type Role = {
    id: number;
    name: string;
    description: string | null;
    permissions_count: number;
    team: Team;
};

type PaginatedRoles = {
    data: Role[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    roles: PaginatedRoles;
    teams: Team[];
    filters: {
        team_id: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Role', href: rolesIndex() },
];

export default function RolesIndex({ roles, teams, filters }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    function handleTeamFilter(value: string) {
        const params = value === 'all' ? {} : { team_id: value };
        router.get(rolesIndex().url, params, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Role" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title="Role" description="Kelola data role" />
                    <Can permission="admin.roles.create">
                        <Button asChild>
                            <Link
                                href={RoleController.create().url}
                                prefetch
                            >
                                Tambah Role
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

                <div className="flex items-center gap-4">
                    <Select
                        value={filters.team_id ?? 'all'}
                        onValueChange={handleTeamFilter}
                    >
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="Filter tim" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Tim</SelectItem>
                            {teams.map((team) => (
                                <SelectItem
                                    key={team.id}
                                    value={team.id.toString()}
                                >
                                    {team.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
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
                                    Deskripsi
                                </th>
                                <th className="px-4 py-3 text-center font-medium">
                                    Jumlah Permission
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
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Shield className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada role.
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
                                            {role.team.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {role.description || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="secondary">
                                                {role.permissions_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Can permission="admin.roles.edit">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={RoleController.edit(
                                                                role.id,
                                                            ).url}
                                                            prefetch
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </Can>
                                                <Can permission="admin.roles.destroy">
                                                    <DeleteRoleDialog
                                                        role={role}
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
                    links={roles.links}
                    currentPage={roles.current_page}
                    lastPage={roles.last_page}
                    total={roles.total}
                />
            </div>
        </AppLayout>
    );
}

function DeleteRoleDialog({ role }: { role: Role }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus Role</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus role &quot;{role.name}
                    &quot;? Tindakan ini tidak dapat dibatalkan.
                </DialogDescription>
                <Form
                    {...RoleController.destroy.form(role.id)}
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

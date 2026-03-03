import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { UserCog } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
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
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import type { BreadcrumbItem } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    jabatan: string | null;
    roles_count: number;
};

type PaginatedUsers = {
    data: UserRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    users: PaginatedUsers;
    filters: {
        search: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Pengguna', href: usersIndex() },
];

export default function UsersIndex({ users, filters }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const [search, setSearch] = useState(filters.search ?? '');

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        const params = search ? { search } : {};
        router.get(usersIndex().url, params, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pengguna" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Pengguna"
                        description="Kelola data pengguna"
                    />
                    <Can permission="admin.users.create">
                        <Button asChild>
                            <Link
                                href={UserController.create().url}
                                prefetch
                            >
                                Tambah Pengguna
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

                <form
                    onSubmit={handleSearch}
                    className="flex items-center gap-4"
                >
                    <Input
                        type="search"
                        placeholder="Cari nama atau email..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-64"
                    />
                    <Button type="submit" variant="outline" size="sm">
                        Cari
                    </Button>
                </form>

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">
                                    Nama
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Email
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Jabatan
                                </th>
                                <th className="px-4 py-3 text-center font-medium">
                                    Jumlah Role
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <UserCog className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada pengguna.
                                    </td>
                                </tr>
                            ) : (
                                users.data.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {user.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {user.email}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {user.jabatan || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="secondary">
                                                {user.roles_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Can permission="admin.users.edit">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={UserController.edit(
                                                                user.id,
                                                            ).url}
                                                            prefetch
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </Can>
                                                <Can permission="admin.users.destroy">
                                                    <DeleteUserDialog
                                                        user={user}
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
                    links={users.links}
                    currentPage={users.current_page}
                    lastPage={users.last_page}
                    total={users.total}
                />
            </div>
        </AppLayout>
    );
}

function DeleteUserDialog({ user }: { user: UserRow }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus Pengguna</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus pengguna &quot;
                    {user.name}&quot;? Tindakan ini tidak dapat dibatalkan.
                </DialogDescription>
                <Form
                    {...UserController.destroy.form(user.id)}
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

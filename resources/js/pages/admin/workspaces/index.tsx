import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Layers, Trash2 } from 'lucide-react';
import WorkspaceController from '@/actions/App/Http/Controllers/Admin/WorkspaceController';
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
import { index as adminIndex } from '@/routes/admin';
import { index as workspacesIndex } from '@/routes/admin/workspaces';
import type { BreadcrumbItem } from '@/types';

type Workspace = {
    id: number;
    name: string;
    description: string | null;
    organizations_count: number;
};

type PaginatedWorkspaces = {
    data: Workspace[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    workspaces: PaginatedWorkspaces;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Workspace', href: workspacesIndex() },
];

export default function WorkspacesIndex({ workspaces }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Workspace" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Workspace"
                        description="Kelola data workspace"
                    />
                    <div className="flex gap-2">
                        <Can permission="admin.workspaces.trash">
                            <Button variant="outline" asChild>
                                <Link
                                    href={WorkspaceController.trash().url}
                                    prefetch
                                >
                                    <Trash2 />
                                    Sampah
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="admin.workspaces.create">
                            <Button asChild>
                                <Link
                                    href={WorkspaceController.create().url}
                                    prefetch
                                >
                                    Tambah Workspace
                                </Link>
                            </Button>
                        </Can>
                    </div>
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
                                    Jumlah Organisasi
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {workspaces.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Layers className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada workspace.
                                    </td>
                                </tr>
                            ) : (
                                workspaces.data.map((workspace) => (
                                    <tr
                                        key={workspace.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {workspace.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {workspace.description || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="secondary">
                                                {workspace.organizations_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Can permission="admin.workspaces.edit">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={WorkspaceController.edit(
                                                                workspace.id,
                                                            ).url}
                                                            prefetch
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </Can>
                                                <Can permission="admin.workspaces.destroy">
                                                    <DeleteWorkspaceDialog
                                                        workspace={workspace}
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
                    links={workspaces.links}
                    currentPage={workspaces.current_page}
                    lastPage={workspaces.last_page}
                    total={workspaces.total}
                />
            </div>
        </AppLayout>
    );
}

function DeleteWorkspaceDialog({ workspace }: { workspace: Workspace }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus Workspace</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus workspace &quot;
                    {workspace.name}&quot;? Data akan dipindahkan ke
                    sampah.
                </DialogDescription>
                <Form
                    {...WorkspaceController.destroy.form(workspace.id)}
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

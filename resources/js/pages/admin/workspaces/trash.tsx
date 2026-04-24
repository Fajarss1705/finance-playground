import { Form, Head, Link } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import WorkspaceController from '@/actions/App/Http/Controllers/Admin/WorkspaceController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeShort } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as workspacesIndex } from '@/routes/admin/workspaces';
import type { BreadcrumbItem } from '@/types';

type WorkspaceRow = {
    id: number;
    name: string;
    description: string | null;
    deleted_at: string;
};

type PaginatedWorkspaces = {
    data: WorkspaceRow[];
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
    { title: 'Sampah', href: WorkspaceController.trash() },
];

export default function WorkspacesTrash({ workspaces }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sampah Workspace" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Sampah Workspace"
                        description="Data workspace yang telah dihapus"
                    />
                    <Button variant="outline" asChild>
                        <Link href={workspacesIndex().url} prefetch>
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
                            {workspaces.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Layers className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Tidak ada data di sampah.
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
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {formatDateTimeShort(workspace.deleted_at)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Can permission="admin.workspaces.restore">
                                                <Form
                                                    {...WorkspaceController.restore.form(
                                                        workspace.id,
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
                    links={workspaces.links}
                    currentPage={workspaces.current_page}
                    lastPage={workspaces.last_page}
                    total={workspaces.total}
                />
            </div>
        </AppLayout>
    );
}

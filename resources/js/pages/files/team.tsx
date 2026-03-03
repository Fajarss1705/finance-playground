import { Head } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import FileController from '@/actions/App/Http/Controllers/FileController';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { team as filesTeam } from '@/routes/files';
import type { BreadcrumbItem } from '@/types';

type FileRow = {
    id: number;
    uuid: string;
    original_filename: string;
    mime_type: string;
    size: number;
    source_route: string | null;
    is_workspace_public: boolean;
    created_at: string;
    user: { id: number; name: string };
    role: { id: number; name: string } | null;
};

type PaginatedFiles = {
    data: FileRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    files: PaginatedFiles;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'File Tim', href: filesTeam() },
];

function formatFileSize(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    for (; size >= 1024 && i < units.length - 1; i++) {
        size /= 1024;
    }
    return `${size.toFixed(1)} ${units[i]}`;
}

export default function FilesTeam({ files }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="File Tim" />
            <div className="space-y-6 p-6">
                <Heading
                    title="File Tim"
                    description="File yang diunggah oleh anggota tim"
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-175 text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Nama File</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Pengunggah</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Role</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tipe</th>
                                <th className="px-4 py-3 text-right font-medium whitespace-nowrap">Ukuran</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Sumber</th>
                                <th className="px-4 py-3 text-right font-medium whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {files.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada file.
                                    </td>
                                </tr>
                            ) : (
                                files.data.map((file) => (
                                    <tr key={file.id} className="border-b last:border-0">
                                        <td className="px-4 py-3 font-medium">
                                            {file.original_filename}
                                            {file.is_workspace_public && (
                                                <Badge className="ml-2 bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Publik</Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{file.user.name}</td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{file.role?.name || '—'}</td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {new Date(file.created_at).toLocaleDateString('id-ID', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            })}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="secondary">{file.mime_type.split('/').pop()}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right text-muted-foreground whitespace-nowrap">{formatFileSize(file.size)}</td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{file.source_route || '—'}</td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <Button variant="outline" size="sm" asChild>
                                                <a href={FileController.download(file.uuid).url}>Unduh</a>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={files.links}
                    currentPage={files.current_page}
                    lastPage={files.last_page}
                    total={files.total}
                />
            </div>
        </AppLayout>
    );
}

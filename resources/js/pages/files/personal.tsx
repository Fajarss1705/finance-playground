import { Form, Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import FileController from '@/actions/App/Http/Controllers/FileController';
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
import { personal as filesPersonal } from '@/routes/files';
import type { BreadcrumbItem } from '@/types';

type FileRow = {
    id: number;
    uuid: string;
    original_filename: string;
    mime_type: string;
    size: number;
    source_route: string | null;
    created_at: string;
    user: { id: number; name: string };
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
    { title: 'File Saya', href: filesPersonal() },
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

export default function FilesPersonal({ files }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="File Saya" />
            <div className="space-y-6 p-6">
                <Heading
                    title="File Saya"
                    description="File yang Anda unggah"
                />

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">
                                    Nama File
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Tipe
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Ukuran
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Sumber
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Tanggal
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {files.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada file.
                                    </td>
                                </tr>
                            ) : (
                                files.data.map((file) => (
                                    <tr
                                        key={file.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {file.original_filename}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="secondary">
                                                {file.mime_type
                                                    .split('/')
                                                    .pop()}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right text-muted-foreground">
                                            {formatFileSize(file.size)}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {file.source_route || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {new Date(
                                                file.created_at,
                                            ).toLocaleDateString('id-ID', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            })}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={
                                                            FileController.download(
                                                                file.uuid,
                                                            ).url
                                                        }
                                                    >
                                                        Unduh
                                                    </Link>
                                                </Button>
                                                <DeleteFileDialog file={file} />
                                            </div>
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

function DeleteFileDialog({ file }: { file: FileRow }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus File</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus file &quot;
                    {file.original_filename}&quot;? Tindakan ini tidak dapat
                    dibatalkan.
                </DialogDescription>
                <Form
                    {...FileController.destroy.form(file.uuid)}
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

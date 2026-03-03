import { Head, Link, useForm } from '@inertiajs/react';
import { FileText, Upload } from 'lucide-react';
import FileController from '@/actions/App/Http/Controllers/FileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as adminFilesIndex } from '@/routes/admin/files';
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
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Semua File', href: adminFilesIndex() },
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

export default function AdminFilesIndex({ files }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Semua File" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Semua File"
                        description="Seluruh file dalam workspace"
                    />
                </div>

                <UploadForm />

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">
                                    Nama File
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Pengunggah
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
                                        colSpan={7}
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
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {file.user.name}
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

function UploadForm() {
    const { data, setData, post, processing, errors, reset } = useForm<{
        file: File | null;
    }>({
        file: null,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(FileController.upload().url, {
            forceFormData: true,
            onSuccess: () => {
                reset();
                // Reset the file input
                const input = document.getElementById(
                    'file-upload',
                ) as HTMLInputElement;
                if (input) {
                    input.value = '';
                }
            },
        });
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="flex items-end gap-4 rounded-lg border bg-muted/30 p-4"
        >
            <div className="grid w-full max-w-sm gap-1.5">
                <Label htmlFor="file-upload">Unggah File (maks. 10MB)</Label>
                <Input
                    id="file-upload"
                    type="file"
                    onChange={(e) =>
                        setData('file', e.target.files?.[0] ?? null)
                    }
                />
                {errors.file && <InputError message={errors.file} />}
            </div>
            <Button type="submit" disabled={processing || !data.file}>
                <Upload />
                Unggah
            </Button>
        </form>
    );
}

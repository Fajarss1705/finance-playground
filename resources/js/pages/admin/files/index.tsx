import { Form, Head, Link, useForm } from '@inertiajs/react';
import { FileText, Trash2, Upload } from 'lucide-react';
import AdminFileController from '@/actions/App/Http/Controllers/Admin/FileController';
import FileController from '@/actions/App/Http/Controllers/FileController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateShort } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as adminFilesIndex } from '@/routes/admin/files';
import type { BreadcrumbItem } from '@/types';

const ACCEPTED_TYPES = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.zip';

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
    team: { id: number; name: string } | null;
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
                    <Can permission="admin.files.trash">
                        <Button variant="outline" asChild>
                            <Link href={AdminFileController.trash().url} prefetch>
                                <Trash2 />
                                Sampah
                            </Link>
                        </Button>
                    </Can>
                </div>

                <UploadForm />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-225 text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Nama File</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Pengunggah</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Role</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tim</th>
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
                                    <td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">
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
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{file.team?.name || '—'}</td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {formatDateShort(file.created_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="secondary">{file.mime_type.split('/').pop()}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right text-muted-foreground whitespace-nowrap">{formatFileSize(file.size)}</td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{file.source_route || '—'}</td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            <div className="flex justify-end gap-2">
                                                <Button variant="outline" size="sm" asChild>
                                                    <a href={FileController.download(file.uuid).url}>Unduh</a>
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
                <Button variant="destructive" size="sm">Hapus</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus File</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus file &quot;{file.original_filename}&quot;?
                    Data akan dipindahkan ke sampah.
                </DialogDescription>
                <Form
                    {...AdminFileController.destroy.form(file.uuid)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Batal</Button>
                            </DialogClose>
                            <Button variant="destructive" disabled={processing} asChild>
                                <button type="submit">Hapus</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function UploadForm() {
    const { data, setData, post, processing, errors, reset } = useForm<{
        file: File | null;
        source_route: string;
        is_workspace_public: boolean;
    }>({
        file: null,
        source_route: 'admin.files.index',
        is_workspace_public: false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(AdminFileController.upload().url, {
            forceFormData: true,
            onSuccess: () => {
                reset();
                const input = document.getElementById('file-upload') as HTMLInputElement;
                if (input) {
                    input.value = '';
                }
            },
        });
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="space-y-3 rounded-lg border bg-muted/30 p-4"
        >
            <div className="grid gap-1.5">
                <Label>Pilih File</Label>
                <label
                    htmlFor="file-upload"
                    className="inline-flex h-9 w-full max-w-sm cursor-pointer items-center gap-2 truncate rounded-md border border-input bg-background px-3 text-sm shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className={data.file ? 'text-foreground' : 'text-muted-foreground'}>
                        {data.file ? data.file.name : 'Pilih file...'}
                    </span>
                </label>
                <input
                    id="file-upload"
                    type="file"
                    className="sr-only"
                    accept={ACCEPTED_TYPES}
                    onChange={(e) =>
                        setData('file', e.target.files?.[0] ?? null)
                    }
                />
                <p className="text-xs text-muted-foreground">Maks. 50MB — PDF, Word, Excel, PPT, JPG, PNG, ZIP</p>
                {errors.file && <InputError message={errors.file} />}
            </div>
            <div className="flex items-center gap-3">
                <label
                    htmlFor="is-public"
                    className="flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs select-none"
                >
                    <Checkbox
                        id="is-public"
                        checked={data.is_workspace_public}
                        onCheckedChange={(checked) =>
                            setData('is_workspace_public', checked === true)
                        }
                        className="border-foreground/25 data-[state=checked]:border-primary"
                    />
                    Publik (seluruh workspace)
                </label>
                <Button type="submit" disabled={processing || !data.file}>
                    <Upload />
                    Unggah
                </Button>
            </div>
        </form>
    );
}
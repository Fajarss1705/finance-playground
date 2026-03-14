import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle, AlertTriangle, XCircle, Search } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Personal', href: '/personal' },
    { title: 'Verifikasi Dokumen', href: '/personal/verify' },
];

type VerifyResult = {
    status: 'valid' | 'tampered' | 'not_found';
    message: string;
    document_type?: string;
    label?: string;
    tahun?: number;
    revision?: number;
    compiled_at?: string;
    organization?: string;
    total_plafon?: number;
    kuisioner_count?: number;
    dokumen_count?: number;
    kode_count?: number;
};

function formatRupiah(value: number): string {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Jakarta',
    }) + ' WIB';
}

export default function Verify() {
    const [code, setCode] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<VerifyResult | null>(null);

    async function handleVerify() {
        const trimmed = code.trim();
        if (!trimmed) return;

        setLoading(true);
        setResult(null);

        try {
            const response = await fetch(`/verify/${encodeURIComponent(trimmed)}`);
            const data = await response.json();
            setResult(data);
        } catch {
            setResult({ status: 'not_found', message: 'Gagal menghubungi server.' });
        } finally {
            setLoading(false);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Verifikasi Dokumen" />
            <div className="space-y-6 p-6">
                <Heading title="Verifikasi Dokumen" description="Periksa keaslian dokumen dengan kode verifikasi" />

                <div className="max-w-lg rounded-lg border p-6">
                    <p className="mb-4 text-sm text-muted-foreground">
                        Masukkan kode verifikasi 8 karakter yang tertera pada dokumen untuk memastikan keaslian dan integritas datanya.
                    </p>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={code}
                            onChange={(e) => {
                                setCode(e.target.value.toUpperCase());
                                setResult(null);
                            }}
                            onKeyDown={(e) => e.key === 'Enter' && handleVerify()}
                            placeholder="Masukkan kode verifikasi"
                            maxLength={8}
                            className="flex-1 rounded-md border border-input bg-background px-3 py-2 font-mono text-sm tracking-wider uppercase placeholder:normal-case placeholder:tracking-normal placeholder:font-sans focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                        <Button onClick={handleVerify} disabled={!code.trim() || loading}>
                            <Search className="mr-1.5 h-4 w-4" />
                            {loading ? 'Memeriksa...' : 'Verifikasi'}
                        </Button>
                    </div>
                </div>

                {result && <VerifyResultCard result={result} />}
            </div>
        </AppLayout>
    );
}

function VerifyResultCard({ result }: { result: VerifyResult }) {
    if (result.status === 'not_found') {
        return (
            <div className="max-w-lg rounded-lg border border-red-200 bg-red-50 p-6 dark:border-red-800 dark:bg-red-950">
                <div className="flex items-start gap-3">
                    <XCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                    <div>
                        <p className="font-medium text-red-800 dark:text-red-300">{result.message}</p>
                        <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                            Pastikan kode yang Anda masukkan sesuai dengan yang tertera pada dokumen.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    if (result.status === 'tampered') {
        return (
            <div className="max-w-lg rounded-lg border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-950">
                <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                    <div>
                        <p className="font-medium text-amber-800 dark:text-amber-300">{result.message}</p>
                        <p className="mt-1 text-sm text-amber-600 dark:text-amber-400">
                            Data di sistem telah berubah sejak dokumen ini dikompilasi. Dokumen ini mungkin sudah tidak akurat.
                        </p>
                        <DataSummary result={result} />
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="max-w-lg rounded-lg border border-green-200 bg-green-50 p-6 dark:border-green-800 dark:bg-green-950">
            <div className="flex items-start gap-3">
                <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-500" />
                <div>
                    <p className="font-medium text-green-800 dark:text-green-300">{result.message}</p>
                    <p className="mt-1 text-sm text-green-600 dark:text-green-400">
                        Bandingkan data di bawah dengan dokumen yang Anda miliki untuk memastikan kecocokan.
                    </p>
                    <DataSummary result={result} />
                </div>
            </div>
        </div>
    );
}

function DataSummary({ result }: { result: VerifyResult }) {
    return (
        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
            <dt className="text-muted-foreground">Dokumen</dt>
            <dd className="font-medium">{result.document_type}</dd>

            <dt className="text-muted-foreground">Label</dt>
            <dd className="font-medium">{result.label}</dd>

            <dt className="text-muted-foreground">Tahun</dt>
            <dd className="font-medium">{result.tahun}</dd>

            <dt className="text-muted-foreground">Revisi</dt>
            <dd className="font-medium">{result.revision}</dd>

            <dt className="text-muted-foreground">Organisasi</dt>
            <dd className="font-medium">{result.organization ?? '-'}</dd>

            <dt className="text-muted-foreground">Total Plafon</dt>
            <dd className="font-medium">{result.total_plafon !== undefined ? formatRupiah(result.total_plafon) : '-'}</dd>

            <dt className="text-muted-foreground">Kuisioner</dt>
            <dd className="font-medium">{result.kuisioner_count ?? 0} pertanyaan</dd>

            <dt className="text-muted-foreground">Kode Referensi</dt>
            <dd className="font-medium">{result.kode_count ?? 0} kode</dd>

            <dt className="text-muted-foreground">Dokumen SOP</dt>
            <dd className="font-medium">{result.dokumen_count ?? 0} file</dd>

            <dt className="text-muted-foreground">Dikompilasi</dt>
            <dd className="font-medium">{result.compiled_at ? formatDate(result.compiled_at) : '-'}</dd>
        </dl>
    );
}

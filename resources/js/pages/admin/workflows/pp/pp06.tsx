import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Pp06 = {
    id: number;
    revision: number;
    tahun: number;
    tanggal_mulai_pra_raker: string;
    tanggal_penetapan_program: string;
    pp01_created_by_user_name: string;
    pp01_created_by_role_name: string;
    pp01_created_at: string;
    pp02_created_by_user_name: string;
    pp02_created_by_role_name: string;
    pp02_created_at: string;
    pp03_created_by_user_name: string;
    pp03_created_by_role_name: string;
    pp03_created_at: string;
    pp04_created_by_user_name: string;
    pp04_created_by_role_name: string;
    pp04_created_at: string;
    pp05_created_by_user_name: string;
    pp05_created_by_role_name: string;
    pp05_created_at: string;
    item_plafon_anggaran: { team: { name: string }; kode_team: string; plafon_anggaran: number; nama_bank: string; nomor_rekening: string }[];
    kode_bidang_pelayanan: { kode: string; nama: string }[];
    kode_sub_bidang_pelayanan: { kode: string; nama: string }[];
    kode_kategori_pelayanan: { kode: string; nama: string }[];
    kode_jenis_program: { kode: string; nama: string }[];
    item_kuisioner: { kode: string; pertanyaan: string; tipe: string; satuan: string | null }[];
    item_dokumen_sop: { file: { original_filename: string } }[];
};

type Revision = { id: number; revision: number; tahun: number; created_at: string };

type Workflow = { id: number; label: string };

type Props = {
    workflow: Workflow;
    pp06: Pp06 | null;
    allRevisions: Revision[];
    canRevise: boolean;
    activeDraftId: number | null;
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

export default function Pp06({ workflow, pp06, allRevisions, canRevise, activeDraftId }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP06: Periode Tahunan', href: '#' },
    ];

    if (!pp06) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="PP06: Periode Tahunan" />
                <div className="p-6">
                    <p className="text-muted-foreground">PP06 belum di-compile. Selesaikan PP05 terlebih dahulu.</p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP06: Periode Tahunan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP06: Periode Tahunan" description="Data final yang sudah di-compile" />
                    <Badge>Revisi {pp06.revision}</Badge>
                    {canRevise && (
                        activeDraftId ? (
                            <Button variant="outline" className="ml-auto" onClick={() => router.visit(`/admin/workflows/pp/${workflow.id}/pp07/${activeDraftId}`)}>
                                Lanjutkan Revisi
                            </Button>
                        ) : (
                            <Button variant="outline" className="ml-auto" onClick={() => router.post(`/admin/workflows/pp/${workflow.id}/pp07/create`)}>
                                Revisi
                            </Button>
                        )
                    )}
                </div>

                {allRevisions.length > 1 && (
                    <SectionCard title="Riwayat Revisi">
                        <div className="flex gap-2">
                            {allRevisions.map((rev) => (
                                <Badge
                                    key={rev.id}
                                    variant={rev.id === pp06.id ? 'default' : 'outline'}
                                    className="cursor-pointer"
                                >
                                    Rev {rev.revision} — {formatDate(rev.created_at)}
                                </Badge>
                            ))}
                        </div>
                    </SectionCard>
                )}

                <SectionCard title="Informasi Periode">
                    <div className="grid gap-2 text-sm sm:grid-cols-3">
                        <div><span className="text-muted-foreground">Tahun:</span> {pp06.tahun}</div>
                        <div><span className="text-muted-foreground">Mulai Pra-Raker:</span> {pp06.tanggal_mulai_pra_raker}</div>
                        <div><span className="text-muted-foreground">Penetapan Program:</span> {pp06.tanggal_penetapan_program}</div>
                    </div>
                </SectionCard>

                <SectionCard title="Author Snapshot">
                    <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        {(['pp01', 'pp02', 'pp03', 'pp04', 'pp05'] as const).map((step) => (
                            <div key={step} className="rounded border p-2">
                                <div className="font-medium">{step.toUpperCase()}</div>
                                <div className="text-muted-foreground">{pp06[`${step}_created_by_user_name`]}</div>
                                <div className="text-xs text-muted-foreground">{pp06[`${step}_created_by_role_name`]}</div>
                            </div>
                        ))}
                    </div>
                </SectionCard>

                <SectionCard title="Plafon Anggaran">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-3 py-2 text-left font-medium">Tim</th>
                                <th className="px-3 py-2 text-left font-medium">Kode</th>
                                <th className="px-3 py-2 text-right font-medium">Plafon</th>
                                <th className="px-3 py-2 text-left font-medium">Bank</th>
                                <th className="px-3 py-2 text-left font-medium">No. Rekening</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pp06.item_plafon_anggaran.map((item, i) => (
                                <tr key={i} className="border-b last:border-0">
                                    <td className="px-3 py-2">{item.team?.name}</td>
                                    <td className="px-3 py-2">{item.kode_team}</td>
                                    <td className="px-3 py-2 text-right">{formatRupiah(item.plafon_anggaran)}</td>
                                    <td className="px-3 py-2">{item.nama_bank}</td>
                                    <td className="px-3 py-2">{item.nomor_rekening}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </SectionCard>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SectionCard title="Kode Bidang Pelayanan">
                        {pp06.kode_bidang_pelayanan.map((k, i) => <div key={i} className="text-sm"><span className="font-mono text-muted-foreground">{k.kode}</span> — {k.nama}</div>)}
                    </SectionCard>
                    <SectionCard title="Kode Sub Bidang">
                        {pp06.kode_sub_bidang_pelayanan.map((k, i) => <div key={i} className="text-sm"><span className="font-mono text-muted-foreground">{k.kode}</span> — {k.nama}</div>)}
                    </SectionCard>
                    <SectionCard title="Kode Kategori">
                        {pp06.kode_kategori_pelayanan.map((k, i) => <div key={i} className="text-sm"><span className="font-mono text-muted-foreground">{k.kode}</span> — {k.nama}</div>)}
                    </SectionCard>
                    <SectionCard title="Kode Jenis Program">
                        {pp06.kode_jenis_program.map((k, i) => <div key={i} className="text-sm"><span className="font-mono text-muted-foreground">{k.kode}</span> — {k.nama}</div>)}
                    </SectionCard>
                </div>

                <SectionCard title="Kuisioner">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-3 py-2 text-left font-medium">Kode</th>
                                <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                <th className="px-3 py-2 text-left font-medium">Tipe</th>
                                <th className="px-3 py-2 text-left font-medium">Satuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pp06.item_kuisioner.map((item, i) => (
                                <tr key={i} className="border-b last:border-0">
                                    <td className="px-3 py-2">{item.kode}</td>
                                    <td className="px-3 py-2">{item.pertanyaan}</td>
                                    <td className="px-3 py-2">{item.tipe}</td>
                                    <td className="px-3 py-2">{item.satuan || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </SectionCard>

                <SectionCard title="Dokumen SOP">
                    {pp06.item_dokumen_sop.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Tidak ada dokumen.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {pp06.item_dokumen_sop.map((dok, i) => (
                                <li key={i}>{dok.file.original_filename}</li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}

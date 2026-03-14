import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };
type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };
type PlafonItem = { team: { name: string }; kode_team: string; plafon_anggaran: number; nama_bank: string; nama_rekening: string; nomor_rekening: string };
type DokumenItem = { file: { id: number; original_filename: string } };

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
    kode_bidang_pelayanan: KodeItem[];
    kode_sub_bidang_pelayanan: KodeItem[];
    kode_kategori_pelayanan: KodeItem[];
    kode_jenis_program: KodeItem[];
    item_kuisioner: KuisionerItem[];
    item_plafon_anggaran: PlafonItem[];
    item_dokumen_sop: DokumenItem[];
};

type Revision = { id: number; revision: number; tahun: number; created_at: string };

type Workflow = { id: number; label: string; status: string; history: HistoryEntry[] };

type Props = {
    workflow: Workflow;
    pp06: Pp06 | null;
    allRevisions: Revision[];
    canRevise: boolean;
    activeDraftId: number | null;
    canComment: boolean;
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

function AuthorLine({ name, role, date }: { name: string; role: string; date: string }) {
    return (
        <p className="mt-3 text-xs text-muted-foreground">
            Disusun oleh: {name} ({role}) — {formatDate(date)}
        </p>
    );
}

export default function Pp06({ workflow, pp06, allRevisions, canRevise, activeDraftId, canComment }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP06: Periode Tahunan', href: '#' },
    ];

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05') return `/admin/workflows/pp/${workflow.id}/pp05`;
        if (step === 'PP06') return `/admin/workflows/pp/${workflow.id}/pp06${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
        if (entry.id && entry.table) {
            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
        }
        return null;
    }

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

    const totalPlafon = pp06.item_plafon_anggaran.reduce((sum, item) => sum + item.plafon_anggaran, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP06: Periode Tahunan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP06: Periode Tahunan" description="Data final yang sudah di-compile" />
                    <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Revisi {pp06.revision}</Badge>
                </div>

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp06"
                    canComment={canComment}
                    finalSteps={['PP06']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Riwayat Revisi */}
                {allRevisions.length > 1 && (
                    <SectionCard title={`Riwayat Revisi (${allRevisions.length})`}>
                        <div className="flex flex-wrap gap-2">
                            {allRevisions.map((rev) => (
                                <a key={rev.id} href={`/admin/workflows/pp/${workflow.id}/pp06?revision=${rev.revision}`}>
                                    <Badge
                                        variant={rev.id === pp06.id ? 'default' : 'outline'}
                                        className={rev.id !== pp06.id ? 'cursor-pointer hover:bg-muted' : ''}
                                    >
                                        Rev {rev.revision} — {formatDate(rev.created_at)}
                                    </Badge>
                                </a>
                            ))}
                        </div>
                    </SectionCard>
                )}

                {/* Informasi Periode */}
                <SectionCard title="Informasi Periode">
                    <div className="grid gap-2 text-sm sm:grid-cols-3">
                        <div><span className="text-muted-foreground">Tahun Periode:</span> {pp06.tahun}</div>
                        <div><span className="text-muted-foreground">Mulai Pra-Raker:</span> {formatDate(pp06.tanggal_mulai_pra_raker)}</div>
                        <div><span className="text-muted-foreground">Penetapan Program:</span> {formatDate(pp06.tanggal_penetapan_program)}</div>
                    </div>
                    <AuthorLine name={pp06.pp01_created_by_user_name} role={pp06.pp01_created_by_role_name} date={pp06.pp01_created_at} />
                </SectionCard>

                {/* Kode Referensi */}
                <SectionCard title="Kode Referensi">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <KodeList title="Bidang Pelayanan" items={pp06.kode_bidang_pelayanan} />
                        <KodeList title="Sub Bidang Pelayanan" items={pp06.kode_sub_bidang_pelayanan} />
                        <KodeList title="Kategori Pelayanan" items={pp06.kode_kategori_pelayanan} />
                        <KodeList title="Jenis Program" items={pp06.kode_jenis_program} />
                    </div>
                    <AuthorLine name={pp06.pp01_created_by_user_name} role={pp06.pp01_created_by_role_name} date={pp06.pp01_created_at} />
                </SectionCard>

                {/* Pertanyaan Kuisioner */}
                <SectionCard title="Pertanyaan Kuisioner">
                    <div className="overflow-x-auto">
                        <table className="min-w-200 w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                    <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Tipe</th>
                                    <th className="px-3 py-2 text-left font-medium w-24">Satuan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pp06.item_kuisioner.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-2 font-mono text-xs">{item.kode}</td>
                                        <td className="px-3 py-2">{item.pertanyaan}</td>
                                        <td className="px-3 py-2">{item.tipe}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{item.satuan || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <AuthorLine name={pp06.pp02_created_by_user_name} role={pp06.pp02_created_by_role_name} date={pp06.pp02_created_at} />
                </SectionCard>

                {/* Plafon Anggaran */}
                <SectionCard title="Plafon Anggaran">
                    <div className="overflow-x-auto">
                        <table className="min-w-200 w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                    <th className="px-3 py-2 text-left font-medium">Tim</th>
                                    <th className="px-3 py-2 text-right font-medium w-36">Plafon (Rp)</th>
                                    <th className="px-3 py-2 text-left font-medium w-24">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium w-32">Nama Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">No. Rek.</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pp06.item_plafon_anggaran.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-2 font-mono text-xs">{item.kode_team}</td>
                                        <td className="px-3 py-2">{item.team?.name}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(item.plafon_anggaran)}</td>
                                        <td className="px-3 py-2">{item.nama_bank}</td>
                                        <td className="px-3 py-2">{item.nama_rekening}</td>
                                        <td className="px-3 py-2">{item.nomor_rekening}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t bg-muted/30 font-medium">
                                    <td className="px-3 py-2" colSpan={2}>Total Plafon</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(totalPlafon)}</td>
                                    <td colSpan={3} />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <AuthorLine name={pp06.pp03_created_by_user_name} role={pp06.pp03_created_by_role_name} date={pp06.pp03_created_at} />
                </SectionCard>

                {/* Dokumen SOP */}
                <SectionCard title="Dokumen SOP">
                    {pp06.item_dokumen_sop.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Tidak ada dokumen dilampirkan.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {pp06.item_dokumen_sop.map((dok, i) => (
                                <li key={i} className="flex items-center gap-2">
                                    <a href={`/files/${dok.file.id}/download`} className="text-primary hover:underline">
                                        {dok.file.original_filename}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                    <AuthorLine name={pp06.pp04_created_by_user_name} role={pp06.pp04_created_by_role_name} date={pp06.pp04_created_at} />
                </SectionCard>

                {/* Persetujuan */}
                <SectionCard title="Persetujuan">
                    <p className="text-sm">
                        Disetujui oleh: <span className="font-medium">{pp06.pp05_created_by_user_name}</span>{' '}
                        <span className="text-muted-foreground">({pp06.pp05_created_by_role_name})</span>{' '}
                        — {formatDate(pp06.pp05_created_at)}
                    </p>
                </SectionCard>

                {/* Aksi */}
                {canRevise && (
                    <div className="flex gap-2">
                        {activeDraftId ? (
                            <Button variant="outline" onClick={() => router.visit(`/admin/workflows/pp/${workflow.id}/pp07/${activeDraftId}`)}>
                                Lanjutkan Revisi
                            </Button>
                        ) : (
                            <Button variant="outline" onClick={() => router.post(`/admin/workflows/pp/${workflow.id}/pp07/create`)}>
                                Revisi
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function KodeList({ title, items }: { title: string; items: KodeItem[] }) {
    return (
        <div>
            <h4 className="mb-1 text-xs font-medium text-muted-foreground">{title} ({items.length})</h4>
            <div className="space-y-0.5">
                {items.map((item, i) => (
                    <div key={i} className="text-xs">
                        <span className="font-mono text-muted-foreground">{item.kode}</span> — {item.nama}
                    </div>
                ))}
            </div>
        </div>
    );
}

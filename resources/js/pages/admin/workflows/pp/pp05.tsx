import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };
type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };
type PlafonItem = { team_id: number; kode_team: string; plafon_anggaran: number; nama_bank: string; nama_rekening: string; nomor_rekening: string; catatan: string | null; team?: { name: string } };

type ReviewData = {
    pp01: {
        tahun: number;
        tanggal_mulai_pra_raker: string;
        tanggal_penetapan_program: string;
        kode_bidang_pelayanan: KodeItem[];
        kode_sub_bidang_pelayanan: KodeItem[];
        kode_kategori_pelayanan: KodeItem[];
        kode_jenis_program: KodeItem[];
    } | null;
    pp02: { item_kuisioner: KuisionerItem[] } | null;
    pp03: { item_plafon_anggaran: PlafonItem[] } | null;
    pp04: { item_dokumen: { file: { original_filename: string } }[] } | null;
};

type Workflow = { id: number; label: string };

type Props = {
    workflow: Workflow;
    reviewData: ReviewData;
    canApprove: boolean;
    canReject: boolean;
    canTerminate: boolean;
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

export default function Pp05({ workflow, reviewData, canApprove, canReject, canTerminate }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [showTerminate, setShowTerminate] = useState(false);
    const [terminateNotes, setTerminateNotes] = useState('');
    const [terminateProcessing, setTerminateProcessing] = useState(false);

    const approveForm = useForm({ notes: '' });
    const rejectForm = useForm({ notes: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP05: Persetujuan', href: '#' },
    ];

    function handleApprove() {
        approveForm.post(`/admin/workflows/pp/${workflow.id}/pp05/approve`);
    }

    function handleReject() {
        if (!rejectForm.data.notes.trim()) return;
        rejectForm.post(`/admin/workflows/pp/${workflow.id}/pp05/reject`);
    }

    function handleTerminate() {
        if (!terminateNotes.trim()) return;
        setTerminateProcessing(true);
        router.post(`/admin/workflows/pp/${workflow.id}/terminate`, { notes: terminateNotes }, {
            onFinish: () => setTerminateProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP05: Persetujuan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <Heading title="PP05: Persetujuan" description="Review dan setujui / tolak perencanaan periode" />

                {(errors.approve || errors.reject) && (
                    <AlertError errors={[errors.approve || errors.reject]} title="Gagal" />
                )}

                {/* PP01 Review */}
                {reviewData.pp01 && (
                    <SectionCard title="PP01 — Rencana Periode">
                        <div className="grid gap-2 text-sm sm:grid-cols-3">
                            <div><span className="text-muted-foreground">Tahun:</span> {reviewData.pp01.tahun}</div>
                            <div><span className="text-muted-foreground">Mulai Pra-Raker:</span> {reviewData.pp01.tanggal_mulai_pra_raker}</div>
                            <div><span className="text-muted-foreground">Penetapan Program:</span> {reviewData.pp01.tanggal_penetapan_program}</div>
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <KodeList title="Bidang Pelayanan" items={reviewData.pp01.kode_bidang_pelayanan} />
                            <KodeList title="Sub Bidang" items={reviewData.pp01.kode_sub_bidang_pelayanan} />
                            <KodeList title="Kategori" items={reviewData.pp01.kode_kategori_pelayanan} />
                            <KodeList title="Jenis Program" items={reviewData.pp01.kode_jenis_program} />
                        </div>
                    </SectionCard>
                )}

                {/* PP02 Review */}
                {reviewData.pp02 && (
                    <SectionCard title="PP02 — Pertanyaan Kuisioner">
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
                                {reviewData.pp02.item_kuisioner.map((item, i) => (
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
                )}

                {/* PP03 Review */}
                {reviewData.pp03 && (
                    <SectionCard title="PP03 — Plafon Anggaran">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium">Tim</th>
                                    <th className="px-3 py-2 text-left font-medium">Kode</th>
                                    <th className="px-3 py-2 text-right font-medium">Plafon</th>
                                    <th className="px-3 py-2 text-left font-medium">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium">Nama Rekening</th>
                                    <th className="px-3 py-2 text-left font-medium">No. Rekening</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reviewData.pp03.item_plafon_anggaran.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-2">{item.team?.name ?? `Tim #${item.team_id}`}</td>
                                        <td className="px-3 py-2">{item.kode_team}</td>
                                        <td className="px-3 py-2 text-right">{formatRupiah(item.plafon_anggaran)}</td>
                                        <td className="px-3 py-2">{item.nama_bank}</td>
                                        <td className="px-3 py-2">{item.nama_rekening}</td>
                                        <td className="px-3 py-2">{item.nomor_rekening}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t bg-muted/50 font-medium">
                                    <td className="px-3 py-2" colSpan={2}>Total Plafon</td>
                                    <td className="px-3 py-2 text-right">
                                        {formatRupiah(reviewData.pp03.item_plafon_anggaran.reduce((sum, item) => sum + item.plafon_anggaran, 0))}
                                    </td>
                                    <td colSpan={3} />
                                </tr>
                            </tfoot>
                        </table>
                    </SectionCard>
                )}

                {/* PP04 Review */}
                {reviewData.pp04 && (
                    <SectionCard title="PP04 — Dokumen SOP">
                        {reviewData.pp04.item_dokumen.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Tidak ada dokumen dilampirkan.</p>
                        ) : (
                            <ul className="space-y-1 text-sm">
                                {reviewData.pp04.item_dokumen.map((dok, i) => (
                                    <li key={i} className="flex items-center gap-2">
                                        <Badge variant="outline">File</Badge>
                                        {dok.file.original_filename}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                )}

                {/* Action Buttons */}
                {(canApprove || canReject) && (
                    <SectionCard title="Keputusan">
                        <div className="space-y-4">
                            {canApprove && (
                                <div className="space-y-2">
                                    <Label>Catatan Persetujuan (opsional)</Label>
                                    <textarea
                                        value={approveForm.data.notes}
                                        onChange={(e) => approveForm.setData('notes', e.target.value)}
                                        placeholder="Tulis catatan persetujuan..."
                                        rows={2}
                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </div>
                            )}
                            {canReject && (
                                <div className="space-y-2">
                                    <Label>Alasan Penolakan (wajib untuk tolak)</Label>
                                    <textarea
                                        value={rejectForm.data.notes}
                                        onChange={(e) => rejectForm.setData('notes', e.target.value)}
                                        placeholder="Tulis alasan penolakan..."
                                        rows={3}
                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </div>
                            )}
                            <div className="flex gap-2">
                                {canApprove && (
                                    <Button onClick={handleApprove} disabled={approveForm.processing}>
                                        Setujui
                                    </Button>
                                )}
                                {canReject && (
                                    <Button
                                        variant="destructive"
                                        onClick={handleReject}
                                        disabled={rejectForm.processing || !rejectForm.data.notes.trim()}
                                    >
                                        Tolak
                                    </Button>
                                )}
                                {canTerminate && !showTerminate && (
                                    <Button variant="destructive" className="ml-auto" onClick={() => setShowTerminate(true)}>Batalkan Workflow</Button>
                                )}
                            </div>
                        </div>
                    </SectionCard>
                )}

                {showTerminate && (
                    <SectionCard title="Batalkan Workflow">
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">Workflow yang dibatalkan tidak dapat dilanjutkan. Tuliskan alasan pembatalan.</p>
                            <textarea
                                value={terminateNotes}
                                onChange={(e) => setTerminateNotes(e.target.value)}
                                placeholder="Alasan pembatalan (wajib)..."
                                rows={3}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <div className="flex gap-2">
                                <Button variant="destructive" onClick={handleTerminate} disabled={terminateProcessing || !terminateNotes.trim()}>Konfirmasi Batalkan</Button>
                                <Button variant="outline" onClick={() => setShowTerminate(false)}>Batal</Button>
                            </div>
                        </div>
                    </SectionCard>
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

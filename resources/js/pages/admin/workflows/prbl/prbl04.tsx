import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileText } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { prbl01Kegiatan, prbl03RefundData, prblHistory } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const workflowKode = 'PRBL-2027-KA-03';
const workflowUuid = 'prbl-uuid-001';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Laporan Bulanan', href: '/admin/workflows/prbl' },
    { title: workflowKode, href: `/admin/workflows/prbl/${workflowUuid}` },
    { title: 'Review Akhir', href: '#' },
];

const steps = [
    { code: 'PRBL01', label: 'Laporan', status: 'completed' as const },
    { code: 'PRBL02A', label: 'Narasi', status: 'completed' as const },
    { code: 'PRBL02B', label: 'Anggaran', status: 'completed' as const },
    { code: 'PRBL03', label: 'Refund', status: 'completed' as const },
    { code: 'PRBL04', label: 'Review', status: 'active' as const },
    { code: 'PRBL05', label: 'Final', status: 'pending' as const },
];

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('id-ID').format(amount);
}

const totalDicairkan = prbl01Kegiatan.reduce((sum, k) => sum + k.subtotalDicairkan, 0);
const totalRealisasi = prbl01Kegiatan.reduce((sum, k) => sum + k.subtotalRealisasi, 0);

export default function AdminPrbl04() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL04 — Review Akhir" />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between">
                    <Heading title="Review Akhir" description="Verifikasi laporan dan refund bulan Maret 2027" />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/admin/workflows/prbl/${workflowUuid}`}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StepProgress steps={steps} currentStep="PRBL04" />

                <HistoryTimeline entries={prblHistory} />

                <SectionCard title="Status Persetujuan Sebelumnya">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="px-2 py-2 font-medium">Langkah</th>
                                    <th className="px-2 py-2 font-medium">Status</th>
                                    <th className="px-2 py-2 font-medium">Oleh</th>
                                    <th className="px-2 py-2 font-medium">Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="border-b">
                                    <td className="px-2 py-2">Persetujuan Narasi (PRBL02A)</td>
                                    <td className="px-2 py-2">
                                        <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Disetujui</Badge>
                                    </td>
                                    <td className="px-2 py-2">Budi S. (Ketua Monev)</td>
                                    <td className="px-2 py-2">22 Maret 2027</td>
                                </tr>
                                <tr className="border-b">
                                    <td className="px-2 py-2">Persetujuan Anggaran (PRBL02B)</td>
                                    <td className="px-2 py-2">
                                        <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Disetujui</Badge>
                                    </td>
                                    <td className="px-2 py-2">Sari D. (Bendahara Umum 1)</td>
                                    <td className="px-2 py-2">23 Maret 2027</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard title="Info Laporan">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Diajukan oleh</span>
                            <span className="font-medium">Rina Wijaya (Ketua)</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tim</span>
                            <span className="font-medium">Divisi Pendidikan</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Bulan Laporan</span>
                            <span className="font-medium">Maret 2027 (3)</span>
                        </div>
                    </div>
                </SectionCard>

                {prbl01Kegiatan.map((kegiatan, idx) => (
                    <SectionCard key={kegiatan.id} title={`Laporan Kegiatan ${idx + 1}`}>
                        <div className="space-y-4">
                            <div className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Program</span>
                                    <span className="font-medium">
                                        {kegiatan.program} ({kegiatan.programKode})
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Kegiatan</span>
                                    <span className="font-medium">{kegiatan.kegiatan}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Bulan</span>
                                    <span className="font-medium">{kegiatan.bulan}</span>
                                </div>
                            </div>

                            <Separator />

                            <div className="space-y-3">
                                <div>
                                    <p className="mb-1 text-xs font-medium text-muted-foreground">Masalah/Kendala</p>
                                    <blockquote className="border-l-2 pl-3 text-sm italic">
                                        {kegiatan.masalah || <span className="text-muted-foreground">Tidak ada</span>}
                                    </blockquote>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs font-medium text-muted-foreground">Langkah Penanganan</p>
                                    <blockquote className="border-l-2 pl-3 text-sm italic">
                                        {kegiatan.langkah || <span className="text-muted-foreground">Tidak ada</span>}
                                    </blockquote>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs font-medium text-muted-foreground">Harapan</p>
                                    <blockquote className="border-l-2 pl-3 text-sm italic">
                                        {kegiatan.harapan || <span className="text-muted-foreground">Tidak ada</span>}
                                    </blockquote>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs font-medium text-muted-foreground">Catatan Tim</p>
                                    <blockquote className="border-l-2 pl-3 text-sm italic">
                                        {kegiatan.catatan || <span className="text-muted-foreground">Tidak ada</span>}
                                    </blockquote>
                                </div>
                            </div>

                            <Separator />

                            <div className="space-y-2">
                                <h4 className="text-sm font-medium">Kuisioner</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left">
                                                <th className="px-2 py-2 font-medium">#</th>
                                                <th className="px-2 py-2 font-medium">Pertanyaan</th>
                                                <th className="px-2 py-2 font-medium">Tipe</th>
                                                <th className="px-2 py-2 font-medium">Satuan</th>
                                                <th className="px-2 py-2 font-medium">Jawaban</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kegiatan.kuisioner.map((q, qi) => (
                                                <tr key={q.id} className="border-b">
                                                    <td className="px-2 py-2">{qi + 1}</td>
                                                    <td className="px-2 py-2">{q.pertanyaan}</td>
                                                    <td className="px-2 py-2">{q.tipe}</td>
                                                    <td className="px-2 py-2">{q.satuan ?? '-'}</td>
                                                    <td className="px-2 py-2 font-medium">{q.jawaban}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <Separator />

                            <div className="space-y-2">
                                <h4 className="text-sm font-medium">Realisasi Anggaran</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left">
                                                <th className="px-2 py-2 font-medium">Kode</th>
                                                <th className="px-2 py-2 font-medium">Mata Ang.</th>
                                                <th className="px-2 py-2 text-right font-medium">Dicairkan (Rp)</th>
                                                <th className="px-2 py-2 text-right font-medium">Realisasi (Rp)</th>
                                                <th className="px-2 py-2 text-right font-medium">Selisih (Rp)</th>
                                                <th className="px-2 py-2 font-medium">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kegiatan.realisasi.map((r) => (
                                                <tr key={r.kode} className="border-b">
                                                    <td className="px-2 py-2"><KodeAnggaranDisplay kode={r.kode} /></td>
                                                    <td className="px-2 py-2">{r.mata}</td>
                                                    <td className="px-2 py-2 text-right">{formatCurrency(r.dicairkan)}</td>
                                                    <td className="px-2 py-2 text-right">{formatCurrency(r.realisasi)}</td>
                                                    <td className="px-2 py-2 text-right">{formatCurrency(r.dicairkan - r.realisasi)}</td>
                                                    <td className="px-2 py-2 text-muted-foreground">{r.komentar}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t font-medium">
                                                <td colSpan={2} className="px-2 py-2">
                                                    Subtotal
                                                </td>
                                                <td className="px-2 py-2 text-right">{formatCurrency(kegiatan.subtotalDicairkan)}</td>
                                                <td className="px-2 py-2 text-right">{formatCurrency(kegiatan.subtotalRealisasi)}</td>
                                                <td className="px-2 py-2 text-right">
                                                    {formatCurrency(kegiatan.subtotalDicairkan - kegiatan.subtotalRealisasi)}
                                                </td>
                                                <td className="px-2 py-2" />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </SectionCard>
                ))}

                <SectionCard title="Refund">
                    <div className="space-y-3">
                        <div className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Nominal Refund</span>
                                <span className="font-semibold">Rp {formatCurrency(prbl03RefundData.nominalRefund)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Keterangan</span>
                                <span className="font-medium">{prbl03RefundData.keterangan}</span>
                            </div>
                        </div>

                        <Separator />

                        <div className="space-y-2">
                            <p className="text-xs font-medium text-muted-foreground">Bukti Transfer Refund</p>
                            {prbl03RefundData.buktiTransfer.map((f, i) => (
                                <div key={i} className="flex items-center justify-between rounded border px-3 py-2 text-sm">
                                    <div className="flex items-center gap-2">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span>{f}</span>
                                    </div>
                                    <Button variant="ghost" size="sm">
                                        <Download className="mr-1 h-3 w-3" />
                                        Unduh
                                    </Button>
                                </div>
                            ))}
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-medium text-muted-foreground">Foto Nota</p>
                            {prbl03RefundData.fotoNota.map((f, i) => (
                                <div key={i} className="flex items-center justify-between rounded border px-3 py-2 text-sm">
                                    <div className="flex items-center gap-2">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span>{f}</span>
                                    </div>
                                    <Button variant="ghost" size="sm">
                                        <Download className="mr-1 h-3 w-3" />
                                        Unduh
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title="Ringkasan">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Dicairkan</span>
                            <span className="font-medium">Rp {formatCurrency(totalDicairkan)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Realisasi</span>
                            <span className="font-medium">Rp {formatCurrency(totalRealisasi)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Refund</span>
                            <span className="font-medium">Rp {formatCurrency(prbl03RefundData.nominalRefund)}</span>
                        </div>
                    </div>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="destructive">Tolak</Button>}
                        title="Tolak Laporan?"
                        description="Laporan akan dikembalikan ke tim untuk diperbaiki (kembali ke PRBL01)."
                        confirmLabel="Tolak"
                        variant="destructive"
                        requireNotes
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Setujui</Button>}
                        title="Setujui Laporan Bulanan?"
                        description="Laporan akan difinalisasi dan menjadi dokumen resmi (PRBL05)."
                        confirmLabel="Setujui"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

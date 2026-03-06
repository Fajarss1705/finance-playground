import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileText } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { prbl01Kegiatan, prbl03RefundData, prbl05FinalData, prblHistory } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const workflowKode = 'PRBL-2027-KA-02';
const workflowUuid = 'prbl-uuid-002';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Laporan Bulanan', href: '/admin/workflows/prbl' },
    { title: workflowKode, href: `/admin/workflows/prbl/${workflowUuid}` },
    { title: 'Laporan Bulanan Final', href: '#' },
];

const steps = [
    { code: 'PRBL01', label: 'Laporan', status: 'completed' as const },
    { code: 'PRBL02A', label: 'Narasi', status: 'completed' as const },
    { code: 'PRBL02B', label: 'Anggaran', status: 'completed' as const },
    { code: 'PRBL03', label: 'Refund', status: 'completed' as const },
    { code: 'PRBL04', label: 'Review', status: 'completed' as const },
    { code: 'PRBL05', label: 'Final', status: 'completed' as const },
];

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('id-ID').format(amount);
}

export default function AdminPrbl05() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PRBL05 — Laporan Bulanan Final — ${prbl05FinalData.bulan} ${prbl05FinalData.tahun}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between">
                    <Heading
                        title={`Laporan Bulanan Final — ${prbl05FinalData.bulan} ${prbl05FinalData.tahun}`}
                        description="Dokumen resmi pelaporan dan realisasi anggaran"
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/admin/workflows/prbl/${workflowUuid}`}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StepProgress steps={steps} />

                <HistoryTimeline entries={prblHistory} />

                <SectionCard title="Info Laporan Bulanan">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tim</span>
                            <span className="font-medium">{prbl05FinalData.team}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Bulan Laporan</span>
                            <span className="font-medium">
                                {prbl05FinalData.bulan} {prbl05FinalData.tahun} ({prbl05FinalData.bulanNum})
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Periode</span>
                            <span className="font-medium">{prbl05FinalData.tahun}</span>
                        </div>
                        <Separator />
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Dilaporkan oleh</span>
                            <span className="font-medium">{prbl05FinalData.dilaporkanOleh}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tanggal laporan</span>
                            <span className="font-medium">{prbl05FinalData.tanggalLaporan}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Narasi disetujui oleh</span>
                            <span className="font-medium">{prbl05FinalData.narasiDisetujui}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tanggal persetujuan narasi</span>
                            <span className="font-medium">{prbl05FinalData.tanggalNarasi}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Anggaran disetujui oleh</span>
                            <span className="font-medium">{prbl05FinalData.anggaranDisetujui}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tanggal persetujuan anggaran</span>
                            <span className="font-medium">{prbl05FinalData.tanggalAnggaran}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Refund oleh</span>
                            <span className="font-medium">{prbl05FinalData.refundOleh}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tanggal refund</span>
                            <span className="font-medium">{prbl05FinalData.tanggalRefund}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Review akhir oleh</span>
                            <span className="font-medium">{prbl05FinalData.reviewAkhir}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tanggal review akhir</span>
                            <span className="font-medium">{prbl05FinalData.tanggalReview}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Rekening Tim</span>
                            <span className="font-medium">
                                {prbl05FinalData.rekeningTim.bank} {prbl05FinalData.rekeningTim.nomor}
                            </span>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title="Download">
                    <div className="flex gap-3">
                        <Button variant="outline">
                            <Download className="mr-2 h-4 w-4" />
                            Unduh PDF
                        </Button>
                        <Button variant="outline">
                            <Download className="mr-2 h-4 w-4" />
                            Unduh Excel
                        </Button>
                    </div>
                </SectionCard>

                {prbl01Kegiatan.map((kegiatan, idx) => (
                    <SectionCard key={kegiatan.id} title={`Kegiatan ${idx + 1}`}>
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
                            <span className="font-medium">Rp {formatCurrency(prbl05FinalData.totalDicairkan)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Realisasi</span>
                            <span className="font-medium">Rp {formatCurrency(prbl05FinalData.totalRealisasi)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Refund</span>
                            <span className="font-medium">Rp {formatCurrency(prbl05FinalData.totalRefund)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Item</span>
                            <span className="font-medium">{prbl05FinalData.totalItem}</span>
                        </div>
                    </div>
                </SectionCard>
            </div>
        </AppLayout>
    );
}

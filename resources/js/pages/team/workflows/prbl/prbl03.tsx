import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Upload } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { prbl03RefundData, prblHistory } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const workflowKode = 'PRBL-2027-KA-03';
const workflowUuid = 'prbl-uuid-001';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tim', href: '/team' },
    { title: 'Laporan Bulanan', href: '/team/workflows/prbl' },
    { title: workflowKode, href: `/team/workflows/prbl/${workflowUuid}` },
    { title: 'Pengembalian Dana', href: '#' },
];

const steps = [
    { code: 'PRBL01', label: 'Laporan', status: 'completed' as const },
    { code: 'PRBL02A', label: 'Narasi', status: 'completed' as const },
    { code: 'PRBL02B', label: 'Anggaran', status: 'completed' as const },
    { code: 'PRBL03', label: 'Refund', status: 'active' as const },
    { code: 'PRBL04', label: 'Review', status: 'pending' as const },
    { code: 'PRBL05', label: 'Final', status: 'pending' as const },
];

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('id-ID').format(amount);
}

export default function TeamPrbl03() {
    const [keterangan, setKeterangan] = useState(prbl03RefundData.keterangan);
    const [buktiTransfer] = useState(prbl03RefundData.buktiTransfer);
    const [fotoNota] = useState(prbl03RefundData.fotoNota);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL03 — Pengembalian Dana" />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between">
                    <Heading title="Pengembalian Dana" description="Konfirmasi refund dan upload bukti bulan Maret 2027" />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/team/workflows/prbl/${workflowUuid}`}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StepProgress steps={steps} currentStep="PRBL03" />

                <HistoryTimeline entries={prblHistory} />

                <SectionCard title="Info Rekening Tujuan Refund">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Refund ke</span>
                            <span className="font-medium">
                                {prbl03RefundData.rekeningTujuan.bank} {prbl03RefundData.rekeningTujuan.nomor} a.n.{' '}
                                {prbl03RefundData.rekeningTujuan.nama}
                            </span>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title="Ringkasan Selisih">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="px-2 py-2 font-medium">Kode</th>
                                    <th className="px-2 py-2 font-medium">Mata Ang.</th>
                                    <th className="px-2 py-2 text-right font-medium">Dicairkan (Rp)</th>
                                    <th className="px-2 py-2 text-right font-medium">Realisasi (Rp)</th>
                                    <th className="px-2 py-2 text-right font-medium">Selisih (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {prbl03RefundData.selisihItems.map((item) => (
                                    <tr key={item.kode} className="border-b">
                                        <td className="px-2 py-2"><KodeAnggaranDisplay kode={item.kode} /></td>
                                        <td className="px-2 py-2">{item.mata}</td>
                                        <td className="px-2 py-2 text-right">{formatCurrency(item.dicairkan)}</td>
                                        <td className="px-2 py-2 text-right">{formatCurrency(item.realisasi)}</td>
                                        <td className="px-2 py-2 text-right">{formatCurrency(item.selisih)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t font-medium">
                                    <td colSpan={2} className="px-2 py-2">
                                        Total
                                    </td>
                                    <td className="px-2 py-2 text-right">{formatCurrency(prbl03RefundData.totalDicairkan)}</td>
                                    <td className="px-2 py-2 text-right">{formatCurrency(prbl03RefundData.totalRealisasi)}</td>
                                    <td className="px-2 py-2 text-right">{formatCurrency(prbl03RefundData.totalSelisih)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard title="Total Refund">
                    <div className="space-y-3">
                        <div className="flex justify-between text-sm">
                            <span className="text-muted-foreground">Nominal Refund</span>
                            <span className="font-semibold">Rp {formatCurrency(prbl03RefundData.nominalRefund)}</span>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Keterangan (opsional)</Label>
                            <textarea
                                value={keterangan}
                                onChange={(e) => setKeterangan(e.target.value)}
                                rows={2}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                placeholder="Keterangan tambahan..."
                            />
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title="Upload Bukti Transfer Refund">
                    <div className="space-y-3">
                        <p className="text-xs text-muted-foreground">Wajib jika nominal refund &gt; 0</p>
                        <label
                            htmlFor="bukti-transfer-upload"
                            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs hover:bg-accent hover:text-accent-foreground"
                        >
                            <Upload className="h-4 w-4 text-muted-foreground" />
                            <span className="text-muted-foreground">Pilih file...</span>
                        </label>
                        <input id="bukti-transfer-upload" type="file" className="sr-only" accept="image/*,.pdf" />
                        {buktiTransfer.length > 0 && (
                            <div className="space-y-1">
                                {buktiTransfer.map((f, i) => (
                                    <div key={i} className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span>{f}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </SectionCard>

                <SectionCard title="Upload Foto Nota di Kantor Pusat">
                    <div className="space-y-3">
                        <p className="text-xs text-muted-foreground">Selalu wajib</p>
                        <label
                            htmlFor="foto-nota-upload"
                            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs hover:bg-accent hover:text-accent-foreground"
                        >
                            <Upload className="h-4 w-4 text-muted-foreground" />
                            <span className="text-muted-foreground">Pilih file...</span>
                        </label>
                        <input id="foto-nota-upload" type="file" className="sr-only" multiple accept="image/*,.pdf" />
                        {fotoNota.length > 0 && (
                            <div className="space-y-1">
                                {fotoNota.map((f, i) => (
                                    <div key={i} className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span>{f}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <Button variant="secondary">Simpan Draft</Button>
                    <ActionConfirmDialog
                        trigger={<Button>Submit</Button>}
                        title="Submit Pengembalian Dana?"
                        description="Bukti refund akan dikirim untuk review akhir (PRBL04)."
                        confirmLabel="Submit"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

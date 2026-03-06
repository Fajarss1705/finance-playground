import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import Heading from '@/components/heading';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { pabd05FinalData, pabdHistory } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Anggaran Bulanan', href: '/admin/workflows/pabd' },
    { title: 'PABD-2027-KA-02', href: '#' },
    { title: 'Pengajuan Bulanan Final', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'completed' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'completed' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'completed' as const },
    { code: 'PABD05', label: 'Final', status: 'completed' as const },
];

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);
const d = pabd05FinalData;

export default function AdminPabd05() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pengajuan Bulanan Final" />
            <div className="space-y-6 p-6">
                <Heading
                    title={`Pengajuan Bulanan Final — ${d.bulan} ${d.tahun}`}
                    description="Dokumen resmi pengajuan anggaran bulanan"
                />

                <StepProgress steps={steps} />
                <HistoryTimeline entries={pabdHistory} />

                <SectionCard title="Info Pengajuan Bulanan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Tim</dt>
                            <dd className="font-medium">{d.team}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bulan</dt>
                            <dd className="font-medium">{d.bulan} {d.tahun}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Periode</dt>
                            <dd className="font-medium">{d.tahun}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Diajukan oleh</dt>
                            <dd className="font-medium">{d.diajukanOleh}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tanggal Submit</dt>
                            <dd className="font-medium">{d.tanggalSubmit}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Ada Perubahan</dt>
                            <dd className="font-medium">{d.adaPerubahan ? 'Ya' : 'Tidak'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Verifikasi oleh</dt>
                            <dd className="font-medium">{d.verifikasiOleh}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tanggal Verifikasi</dt>
                            <dd className="font-medium">{d.tanggalVerifikasi}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bukti Transfer oleh</dt>
                            <dd className="font-medium">{d.buktiOleh}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tanggal Bukti</dt>
                            <dd className="font-medium">{d.tanggalBukti}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Rekening Tim</dt>
                            <dd className="font-medium">
                                {d.rekeningTim.bank} <span className="font-mono">{d.rekeningTim.nomor}</span> a.n. {d.rekeningTim.nama}
                            </dd>
                        </div>
                    </dl>
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

                <SectionCard title="Daftar Pencairan">
                    <div className="space-y-6">
                        {d.daftarPencairan.map((prog) => (
                            <div key={prog.programKode}>
                                <h4 className="mb-2 text-sm font-semibold text-foreground">
                                    {prog.programKode} — {prog.program}
                                </h4>
                                {prog.kegiatan.map((keg) => (
                                    <div key={keg.nama} className="mb-3 ml-2">
                                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                                            {keg.nama} — {keg.bulan}
                                        </p>
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b bg-muted/50">
                                                    <th className="px-3 py-2 text-left font-medium">Kode</th>
                                                    <th className="px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                    <th className="px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {keg.items.map((item) => (
                                                    <tr key={item.kode} className="border-b last:border-0">
                                                        <td className="px-3 py-2"><KodeAnggaranDisplay kode={item.kode} /></td>
                                                        <td className="px-3 py-2">{item.mata}</td>
                                                        <td className="px-3 py-2 text-right tabular-nums">{fmt(item.nominal)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                            <tfoot>
                                                <tr className="border-t bg-muted/30">
                                                    <td colSpan={2} className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
                                                        Subtotal
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-medium tabular-nums">
                                                        {fmt(keg.subtotal)}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </SectionCard>

                <SectionCard title="Ringkasan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Total dicairkan</dt>
                            <dd className="text-lg font-semibold">Rp {fmt(d.totalDicairkan)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Total item</dt>
                            <dd className="text-lg font-semibold">{d.totalItem}</dd>
                        </div>
                    </dl>
                </SectionCard>
            </div>
        </AppLayout>
    );
}

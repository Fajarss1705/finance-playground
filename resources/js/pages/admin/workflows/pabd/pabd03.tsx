import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import Heading from '@/components/heading';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { pabd03DaftarPencairan, pabdHistory } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Anggaran Bulanan', href: '/admin/workflows/pabd' },
    { title: 'PABD-2027-KA-03', href: '#' },
    { title: 'Verifikasi Pencairan', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'completed' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'active' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'pending' as const },
    { code: 'PABD05', label: 'Final', status: 'pending' as const },
];

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);

export default function Pabd03() {
    const totalDicairkan = pabd03DaftarPencairan.reduce(
        (sum, p) => sum + p.kegiatan.reduce((ks, k) => ks + k.subtotal, 0),
        0,
    );
    const totalItems = pabd03DaftarPencairan.reduce(
        (sum, p) => sum + p.kegiatan.reduce((ks, k) => ks + k.items.length, 0),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Verifikasi Pencairan" />
            <div className="space-y-6 p-6">
                <Heading
                    title="Verifikasi Pencairan"
                    description="Review akhir sebelum pencairan anggaran bulan Maret 2027"
                />

                <StepProgress steps={steps} currentStep="PABD03" />
                <HistoryTimeline entries={pabdHistory} />

                <SectionCard title="Info Pengajuan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-muted-foreground">Diajukan oleh</dt>
                            <dd className="font-medium">Rina Wijaya</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tim</dt>
                            <dd className="font-medium">Divisi Pendidikan</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bulan Anggaran</dt>
                            <dd className="font-medium">Maret 2027</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tanggal submit</dt>
                            <dd className="font-medium">20 Feb 2027, 14:00</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Info Rekening Tujuan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Nama</dt>
                            <dd className="font-medium">Rina Wijaya</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Nomor Rekening</dt>
                            <dd className="font-medium font-mono">0881234501</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bank</dt>
                            <dd className="font-medium">BCA</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Daftar Anggaran Dicairkan">
                    <div className="space-y-6">
                        {pabd03DaftarPencairan.map((prog) => (
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
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Total item</dt>
                            <dd className="font-medium">{totalItems}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Total dicairkan</dt>
                            <dd className="font-medium">Rp {fmt(totalDicairkan)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Item hangus</dt>
                            <dd className="font-medium">1 (Rp {fmt(150000)})</dd>
                        </div>
                    </dl>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="destructive">Tolak</Button>}
                        title="Tolak Pencairan"
                        description="Pencairan akan ditolak dan dikembalikan ke tim untuk revisi pengajuan."
                        confirmLabel="Tolak"
                        variant="destructive"
                        requireNotes
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Setujui</Button>}
                        title="Setujui Pencairan"
                        description="Pencairan akan disetujui dan dilanjutkan ke tahap bukti transfer."
                        confirmLabel="Setujui"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

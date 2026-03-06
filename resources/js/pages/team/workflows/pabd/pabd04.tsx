import { Head } from '@inertiajs/react';
import { FileText, Upload } from 'lucide-react';
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
    { title: 'Tim', href: '/team' },
    { title: 'Anggaran Bulanan', href: '/team/workflows/pabd' },
    { title: 'PABD-2027-KA-03', href: '/team/workflows/pabd/pabd-uuid-001' },
    { title: 'Bukti Transfer', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'completed' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'completed' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'active' as const },
    { code: 'PABD05', label: 'Final', status: 'pending' as const },
];

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);

export default function Pabd04() {
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
            <Head title="Bukti Transfer" />
            <div className="space-y-6 p-6">
                <Heading
                    title="Bukti Transfer"
                    description="Upload bukti transfer pencairan anggaran bulan Maret 2027"
                />

                <StepProgress steps={steps} currentStep="PABD04" />
                <HistoryTimeline entries={pabdHistory} />

                <SectionCard title="Ringkasan Pencairan" className="border-primary/30 bg-primary/5 dark:bg-primary/10">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Total dicairkan</dt>
                            <dd className="text-lg font-semibold">Rp {fmt(totalDicairkan)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Total item</dt>
                            <dd className="font-medium">{totalItems} item</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Item hangus</dt>
                            <dd className="font-medium">1 (Rp {fmt(150000)})</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Info Rekening Tujuan" className="border-primary/30 bg-primary/5 dark:bg-primary/10">
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
                            <dt className="text-muted-foreground">Disetujui oleh</dt>
                            <dd className="font-medium">Sari Dewi (BU1)</dd>
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

                <SectionCard title="Upload Bukti Transfer">
                    <div className="space-y-4">
                        <label className="flex cursor-pointer flex-col items-center gap-3 rounded-lg border-2 border-dashed border-muted-foreground/25 px-6 py-8 text-center transition-colors hover:border-muted-foreground/50 hover:bg-muted/30">
                            <Upload className="h-8 w-8 text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">Klik untuk memilih file atau seret file ke sini</p>
                                <p className="text-xs text-muted-foreground">JPG, PNG, atau PDF (maks. 5MB)</p>
                            </div>
                            <input type="file" className="sr-only" accept="image/*,.pdf" />
                        </label>

                        <div className="space-y-2">
                            <p className="text-xs font-medium text-muted-foreground">File yang sudah diunggah:</p>
                            <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                <span className="flex-1 truncate">bukti_transfer_maret_2027.jpg</span>
                                <button type="button" className="shrink-0 text-xs text-muted-foreground hover:text-destructive">
                                    Hapus
                                </button>
                            </div>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title="Catatan">
                    <textarea
                        placeholder="Catatan tambahan (opsional)..."
                        rows={3}
                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="secondary">Simpan Draft</Button>}
                        title="Simpan Draft"
                        description="Bukti transfer akan disimpan sebagai draft."
                        confirmLabel="Simpan"
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Submit</Button>}
                        title="Submit Bukti Transfer"
                        description="Bukti transfer akan dikirim. Pastikan file sudah benar."
                        confirmLabel="Submit"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

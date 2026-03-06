import { Head } from '@inertiajs/react';
import { Download, FileText, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Heading from '@/components/heading';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { bulanOptions, pabd02aAvailableItems, pabd02aPerubahan, pabdHistory } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const bulanAnggaran = 3; // Maret

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tim', href: '/team' },
    { title: 'Anggaran Bulanan', href: '/team/workflows/pabd' },
    { title: 'PABD-2027-KA-03', href: '/team/workflows/pabd/pabd-uuid-001' },
    { title: 'Perubahan Anggaran', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'active' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'pending' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'pending' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'pending' as const },
    { code: 'PABD05', label: 'Final', status: 'pending' as const },
];

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);

const tipeBadge: Record<string, { label: string; className: string }> = {
    tarik_maju: { label: 'Tarik Maju', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    tarik_mundur: { label: 'Tarik Mundur', className: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' },
    penambahan: { label: 'Penambahan', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
};

function getBulanLabel(num: number): string {
    return bulanOptions.find((b) => b.value === num)?.label ?? String(num);
}

function getSelectedItem(tipe: 'tarik_maju' | 'tarik_mundur', itemId: number) {
    const list = pabd02aAvailableItems[tipe];
    return list.find((i) => i.id === itemId);
}

function getBulanTujuanOptions(tipe: 'tarik_maju' | 'tarik_mundur') {
    if (tipe === 'tarik_maju') {
        return bulanOptions.filter((b) => b.value <= bulanAnggaran);
    }
    return bulanOptions.filter((b) => b.value > bulanAnggaran);
}

export default function Pabd02a() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Perubahan Anggaran" />
            <div className="space-y-6 p-6">
                <Heading title="Perubahan Anggaran" description="Ajukan perubahan anggaran bulan Maret 2027" />

                <StepProgress steps={steps} currentStep="PABD02A" />
                <HistoryTimeline entries={pabdHistory} />

                <SectionCard title="Info Pengajuan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Tim</dt>
                            <dd className="font-medium">Divisi Pendidikan</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bulan Anggaran</dt>
                            <dd className="font-medium">Maret 2027</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Periode</dt>
                            <dd className="font-medium">2027</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Info Anggaran Tim">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Plafon</dt>
                            <dd className="font-medium">Rp {fmt(73052500)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Sudah disetujui</dt>
                            <dd className="font-medium">Rp {fmt(65000000)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Sisa</dt>
                            <dd className="font-medium text-green-600">Rp {fmt(8052500)}</dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Daftar Perubahan">
                    <div className="space-y-4">
                        {pabd02aPerubahan.map((p) => {
                            const badge = tipeBadge[p.tipe];
                            return (
                                <div key={p.id} className="rounded-lg border-2 border-amber-400 dark:border-amber-500">
                                    <div className="flex items-center justify-between border-b px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <Badge className={badge.className}>{badge.label}</Badge>
                                            <span className="text-sm font-medium">Perubahan #{p.id}</span>
                                        </div>
                                        <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-destructive">
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <div className="space-y-4 px-4 py-3">
                                        {(p.tipe === 'tarik_maju' || p.tipe === 'tarik_mundur') && (() => {
                                            const selectedItem = getSelectedItem(p.tipe, p.selectedItemId);
                                            const availableItems = pabd02aAvailableItems[p.tipe];
                                            const tujuanOptions = getBulanTujuanOptions(p.tipe);
                                            return (
                                                <>
                                                    <div className="space-y-1.5">
                                                        <Label>Tipe Perubahan</Label>
                                                        <select
                                                            defaultValue={p.tipe}
                                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                        >
                                                            <option value="tarik_maju">Tarik Maju</option>
                                                            <option value="tarik_mundur">Tarik Mundur</option>
                                                        </select>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Item Anggaran</Label>
                                                        <select
                                                            defaultValue={p.selectedItemId}
                                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                        >
                                                            <option value="">-- Pilih item anggaran --</option>
                                                            {availableItems.map((item) => (
                                                                <option key={item.id} value={item.id}>
                                                                    {item.kode} | {item.mata} | Rp {fmt(item.nominal)} | {item.kegiatan} | {item.bulan}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <p className="text-xs text-muted-foreground">
                                                            {p.tipe === 'tarik_maju'
                                                                ? 'Item aktif dari bulan setelah Maret'
                                                                : 'Item aktif dari bulan Maret'}
                                                        </p>
                                                    </div>
                                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                                        <div className="space-y-1.5">
                                                            <Label>Bulan Awal</Label>
                                                            <div className="rounded-md border bg-muted/50 px-3 py-2 text-sm">
                                                                {selectedItem ? `${selectedItem.bulan} (${selectedItem.bulanNum})` : '-'}
                                                            </div>
                                                            <p className="text-xs text-muted-foreground">Otomatis dari item</p>
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label>Bulan Tujuan</Label>
                                                            <select
                                                                defaultValue={p.bulanTujuanNum}
                                                                className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                            >
                                                                <option value="">-- Pilih bulan --</option>
                                                                {tujuanOptions.map((b) => (
                                                                    <option key={b.value} value={b.value}>
                                                                        {b.label} ({b.value})
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label>Nominal</Label>
                                                            <div className="rounded-md border bg-muted/50 px-3 py-2 text-sm tabular-nums">
                                                                Rp {selectedItem ? fmt(selectedItem.nominal) : '-'}
                                                            </div>
                                                            <p className="text-xs text-muted-foreground">Tidak dapat diubah</p>
                                                        </div>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Alasan Perubahan *</Label>
                                                        <textarea
                                                            defaultValue={p.alasan}
                                                            rows={2}
                                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Lampiran (opsional)</Label>
                                                        <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs hover:bg-accent hover:text-accent-foreground">
                                                            <FileText className="h-4 w-4 text-muted-foreground" />
                                                            <span className="text-muted-foreground">Pilih file...</span>
                                                        </label>
                                                    </div>
                                                </>
                                            );
                                        })()}

                                        {p.tipe === 'penambahan' && (
                                            <>
                                                <div className="space-y-1.5">
                                                    <Label>Tipe Perubahan</Label>
                                                    <select
                                                        defaultValue="penambahan"
                                                        className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                    >
                                                        <option value="tarik_maju">Tarik Maju</option>
                                                        <option value="tarik_mundur">Tarik Mundur</option>
                                                        <option value="penambahan">Penambahan</option>
                                                    </select>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Nama Program *</Label>
                                                    <input
                                                        type="text"
                                                        defaultValue={p.program}
                                                        className="w-full rounded-md border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                    />
                                                </div>
                                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                    <div className="space-y-1.5">
                                                        <Label>Nama Kegiatan *</Label>
                                                        <input
                                                            type="text"
                                                            defaultValue={p.kegiatan}
                                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                        />
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Bulan</Label>
                                                        <div className="rounded-md border bg-muted/50 px-3 py-2 text-sm">
                                                            {getBulanLabel(p.bulanNum)} ({p.bulanNum})
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Anggaran</Label>
                                                    <table className="w-full text-sm">
                                                        <thead>
                                                            <tr className="border-b bg-muted/50">
                                                                <th className="px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                                <th className="px-3 py-2 text-left font-medium">Deskripsi</th>
                                                                <th className="px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr className="border-b">
                                                                <td className="px-3 py-2">{p.anggaran.mataAnggaran}</td>
                                                                <td className="px-3 py-2">{p.anggaran.deskripsi}</td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {fmt(p.anggaran.nominal)}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Alasan Perubahan *</Label>
                                                    <textarea
                                                        defaultValue={p.alasan}
                                                        rows={2}
                                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                    />
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Lampiran (wajib untuk penambahan)</Label>
                                                    <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                                        <span>{p.lampiran}</span>
                                                        <Button variant="link" size="sm" className="ml-auto h-auto p-0 text-xs">
                                                            <Download className="mr-1 h-3 w-3" />
                                                            Unduh
                                                        </Button>
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                            );
                        })}

                        <Button variant="outline" className="w-full">
                            + Tambah Perubahan
                        </Button>
                    </div>
                </SectionCard>

                <SectionCard title="Ringkasan Perubahan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Tarik Maju</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'tarik_maju').length} item — Rp{' '}
                                {fmt(pabd02aPerubahan.filter((p) => p.tipe === 'tarik_maju').reduce((s, p) => s + p.nominal, 0))}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tarik Mundur</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'tarik_mundur').length} item — Rp{' '}
                                {fmt(pabd02aPerubahan.filter((p) => p.tipe === 'tarik_mundur').reduce((s, p) => s + p.nominal, 0))}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Penambahan</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'penambahan').length} item
                                {pabd02aPerubahan.some((p) => p.tipe === 'penambahan') && (
                                    <span className="ml-1 text-xs text-amber-600 dark:text-amber-400">(perlu plafon baru)</span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="secondary">Simpan Draft</Button>}
                        title="Simpan Draft"
                        description="Data perubahan akan disimpan sebagai draft."
                        confirmLabel="Simpan"
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Submit</Button>}
                        title="Submit Perubahan"
                        description="Perubahan akan dikirim untuk disetujui. Pastikan data sudah benar."
                        confirmLabel="Submit"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

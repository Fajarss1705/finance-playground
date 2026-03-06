import { Head } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Heading from '@/components/heading';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { bulanOptions, pabd02aAvailableItems, pabd02aPerubahan, pabdHistory } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Anggaran Bulanan', href: '/admin/workflows/pabd' },
    { title: 'PABD-2027-KA-03', href: '/admin/workflows/pabd/pabd-uuid-001' },
    { title: 'Persetujuan Perubahan', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'active' as const },
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
    return pabd02aAvailableItems[tipe].find((i) => i.id === itemId);
}

export default function Pabd02b() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Persetujuan Perubahan Anggaran" />
            <div className="space-y-6 p-6">
                <Heading
                    title="Persetujuan Perubahan Anggaran"
                    description="Review perubahan anggaran bulan Maret 2027 — Divisi Pendidikan"
                />

                <StepProgress steps={steps} currentStep="PABD02B" />
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
                            <dd className="font-medium">22 Feb 2027, 11:00</dd>
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
                                <div key={p.id} className="rounded-lg border">
                                    <div className="flex items-center gap-2 border-b px-4 py-3">
                                        <Badge className={badge.className}>{badge.label}</Badge>
                                        <span className="text-sm font-medium">Perubahan #{p.id}</span>
                                    </div>
                                    <div className="space-y-4 px-4 py-3">
                                        {(p.tipe === 'tarik_maju' || p.tipe === 'tarik_mundur') && (() => {
                                            const selectedItem = getSelectedItem(p.tipe, p.selectedItemId);
                                            return (
                                                <>
                                                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                                        <div>
                                                            <dt className="text-muted-foreground">Item Anggaran</dt>
                                                            <dd className="font-medium">
                                                                {selectedItem
                                                                    ? <><KodeAnggaranDisplay kode={selectedItem.kode} /> | {selectedItem.mata} | {selectedItem.kegiatan}</>
                                                                    : '-'}
                                                            </dd>
                                                        </div>
                                                        <div>
                                                            <dt className="text-muted-foreground">Nominal</dt>
                                                            <dd className="font-medium">Rp {fmt(p.nominal)}</dd>
                                                        </div>
                                                        <div>
                                                            <dt className="text-muted-foreground">Bulan Awal</dt>
                                                            <dd className="font-medium">{getBulanLabel(p.bulanAwalNum)} ({p.bulanAwalNum})</dd>
                                                        </div>
                                                        <div>
                                                            <dt className="text-muted-foreground">Bulan Tujuan</dt>
                                                            <dd className="font-medium">{getBulanLabel(p.bulanTujuanNum)} ({p.bulanTujuanNum})</dd>
                                                        </div>
                                                    </dl>
                                                    <div>
                                                        <dt className="text-sm text-muted-foreground">Alasan Tim</dt>
                                                        <dd className="mt-1 rounded-md bg-muted/50 px-3 py-2 text-sm">{p.alasan}</dd>
                                                    </div>
                                                    <div className="space-y-1.5">
                                                        <Label>Catatan Approval</Label>
                                                        <textarea
                                                            placeholder="Tulis catatan untuk perubahan ini..."
                                                            rows={2}
                                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                        />
                                                    </div>
                                                </>
                                            );
                                        })()}

                                        {p.tipe === 'penambahan' && (
                                            <>
                                                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                                    <div>
                                                        <dt className="text-muted-foreground">Program</dt>
                                                        <dd className="font-medium">{p.program}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="text-muted-foreground">Kegiatan</dt>
                                                        <dd className="font-medium">{p.kegiatan}</dd>
                                                    </div>
                                                </dl>
                                                <div>
                                                    <dt className="mb-1 text-sm text-muted-foreground">Anggaran</dt>
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
                                                <div>
                                                    <dt className="text-sm text-muted-foreground">Alasan Tim</dt>
                                                    <dd className="mt-1 rounded-md bg-muted/50 px-3 py-2 text-sm">{p.alasan}</dd>
                                                </div>
                                                <div>
                                                    <dt className="text-sm text-muted-foreground">Lampiran</dt>
                                                    <dd className="mt-1 flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                                        <span>{p.lampiran}</span>
                                                        <Button
                                                            variant="link"
                                                            size="sm"
                                                            className="ml-auto h-auto p-0 text-xs"
                                                        >
                                                            <Download className="mr-1 h-3 w-3" />
                                                            Unduh
                                                        </Button>
                                                    </dd>
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label>Catatan Approval</Label>
                                                    <textarea
                                                        placeholder="Tulis catatan untuk perubahan ini..."
                                                        rows={2}
                                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                    />
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </SectionCard>

                <SectionCard title="Perubahan Plafon">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Plafon Saat Ini</dt>
                            <dd className="font-medium">Rp {fmt(73052500)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Total Terpakai</dt>
                            <dd className="font-medium">Rp {fmt(65000000)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Nominal Penambahan</dt>
                            <dd className="font-medium text-blue-600">Rp {fmt(2000000)}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Minimum Plafon Baru</dt>
                            <dd className="font-medium">Rp {fmt(67000000)}</dd>
                        </div>
                        <div className="space-y-1.5">
                            <dt className="text-muted-foreground">Plafon Baru</dt>
                            <dd>
                                <div className="flex items-center gap-1">
                                    <span className="text-sm">Rp</span>
                                    <input
                                        type="text"
                                        defaultValue="75.052.500"
                                        className="w-40 rounded-md border bg-background px-3 py-1.5 text-sm font-medium tabular-nums focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </div>
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Ringkasan Perubahan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Tarik Maju</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'tarik_maju').length} item
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tarik Mundur</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'tarik_mundur').length} item
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Penambahan</dt>
                            <dd className="font-medium">
                                {pabd02aPerubahan.filter((p) => p.tipe === 'penambahan').length} item
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="destructive">Tolak</Button>}
                        title="Tolak Perubahan"
                        description="Perubahan akan ditolak dan dikembalikan ke tim untuk revisi."
                        confirmLabel="Tolak"
                        variant="destructive"
                        requireNotes
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Setujui</Button>}
                        title="Setujui Perubahan"
                        description="Perubahan anggaran akan disetujui dan dilanjutkan ke verifikasi pencairan."
                        confirmLabel="Setujui"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

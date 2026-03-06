import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Heading from '@/components/heading';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import KodeAnggaranDisplay from '@/components/workflow/kode-anggaran-display';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { pabd01Anggaran, pabdHistory } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tim', href: '/team' },
    { title: 'Anggaran Bulanan', href: '/team/workflows/pabd' },
    { title: 'PABD-2027-KA-03', href: '#' },
    { title: 'Pengajuan', href: '#' },
];

const steps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'active' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'pending' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'pending' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'pending' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'pending' as const },
    { code: 'PABD05', label: 'Final', status: 'pending' as const },
];

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n);

const statusBadge: Record<string, { label: string; className: string }> = {
    active: { label: 'Aktif', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    tarik_maju: { label: 'Ditarik Maju', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    tarik_mundur: { label: 'Ditarik Mundur', className: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' },
};

// Group items by program > kegiatan
function groupAnggaran() {
    const groups: Record<string, { program: string; kode: string; kegiatan: Record<string, typeof pabd01Anggaran> }> = {};
    for (const item of pabd01Anggaran) {
        if (!groups[item.programKode]) {
            groups[item.programKode] = { program: item.program, kode: item.programKode, kegiatan: {} };
        }
        if (!groups[item.programKode].kegiatan[item.kegiatan]) {
            groups[item.programKode].kegiatan[item.kegiatan] = [];
        }
        groups[item.programKode].kegiatan[item.kegiatan].push(item);
    }
    return Object.values(groups);
}

export default function Pabd01() {
    const [checked, setChecked] = useState<Record<number, boolean>>(
        Object.fromEntries(pabd01Anggaran.map((item) => [item.id, item.checked])),
    );
    const [perubahanMode, setPerubahanMode] = useState<'none' | 'ada'>('ada');

    const grouped = groupAnggaran();

    const activeItems = pabd01Anggaran.filter((i) => i.statusItem === 'active');
    const checkedItems = activeItems.filter((i) => checked[i.id]);
    const uncheckedItems = activeItems.filter((i) => !checked[i.id]);
    const nonActiveItems = pabd01Anggaran.filter((i) => i.statusItem !== 'active');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pengajuan Anggaran Bulan Depan" />
            <div className="space-y-6 p-6">
                <Heading
                    title="Pengajuan Anggaran Bulan Depan"
                    description="Review dan konfirmasi anggaran bulan Maret 2027 — Divisi Pendidikan"
                />

                <StepProgress steps={steps} currentStep="PABD01" />
                <HistoryTimeline entries={pabdHistory} />

                <SectionCard title="Daftar Anggaran">
                    <div className="space-y-6">
                        {grouped.map((group) => (
                            <div key={group.kode}>
                                <h4 className="mb-2 text-sm font-semibold text-foreground">
                                    {group.kode} — {group.program}
                                </h4>
                                {Object.entries(group.kegiatan).map(([kegiatanName, items]) => (
                                    <div key={kegiatanName} className="mb-3 ml-2">
                                        <p className="mb-1 text-xs font-medium text-muted-foreground">{kegiatanName}</p>
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b bg-muted/50">
                                                    <th className="w-10 px-3 py-2"></th>
                                                    <th className="px-3 py-2 text-left font-medium">Kode Anggaran</th>
                                                    <th className="px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                    <th className="px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                    <th className="px-3 py-2 text-left font-medium">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {items.map((item) => {
                                                    const isActive = item.statusItem === 'active';
                                                    const badge = statusBadge[item.statusItem];
                                                    return (
                                                        <tr key={item.id} className="border-b last:border-0">
                                                            <td className="px-3 py-2 text-center">
                                                                {isActive ? (
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={checked[item.id] ?? false}
                                                                        onChange={() =>
                                                                            setChecked((prev) => ({
                                                                                ...prev,
                                                                                [item.id]: !prev[item.id],
                                                                            }))
                                                                        }
                                                                        className="h-4 w-4 rounded border-gray-300"
                                                                    />
                                                                ) : (
                                                                    <input
                                                                        type="checkbox"
                                                                        disabled
                                                                        checked={false}
                                                                        className="h-4 w-4 rounded border-gray-300 opacity-40"
                                                                    />
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2"><KodeAnggaranDisplay kode={item.kodeAnggaran} /></td>
                                                            <td className="px-3 py-2">{item.mataAnggaran}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums">
                                                                {fmt(item.nominal)}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {badge && <Badge className={badge.className}>{badge.label}</Badge>}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </SectionCard>

                <SectionCard title="Ringkasan Pencairan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Total item</dt>
                            <dd className="font-medium">{pabd01Anggaran.length}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Akan dicairkan</dt>
                            <dd className="font-medium">
                                {checkedItems.length} item — Rp {fmt(checkedItems.reduce((sum, i) => sum + i.nominal, 0))}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tidak dicairkan</dt>
                            <dd className="font-medium">
                                {uncheckedItems.length} item — Rp {fmt(uncheckedItems.reduce((sum, i) => sum + i.nominal, 0))}{' '}
                                <span className="text-xs text-muted-foreground">(hangus)</span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Item ditarik</dt>
                            <dd className="font-medium">
                                {nonActiveItems.length} item — Rp 0{' '}
                                <span className="text-xs text-muted-foreground">(sudah dipindahkan)</span>
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title="Perubahan Anggaran">
                    <div className="space-y-3">
                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="radio"
                                name="perubahan"
                                checked={perubahanMode === 'none'}
                                onChange={() => setPerubahanMode('none')}
                                className="h-4 w-4"
                            />
                            <div>
                                <p className="text-sm font-medium">Tidak ada perubahan</p>
                                <p className="text-xs text-muted-foreground">Langsung ke verifikasi</p>
                            </div>
                        </label>
                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="radio"
                                name="perubahan"
                                checked={perubahanMode === 'ada'}
                                onChange={() => setPerubahanMode('ada')}
                                className="h-4 w-4"
                            />
                            <div>
                                <p className="text-sm font-medium">Ada perubahan</p>
                                <p className="text-xs text-muted-foreground">Ajukan tarik atau penambahan</p>
                            </div>
                        </label>
                    </div>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <ActionConfirmDialog
                        trigger={<Button variant="secondary">Simpan Draft</Button>}
                        title="Simpan Draft"
                        description="Data pengajuan akan disimpan sebagai draft."
                        confirmLabel="Simpan"
                    />
                    <ActionConfirmDialog
                        trigger={<Button>Submit</Button>}
                        title="Submit Pengajuan"
                        description="Pengajuan akan dikirim untuk diproses. Pastikan data sudah benar."
                        confirmLabel="Submit"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

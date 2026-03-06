import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
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
import { prbl01Kegiatan, prbl03RefundData, prblHistory } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const workflowKode = 'PRBL-2027-KA-03';
const workflowUuid = 'prbl-uuid-001';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tim', href: '/team' },
    { title: 'Laporan Bulanan', href: '/team/workflows/prbl' },
    { title: workflowKode, href: `/team/workflows/prbl/${workflowUuid}` },
    { title: 'Laporan Bulanan', href: '#' },
];

const steps = [
    { code: 'PRBL01', label: 'Laporan', status: 'active' as const },
    { code: 'PRBL02A', label: 'Narasi', status: 'pending' as const },
    { code: 'PRBL02B', label: 'Anggaran', status: 'pending' as const },
    { code: 'PRBL03', label: 'Refund', status: 'pending' as const },
    { code: 'PRBL04', label: 'Review', status: 'pending' as const },
    { code: 'PRBL05', label: 'Final', status: 'pending' as const },
];

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('id-ID').format(amount);
}

type KegiatanFormData = {
    masalah: string;
    langkah: string;
    harapan: string;
    catatan: string;
    kuisioner: { id: number; jawaban: string }[];
    realisasi: { kode: string; realisasi: number; komentar: string }[];
};

export default function TeamPrbl01() {
    const [formData, setFormData] = useState<Record<number, KegiatanFormData>>(() => {
        const initial: Record<number, KegiatanFormData> = {};
        for (const k of prbl01Kegiatan) {
            initial[k.id] = {
                masalah: k.masalah,
                langkah: k.langkah,
                harapan: k.harapan,
                catatan: k.catatan,
                kuisioner: k.kuisioner.map((q) => ({ id: q.id, jawaban: q.jawaban })),
                realisasi: k.realisasi.map((r) => ({ kode: r.kode, realisasi: r.realisasi, komentar: r.komentar })),
            };
        }
        return initial;
    });

    function updateField(kegiatanId: number, field: keyof Pick<KegiatanFormData, 'masalah' | 'langkah' | 'harapan' | 'catatan'>, value: string): void {
        setFormData((prev) => ({
            ...prev,
            [kegiatanId]: { ...prev[kegiatanId], [field]: value },
        }));
    }

    function updateKuisioner(kegiatanId: number, questionId: number, value: string): void {
        setFormData((prev) => ({
            ...prev,
            [kegiatanId]: {
                ...prev[kegiatanId],
                kuisioner: prev[kegiatanId].kuisioner.map((q) => (q.id === questionId ? { ...q, jawaban: value } : q)),
            },
        }));
    }

    function updateRealisasi(kegiatanId: number, kode: string, field: 'realisasi' | 'komentar', value: string): void {
        setFormData((prev) => ({
            ...prev,
            [kegiatanId]: {
                ...prev[kegiatanId],
                realisasi: prev[kegiatanId].realisasi.map((r) =>
                    r.kode === kode ? { ...r, [field]: field === 'realisasi' ? Number(value) || 0 : value } : r,
                ),
            },
        }));
    }

    const totalDicairkan = prbl01Kegiatan.reduce((sum, k) => sum + k.subtotalDicairkan, 0);
    const totalRealisasi = prbl01Kegiatan.reduce((sum, k) => {
        const kForm = formData[k.id];
        return sum + k.realisasi.reduce((s, r) => {
            const formR = kForm?.realisasi.find((fr) => fr.kode === r.kode);
            return s + (formR?.realisasi ?? r.realisasi);
        }, 0);
    }, 0);
    const totalSelisih = totalDicairkan - totalRealisasi;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL01 — Laporan Bulanan" />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between">
                    <Heading title="Laporan Bulanan" description="Laporkan kegiatan dan realisasi anggaran bulan Maret 2027" />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/team/workflows/prbl/${workflowUuid}`}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StepProgress steps={steps} currentStep="PRBL01" />

                <HistoryTimeline entries={prblHistory.slice(0, 3)} />

                <SectionCard title="Info Laporan">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tim</span>
                            <span className="font-medium">Divisi Pendidikan</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Bulan Laporan</span>
                            <span className="font-medium">Maret 2027 (3)</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Periode</span>
                            <span className="font-medium">2027</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Dibuat otomatis</span>
                            <span className="font-medium">16 Maret 2027</span>
                        </div>
                    </div>
                </SectionCard>

                {prbl01Kegiatan.map((kegiatan, idx) => {
                    const kForm = formData[kegiatan.id];
                    const subtotalRealisasi = kegiatan.realisasi.reduce((s, r) => {
                        const formR = kForm?.realisasi.find((fr) => fr.kode === r.kode);
                        return s + (formR?.realisasi ?? r.realisasi);
                    }, 0);

                    return (
                        <SectionCard key={kegiatan.id} title={`Kegiatan ${idx + 1} / ${prbl01Kegiatan.length}`}>
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
                                    <div className="space-y-1.5">
                                        <Label>Masalah/Kendala</Label>
                                        <textarea
                                            value={kForm?.masalah ?? ''}
                                            onChange={(e) => updateField(kegiatan.id, 'masalah', e.target.value)}
                                            rows={2}
                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            placeholder="Masalah atau kendala yang dihadapi..."
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Langkah Penanganan</Label>
                                        <textarea
                                            value={kForm?.langkah ?? ''}
                                            onChange={(e) => updateField(kegiatan.id, 'langkah', e.target.value)}
                                            rows={2}
                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            placeholder="Langkah yang dilakukan..."
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Harapan</Label>
                                        <textarea
                                            value={kForm?.harapan ?? ''}
                                            onChange={(e) => updateField(kegiatan.id, 'harapan', e.target.value)}
                                            rows={2}
                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            placeholder="Harapan ke depan..."
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Catatan Tim</Label>
                                        <textarea
                                            value={kForm?.catatan ?? ''}
                                            onChange={(e) => updateField(kegiatan.id, 'catatan', e.target.value)}
                                            rows={2}
                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            placeholder="Catatan tambahan dari tim..."
                                        />
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
                                                        <td className="px-2 py-2">
                                                            <input
                                                                type="text"
                                                                value={kForm?.kuisioner.find((kq) => kq.id === q.id)?.jawaban ?? ''}
                                                                onChange={(e) => updateKuisioner(kegiatan.id, q.id, e.target.value)}
                                                                className="w-full rounded-md border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                            />
                                                        </td>
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
                                                    <th className="px-2 py-2 font-medium">Kode Ang.</th>
                                                    <th className="px-2 py-2 font-medium">Mata Ang.</th>
                                                    <th className="px-2 py-2 text-right font-medium">Dicairkan (Rp)</th>
                                                    <th className="px-2 py-2 text-right font-medium">Realisasi (Rp)</th>
                                                    <th className="px-2 py-2 font-medium">Komentar</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {kegiatan.realisasi.map((r) => {
                                                    const formR = kForm?.realisasi.find((fr) => fr.kode === r.kode);
                                                    return (
                                                        <tr key={r.kode} className="border-b">
                                                            <td className="px-2 py-2"><KodeAnggaranDisplay kode={r.kode} /></td>
                                                            <td className="px-2 py-2">{r.mata}</td>
                                                            <td className="px-2 py-2 text-right text-muted-foreground">
                                                                {formatCurrency(r.dicairkan)}
                                                            </td>
                                                            <td className="px-2 py-2 text-right">
                                                                <input
                                                                    type="number"
                                                                    value={formR?.realisasi ?? 0}
                                                                    onChange={(e) => updateRealisasi(kegiatan.id, r.kode, 'realisasi', e.target.value)}
                                                                    className="w-28 rounded-md border bg-background px-2 py-1 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                                />
                                                            </td>
                                                            <td className="px-2 py-2">
                                                                <input
                                                                    type="text"
                                                                    value={formR?.komentar ?? ''}
                                                                    onChange={(e) => updateRealisasi(kegiatan.id, r.kode, 'komentar', e.target.value)}
                                                                    className="w-full rounded-md border bg-background px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                                />
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                            <tfoot>
                                                <tr className="border-t font-medium">
                                                    <td colSpan={2} className="px-2 py-2">
                                                        Subtotal
                                                    </td>
                                                    <td className="px-2 py-2 text-right">{formatCurrency(kegiatan.subtotalDicairkan)}</td>
                                                    <td className="px-2 py-2 text-right">{formatCurrency(subtotalRealisasi)}</td>
                                                    <td className="px-2 py-2 text-muted-foreground">
                                                        Selisih: Rp {formatCurrency(kegiatan.subtotalDicairkan - subtotalRealisasi)}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </SectionCard>
                    );
                })}

                <SectionCard title="Ringkasan Realisasi">
                    <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Dicairkan</span>
                            <span className="font-medium">Rp {formatCurrency(totalDicairkan)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Realisasi</span>
                            <span className="font-medium">Rp {formatCurrency(totalRealisasi)}</span>
                        </div>
                        <Separator />
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Selisih</span>
                            <span className="font-medium">Rp {formatCurrency(totalSelisih)} (akan di-refund di PRBL03)</span>
                        </div>
                    </div>
                </SectionCard>

                <div className="flex justify-end gap-3">
                    <Button variant="secondary">Simpan Draft</Button>
                    <ActionConfirmDialog
                        trigger={<Button>Submit</Button>}
                        title="Submit Laporan Bulanan?"
                        description="Laporan akan dikirim untuk persetujuan narasi (PRBL02A) dan anggaran (PRBL02B)."
                        confirmLabel="Submit"
                    />
                </div>
            </div>
        </AppLayout>
    );
}

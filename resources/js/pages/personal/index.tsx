import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { index as personalIndex } from '@/routes/personal';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Personal', href: personalIndex() },
];

const workflows = [
    {
        title: 'PP — Perencanaan Periode',
        description: 'Workflow tahunan untuk menetapkan jadwal, kode pelayanan, kuisioner, plafon anggaran, dan dokumen SOP.',
        steps: [
            { code: 'PP01', name: 'Jadwal & Kode Pelayanan', type: 'Form', role: 'Monev + Bendahara Umum', team: 'Tim Monev / Tim Bendahara Umum' },
            { code: 'PP02', name: 'Kuisioner Evaluasi', type: 'Form', role: 'Monev', team: 'Tim Monev' },
            { code: 'PP03', name: 'Plafon Anggaran Tim', type: 'Form', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PP04', name: 'Dokumen SOP', type: 'Form', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PP05', name: 'Persetujuan', type: 'Approval', role: 'Monev (spesifik)', team: 'Tim Monev' },
            { code: 'PP06', name: 'Kompilasi Final', type: 'Final', role: 'Sistem', team: '—' },
            { code: 'PP07', name: 'Revisi', type: 'Revision', role: 'Monev + Bendahara Umum', team: 'Tim Monev / Tim Bendahara Umum' },
        ],
        pageLinks: [],
    },
    {
        title: 'PK — Perencanaan Kegiatan dan Anggaran',
        description: 'Workflow per program — 1 PK = 1 program. Tim mengajukan program + kegiatan + anggaran, di-review paralel.',
        steps: [
            { code: 'PK01', name: 'Input Program & Kegiatan', type: 'Form', role: 'Semua role tim', team: 'Divisi (masing-masing)' },
            { code: 'PK02A', name: 'Review Narasi', type: 'Approval (paralel)', role: 'Monev', team: 'Tim Monev' },
            { code: 'PK02B', name: 'Review Anggaran', type: 'Approval (paralel)', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PK03', name: 'Persetujuan RAKER', type: 'Approval', role: 'BU / Monev', team: 'Tim BU / Tim Monev' },
            { code: 'PK04', name: 'Kompilasi Final', type: 'Final', role: 'Sistem', team: '—' },
            { code: 'PK05', name: 'Revisi', type: 'Revision', role: 'BU / Monev', team: 'Tim BU / Tim Monev' },
        ],
        pageLinks: [],
    },
    {
        title: 'PABD — Pengajuan Anggaran Bulan Depan',
        description: 'Workflow bulanan (auto-created). Konfirmasi anggaran, opsional perubahan (tarik/penambahan), pencairan.',
        steps: [
            { code: 'PABD01', name: 'Konfirmasi Anggaran', type: 'Form (auto)', role: 'Semua role tim', team: 'Divisi (masing-masing)' },
            { code: 'PABD02A', name: 'Permohonan Perubahan', type: 'Form (kondisional)', role: 'Semua role tim', team: 'Divisi (masing-masing)' },
            { code: 'PABD02B', name: 'Persetujuan Perubahan', type: 'Approval', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PABD03', name: 'Persetujuan Pencairan', type: 'Approval', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PABD04', name: 'Upload Bukti Transfer', type: 'Form (auto)', role: 'Kantor Pusat', team: 'Tim Kantor Pusat' },
            { code: 'PABD05', name: 'Kompilasi Final', type: 'Final', role: 'Sistem', team: '—' },
        ],
        pageLinks: [
            { label: 'Index (Tim)', href: '/team/workflows/pabd' },
            { label: 'Index (Admin)', href: '/admin/workflows/pabd' },
        ],
    },
    {
        title: 'PRBL — Pelaporan dan Refund Bulan Lalu',
        description: 'Workflow bulanan (auto-created). Tim lapor realisasi + kuisioner, di-review paralel, refund selisih.',
        steps: [
            { code: 'PRBL01', name: 'Laporan Realisasi', type: 'Form (auto)', role: 'Semua role tim', team: 'Divisi (masing-masing)' },
            { code: 'PRBL02A', name: 'Review Narasi', type: 'Approval (paralel)', role: 'Monev', team: 'Tim Monev' },
            { code: 'PRBL02B', name: 'Review Anggaran', type: 'Approval (paralel)', role: 'Bendahara Umum', team: 'Tim Bendahara Umum' },
            { code: 'PRBL03', name: 'Refund & Bukti Transfer', type: 'Form', role: 'Semua role tim', team: 'Divisi (masing-masing)' },
            { code: 'PRBL04', name: 'Review Akhir', type: 'Approval', role: 'BU / Monev', team: 'Tim BU / Tim Monev' },
            { code: 'PRBL05', name: 'Kompilasi Final', type: 'Final', role: 'Sistem', team: '—' },
        ],
        pageLinks: [
            { label: 'Index (Tim)', href: '/team/workflows/prbl' },
            { label: 'Index (Admin)', href: '/admin/workflows/prbl' },
        ],
    },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Personal" />
            <div className="space-y-6 p-6">
                <Heading title="Personal" description="Dashboard personal" />

                <SectionCard title="Daftar Workflow">
                    <div className="space-y-6">
                        {workflows.map((wf) => (
                            <div key={wf.title}>
                                <h3 className="text-sm font-semibold">{wf.title}</h3>
                                <p className="mb-2 text-xs text-muted-foreground">{wf.description}</p>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="px-3 py-1.5 text-left text-xs font-medium">Kode</th>
                                                <th className="px-3 py-1.5 text-left text-xs font-medium">Nama Step</th>
                                                <th className="px-3 py-1.5 text-left text-xs font-medium">Tipe</th>
                                                <th className="px-3 py-1.5 text-left text-xs font-medium">Aktor</th>
                                                <th className="px-3 py-1.5 text-left text-xs font-medium">Tim</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {wf.steps.map((step) => (
                                                <tr key={step.code} className="border-b last:border-0">
                                                    <td className="px-3 py-1.5 font-mono text-xs">
                                                        {'demo' in step && step.demo ? (
                                                            <Link href={step.demo} className="text-primary underline underline-offset-2 hover:text-primary/80">
                                                                {step.code}
                                                            </Link>
                                                        ) : (
                                                            step.code
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-1.5">{step.name}</td>
                                                    <td className="px-3 py-1.5 whitespace-nowrap text-muted-foreground">{step.type}</td>
                                                    <td className="px-3 py-1.5">{step.role}</td>
                                                    <td className="px-3 py-1.5 text-muted-foreground">{step.team}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {wf.pageLinks.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {wf.pageLinks.map((link) => (
                                            <Link
                                                key={link.href}
                                                href={link.href}
                                                className="rounded border px-2 py-0.5 text-xs hover:bg-muted/50"
                                            >
                                                {link.label}
                                            </Link>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </SectionCard>
            </div>
        </AppLayout>
    );
}

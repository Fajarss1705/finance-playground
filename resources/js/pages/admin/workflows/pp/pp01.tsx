import { Head, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateInput } from '@/components/ui/date-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };

function nextKode(prefix: string, existing: { kode: string }[]): string {
    const nums = existing
        .map((item) => {
            const m = item.kode.match(new RegExp(`^${prefix}(\\d+)$`));
            return m ? parseInt(m[1], 10) : 0;
        })
        .filter((n) => n > 0);
    const next = nums.length > 0 ? Math.max(...nums) + 1 : 1;
    return `${prefix}${String(next).padStart(2, '0')}`;
}

type StepData = {
    id: number;
    tahun: number | null;
    tanggal_mulai_pra_raker: string | null;
    tanggal_penetapan_program: string | null;
    kode_bidang_pelayanan: KodeItem[];
    kode_sub_bidang_pelayanan: KodeItem[];
    kode_kategori_pelayanan: KodeItem[];
    kode_jenis_program: KodeItem[];
    updated_at: string;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
};

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    canComment: boolean;
    isRejectionReentry: boolean;
    rejectionNotes: { notes: string; by: string | null; at: string | null } | null;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

export default function Pp01({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, canComment, isRejectionReentry, rejectionNotes, actionRoles, activeRoleName }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);

    const [tahun, setTahun] = useState<number | string>(stepData.tahun ?? '');
    const [tanggalMulai, setTanggalMulai] = useState(stepData.tanggal_mulai_pra_raker ?? '');
    const [tanggalPenetapan, setTanggalPenetapan] = useState(stepData.tanggal_penetapan_program ?? '');
    const [kodeBidang, setKodeBidang] = useState<KodeItem[]>(stepData.kode_bidang_pelayanan.length > 0 ? stepData.kode_bidang_pelayanan : [{ kode: 'B01', nama: 'B01', catatan: '' }]);
    const [kodeSubBidang, setKodeSubBidang] = useState<KodeItem[]>(stepData.kode_sub_bidang_pelayanan.length > 0 ? stepData.kode_sub_bidang_pelayanan : [{ kode: 'SB01', nama: 'SB01', catatan: '' }]);
    const [kodeKategori, setKodeKategori] = useState<KodeItem[]>(stepData.kode_kategori_pelayanan.length > 0 ? stepData.kode_kategori_pelayanan : [{ kode: 'K01', nama: 'K01', catatan: '' }]);
    const [kodeJenis, setKodeJenis] = useState<KodeItem[]>(stepData.kode_jenis_program.length > 0 ? stepData.kode_jenis_program : [{ kode: 'J01', nama: 'J01', catatan: '' }]);

    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP01: Rencana Periode', href: '#' },
    ];

    function buildFormData(notes: string, files: File[]) {
        return {
            tahun,
            tanggal_mulai_pra_raker: tanggalMulai,
            tanggal_penetapan_program: tanggalPenetapan,
            kode_bidang_pelayanan: kodeBidang,
            kode_sub_bidang_pelayanan: kodeSubBidang,
            kode_kategori_pelayanan: kodeKategori,
            kode_jenis_program: kodeJenis,
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
            ...(files.length > 0 ? { files } : {}),
        };
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `/admin/workflows/pp/${workflow.id}/pp01/${stepData.id}/${action}`;
        router.post(url, buildFormData(notes, files), {
            forceFormData: files.length > 0,
            onFinish: () => setProcessing(false),
        });
    }

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05') return `/admin/workflows/pp/${workflow.id}/pp05`;
        if (step === 'PP06') return `/admin/workflows/pp/${workflow.id}/pp06${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
        if (entry.id && entry.table) {
            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
        }
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP01: Rencana Periode — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP01: Rencana Periode" description="Tentukan tahun perencanaan, jadwal, dan kode-kode referensi" />
                    <StepStatusBadge mode={mode} />
                </div>

                {mode === 'readonly' && (
                    <div className="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
                        Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                    </div>
                )}

                {isPermissionLocked && (
                    <div className="rounded-md border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-200">
                        Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        <p className="font-medium">Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.</p>
                        {rejectionNotes && (
                            <div className="mt-2 rounded border border-amber-200 bg-amber-100/50 px-3 py-2 dark:border-amber-600 dark:bg-amber-900/30">
                                <p className="text-xs text-amber-600 dark:text-amber-400">
                                    Catatan penolakan{rejectionNotes.by ? ` dari ${rejectionNotes.by}` : ''}:
                                </p>
                                <p className="mt-0.5">{rejectionNotes.notes}</p>
                            </div>
                        )}
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}
                {errors.tahun && <AlertError errors={[errors.tahun]} title="Validasi gagal" />}

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp01"
                    canComment={canComment}
                    finalSteps={['PP06']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Informasi Periode">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Tahun Periode <span className="text-destructive">*</span></Label>
                            <Input
                                type="number"
                                value={tahun}
                                onChange={(e) => setTahun(e.target.value === '' ? '' : Number(e.target.value))}
                                disabled={isReadonly}
                                min={2020}
                                max={2099}
                            />
                            {errors.tahun && <p className="text-xs text-destructive">{errors.tahun}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Mulai Pra-Raker <span className="text-destructive">*</span></Label>
                            <DateInput
                                value={tanggalMulai}
                                onChange={setTanggalMulai}
                                disabled={isReadonly}
                            />
                            {errors.tanggal_mulai_pra_raker && <p className="text-xs text-destructive">{errors.tanggal_mulai_pra_raker}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Penetapan Program <span className="text-destructive">*</span></Label>
                            <DateInput
                                value={tanggalPenetapan}
                                onChange={setTanggalPenetapan}
                                disabled={isReadonly}
                            />
                            {errors.tanggal_penetapan_program && <p className="text-xs text-destructive">{errors.tanggal_penetapan_program}</p>}
                        </div>
                    </div>
                </SectionCard>

                <KodeTable
                    title="Kode Bidang Pelayanan"
                    addLabel="Tambah Bidang Pelayanan"
                    items={kodeBidang}
                    onChange={setKodeBidang}
                    disabled={isReadonly}
                    errorPrefix="kode_bidang_pelayanan"
                    errors={errors}
                    kodePrefix="B"
                />

                <KodeTable
                    title="Kode Sub Bidang Pelayanan"
                    addLabel="Tambah Sub Bidang Pelayanan"
                    items={kodeSubBidang}
                    onChange={setKodeSubBidang}
                    disabled={isReadonly}
                    errorPrefix="kode_sub_bidang_pelayanan"
                    errors={errors}
                    kodePrefix="SB"
                />

                <KodeTable
                    title="Kode Kategori Pelayanan"
                    addLabel="Tambah Kategori Pelayanan"
                    items={kodeKategori}
                    onChange={setKodeKategori}
                    disabled={isReadonly}
                    errorPrefix="kode_kategori_pelayanan"
                    errors={errors}
                    kodePrefix="K"
                />

                <KodeTable
                    title="Kode Jenis Program"
                    addLabel="Tambah Jenis Program"
                    items={kodeJenis}
                    onChange={setKodeJenis}
                    disabled={isReadonly}
                    errorPrefix="kode_jenis_program"
                    errors={errors}
                    kodePrefix="J"
                />

                {((!isReadonly && (canDraft || canSubmit)) || canTerminate) && (
                    <div className="flex gap-2">
                        {!isReadonly && canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="outline" disabled={processing}>
                                        {processing ? 'Menyimpan...' : 'Simpan Draft'}
                                    </Button>
                                }
                                title="Simpan Draft"
                                description="Simpan data sebagai draft. Data belum divalidasi dan bisa diubah kembali."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {!isReadonly && canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing}>
                                        {processing ? 'Mengirim...' : 'Submit'}
                                    </Button>
                                }
                                title="Submit PP01"
                                description="Data akan divalidasi dan dikunci. Step selanjutnya (PP02) akan diaktifkan."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                        {canTerminate && (
                            <TerminateButton workflowId={workflow.id} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function StepStatusBadge({ mode }: { mode: string }) {
    const config: Record<string, { label: string; className: string }> = {
        readonly: { label: 'Sudah Disubmit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        edit: { label: 'Draft', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
        create: { label: 'Menunggu Diisi', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    };

    const c = config[mode] ?? config.create;
    return <Badge className={c.className}>{c.label}</Badge>;
}

function TerminateButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);

    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="outline" className="ml-auto text-destructive border-destructive hover:bg-destructive/10">
                    Batalkan Workflow
                </Button>
            }
            title="Batalkan Workflow"
            description="Workflow yang dibatalkan tidak bisa dilanjutkan. Tulis alasan pembatalan."
            confirmLabel="Batalkan Workflow"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function KodeTable({
    title,
    addLabel,
    items,
    onChange,
    disabled,
    errorPrefix,
    errors,
    kodePrefix,
}: {
    title: string;
    addLabel: string;
    items: KodeItem[];
    onChange: (items: KodeItem[]) => void;
    disabled: boolean;
    errorPrefix: string;
    errors: Record<string, string>;
    kodePrefix: string;
}) {
    function addRow() {
        const kode = nextKode(kodePrefix, items);
        onChange([...items, { kode, nama: kode, catatan: '' }]);
    }

    function removeRow(index: number) {
        onChange(items.filter((_, i) => i !== index));
    }

    function updateRow(index: number, field: keyof KodeItem, value: string) {
        const updated = items.map((item, i) => (i === index ? { ...item, [field]: value } : item));
        onChange(updated);
    }

    const tableError = errors[errorPrefix];

    return (
        <SectionCard title={title}>
            {tableError && <p className="mb-2 text-xs text-destructive">{tableError}</p>}
            <div className="overflow-x-auto">
                <table className="min-w-200 w-full text-sm">
                    <thead>
                        <tr className="border-b bg-muted/50">
                            <th className="px-3 py-2 text-left font-medium w-28">Kode <span className="text-destructive">*</span></th>
                            {!disabled && <th className="px-3 py-2 w-12" />}
                            <th className="px-3 py-2 text-left font-medium min-w-48">Nama <span className="text-destructive">*</span></th>
                            <th className="px-3 py-2 text-left font-medium min-w-64">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item, i) => (
                            <tr key={i} className="border-b last:border-0">
                                <td className="px-3 py-1.5">
                                    <Input
                                        value={item.kode}
                                        onChange={(e) => updateRow(i, 'kode', e.target.value)}
                                        disabled={disabled}
                                        className="h-8"
                                        maxLength={10}
                                    />
                                    {errors[`${errorPrefix}.${i}.kode`] && <p className="text-xs text-destructive">{errors[`${errorPrefix}.${i}.kode`]}</p>}
                                </td>
                                {!disabled && (
                                    <td className="px-3 py-1.5">
                                        <Button variant="ghost" size="sm" onClick={() => removeRow(i)} className="h-8 w-8 p-0">
                                            <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                        </Button>
                                    </td>
                                )}
                                <td className="px-3 py-1.5">
                                    <Input
                                        value={item.nama}
                                        onChange={(e) => updateRow(i, 'nama', e.target.value)}
                                        disabled={disabled}
                                        className="h-8"
                                    />
                                    {errors[`${errorPrefix}.${i}.nama`] && <p className="text-xs text-destructive">{errors[`${errorPrefix}.${i}.nama`]}</p>}
                                </td>
                                <td className="px-3 py-1.5 align-top">
                                    <Textarea
                                        value={item.catatan ?? ''}
                                        onChange={(e) => updateRow(i, 'catatan', e.target.value)}
                                        disabled={disabled}
                                        rows={1}
                                        className="min-h-8 resize-y"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {!disabled && (
                <Button variant="outline" size="sm" onClick={addRow} className="mt-2">
                    <Plus className="mr-1 h-3.5 w-3.5" />
                    {addLabel}
                </Button>
            )}
        </SectionCard>
    );
}

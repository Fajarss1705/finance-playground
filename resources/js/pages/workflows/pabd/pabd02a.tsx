import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Info, CheckCircle2, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RupiahInput } from '@/components/ui/rupiah-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type KodeRef = { kode: string; nama: string };
type KuisionerTemplate = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };

type AnggaranPickerItem = {
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    mata_anggaran: string;
    nominal: number;
    disabled: boolean;
};

type KegiatanPickerGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: AnggaranPickerItem[];
};

type ProgramPickerGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    kegiatan: KegiatanPickerGroup[];
};

// PABD01 checklist types (reused from pabd01)
type AnggaranItem = {
    pabd01_item_id: number;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    mata_anggaran: string;
    nominal: number;
    status_item: string;
    status_label: string | null;
    dicairkan: boolean;
};

type KegiatanGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: AnggaranItem[];
};

type ProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    tipe: string;
    kegiatan: KegiatanGroup[];
};

// Proposal form types
type ProposalAnggaran = {
    kode_bidang: string;
    kode_sub_bidang: string;
    kode_jenis: string;
    mata_anggaran: string;
    deskripsi_pk: string;
    nominal_anggaran: number;
};

type ProposalKuisioner = {
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string;
};

type ProposalKegiatan = {
    nama_kegiatan: string;
    bulan: number | null;
    anggaran: ProposalAnggaran[];
    kuisioner: ProposalKuisioner[];
};

type ProposalData = {
    kode_kategori: string;
    nama_program: string;
    deskripsi_program: string;
    tujuan_program: string;
    kegiatan: ProposalKegiatan[];
};

// Change item
type ChangeItem = {
    tipe_perubahan: 'tarik_maju' | 'proposal_baru' | '';
    pk04_anggaran_id: number | null;
    bulan_awal: number | null;
    bulan_tujuan: number | null;
    pk_workflow_id: number | null;
    komentar: string;
    item_files: File[];
    existing_files: { id: number; name: string; url: string | null }[];
    proposal: ProposalData;
    // Enriched display data (from server for existing items)
    anggaran_detail?: {
        program_name: string;
        kode_kategori: string;
        kegiatan_name: string;
        bulan: number;
        bulan_label: string;
        kode_anggaran_baru: string | null;
        mata_anggaran: string;
        nominal: number;
    };
};

type Submitter = { name: string; role: string; team: string; at: string };

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    stepper_cycles: unknown[];
};

type WorkflowMeta = {
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
    team_name: string;
};

type StepData = {
    id: number;
    items: Array<Record<string, unknown>>;
    updated_at: string;
};

type Props = {
    workflow: Workflow;
    stepData: StepData;
    pabd01ChecklistData: ProgramGroup[];
    pabd01Submitter: Submitter | null;
    futureAnggaranItems: ProgramPickerGroup[];
    kodeRefs: {
        kategori: KodeRef[];
        bidang: KodeRef[];
        subBidang: KodeRef[];
        jenis: KodeRef[];
        kuisioner: KuisionerTemplate[];
    };
    budgetCounter: BudgetCounterData;
    workflowMeta: WorkflowMeta;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    teamName: string;
    scope: 'team' | 'admin';
    basePath: string;
};

// ─── Helpers ───────────────────────────────────────────

const BULAN_NAMES: Record<number, string> = {
    1: 'Januari', 2: 'Februari', 3: 'Maret', 4: 'April',
    5: 'Mei', 6: 'Juni', 7: 'Juli', 8: 'Agustus',
    9: 'September', 10: 'Oktober', 11: 'November', 12: 'Desember',
};

function emptyProposal(): ProposalData {
    return {
        kode_kategori: '',
        nama_program: '',
        deskripsi_program: '',
        tujuan_program: '',
        kegiatan: [emptyProposalKegiatan()],
    };
}

function emptyProposalKegiatan(): ProposalKegiatan {
    return {
        nama_kegiatan: '',
        bulan: null,
        anggaran: [emptyProposalAnggaran()],
        kuisioner: [emptyProposalKuisioner()],
    };
}

function emptyProposalAnggaran(): ProposalAnggaran {
    return { kode_bidang: '', kode_sub_bidang: '', kode_jenis: '', mata_anggaran: '', deskripsi_pk: '', nominal_anggaran: 0 };
}

function emptyProposalKuisioner(): ProposalKuisioner {
    return { kode_kuisioner: null, pertanyaan: '', tipe: 'Kualitatif', satuan: '' };
}

function emptyChangeItem(): ChangeItem {
    return {
        tipe_perubahan: '',
        pk04_anggaran_id: null,
        bulan_awal: null,
        bulan_tujuan: null,
        pk_workflow_id: null,
        komentar: '',
        item_files: [],
        existing_files: [],
        proposal: emptyProposal(),
    };
}

function initChangeItemsFromServer(serverItems: Array<Record<string, unknown>>): ChangeItem[] {
    if (!serverItems || serverItems.length === 0) return [];

    return serverItems.map((si) => ({
        tipe_perubahan: (si.tipe_perubahan as string) || '',
        pk04_anggaran_id: (si.pk04_anggaran_id as number) || null,
        bulan_awal: (si.bulan_awal as number) || null,
        bulan_tujuan: (si.bulan_tujuan as number) || null,
        pk_workflow_id: (si.pk_workflow_id as number) || null,
        komentar: (si.komentar as string) || '',
        item_files: [],
        existing_files: ((si.files as Array<{ id: number; name: string; url: string | null }>) || []),
        proposal: (si.proposal as ProposalData) || emptyProposal(),
        anggaran_detail: si.anggaran_detail as ChangeItem['anggaran_detail'],
    }));
}

// ─── Status badge ───────────────────────────────────

function StepStatusBadge({ mode, scope }: { mode: string; scope: string }) {
    if (mode === 'readonly' && scope === 'admin') {
        return <Badge className="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Admin View</Badge>;
    }
    if (mode === 'readonly') {
        return <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Sudah Disubmit</Badge>;
    }
    if (mode === 'edit') {
        return <Badge className="bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300">Draft</Badge>;
    }
    return <Badge className="bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">Menunggu Diisi</Badge>;
}

// ─── TarikMajuPicker ───────────────────────────────────

function TarikMajuPicker({
    programs,
    selectedIds,
    onSelect,
    onClose,
}: {
    programs: ProgramPickerGroup[];
    selectedIds: number[];
    onSelect: (anggaranId: number, detail: ChangeItem['anggaran_detail'], bulanAwal: number) => void;
    onClose: () => void;
}) {
    const hasItems = programs.some((p) => p.kegiatan.some((k) => k.anggaran.length > 0));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="mx-4 max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-background p-6 shadow-xl">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold">Pilih Anggaran dari Bulan Mendatang</h3>
                    <Button variant="ghost" size="sm" onClick={onClose}>✕</Button>
                </div>
                {!hasItems ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Tidak ada anggaran di bulan mendatang yang dapat ditarik.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {programs.map((program) => (
                            <div key={program.program_id}>
                                <h4 className="mb-1 text-sm font-semibold">
                                    {program.program_name}{' '}
                                    <span className="text-muted-foreground">({program.kode_kategori})</span>
                                </h4>
                                {program.kegiatan.map((kegiatan) => (
                                    <div key={kegiatan.kegiatan_id} className="mb-3 ml-2">
                                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                                            {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                        </p>
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b bg-muted/50">
                                                    <th className="w-10 px-2 py-1"></th>
                                                    <th className="px-2 py-1 text-left text-xs font-medium">Kode Anggaran</th>
                                                    <th className="px-2 py-1 text-left text-xs font-medium">Mata Anggaran</th>
                                                    <th className="px-2 py-1 text-right text-xs font-medium">Nominal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {kegiatan.anggaran.map((a) => {
                                                    const isUsed = selectedIds.includes(a.pk04_anggaran_id) || a.disabled;
                                                    return (
                                                        <tr
                                                            key={a.pk04_anggaran_id}
                                                            className={`cursor-pointer border-b last:border-0 ${isUsed ? 'opacity-40' : 'hover:bg-muted/30'}`}
                                                            onClick={() => {
                                                                if (isUsed) return;
                                                                onSelect(a.pk04_anggaran_id, {
                                                                    program_name: program.program_name,
                                                                    kode_kategori: program.kode_kategori,
                                                                    kegiatan_name: kegiatan.nama_kegiatan,
                                                                    bulan: kegiatan.bulan,
                                                                    bulan_label: kegiatan.bulan_label,
                                                                    kode_anggaran_baru: a.kode_anggaran_baru,
                                                                    mata_anggaran: a.mata_anggaran,
                                                                    nominal: a.nominal,
                                                                }, kegiatan.bulan);
                                                            }}
                                                        >
                                                            <td className="px-2 py-1 text-center">
                                                                <input type="radio" disabled={isUsed} checked={false} readOnly className="h-3.5 w-3.5" />
                                                            </td>
                                                            <td className="px-2 py-1">
                                                                <KodeAnggaranFromString kode={a.kode_anggaran_baru} />
                                                            </td>
                                                            <td className="px-2 py-1 text-xs">{a.mata_anggaran}</td>
                                                            <td className="px-2 py-1 text-right text-xs tabular-nums">{formatRupiah(a.nominal)}</td>
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
                )}
                <div className="mt-4 flex justify-end">
                    <Button variant="secondary" size="sm" onClick={onClose}>Tutup</Button>
                </div>
            </div>
        </div>
    );
}

// ─── ProposalBaruForm (embedded PK01 mini-form) ─────────

function ProposalBaruForm({
    proposal,
    onChange,
    kodeRefs,
    isReadonly,
}: {
    proposal: ProposalData;
    onChange: (p: ProposalData) => void;
    kodeRefs: Props['kodeRefs'];
    isReadonly: boolean;
}) {
    function updateField<K extends keyof ProposalData>(key: K, value: ProposalData[K]) {
        onChange({ ...proposal, [key]: value });
    }

    function updateKegiatan(kIdx: number, updates: Partial<ProposalKegiatan>) {
        const kegiatan = [...proposal.kegiatan];
        kegiatan[kIdx] = { ...kegiatan[kIdx], ...updates };
        onChange({ ...proposal, kegiatan });
    }

    function addKegiatan() {
        onChange({ ...proposal, kegiatan: [...proposal.kegiatan, emptyProposalKegiatan()] });
    }

    function removeKegiatan(kIdx: number) {
        const kegiatan = [...proposal.kegiatan];
        kegiatan.splice(kIdx, 1);
        onChange({ ...proposal, kegiatan });
    }

    function updateAnggaran(kIdx: number, aIdx: number, updates: Partial<ProposalAnggaran>) {
        const kegiatan = [...proposal.kegiatan];
        const anggaran = [...kegiatan[kIdx].anggaran];
        anggaran[aIdx] = { ...anggaran[aIdx], ...updates };
        kegiatan[kIdx] = { ...kegiatan[kIdx], anggaran };
        onChange({ ...proposal, kegiatan });
    }

    function addAnggaran(kIdx: number) {
        const kegiatan = [...proposal.kegiatan];
        kegiatan[kIdx] = { ...kegiatan[kIdx], anggaran: [...kegiatan[kIdx].anggaran, emptyProposalAnggaran()] };
        onChange({ ...proposal, kegiatan });
    }

    function removeAnggaran(kIdx: number, aIdx: number) {
        const kegiatan = [...proposal.kegiatan];
        const anggaran = [...kegiatan[kIdx].anggaran];
        anggaran.splice(aIdx, 1);
        kegiatan[kIdx] = { ...kegiatan[kIdx], anggaran };
        onChange({ ...proposal, kegiatan });
    }

    function updateKuisioner(kIdx: number, qIdx: number, updates: Partial<ProposalKuisioner>) {
        const kegiatan = [...proposal.kegiatan];
        const kuisioner = [...kegiatan[kIdx].kuisioner];
        kuisioner[qIdx] = { ...kuisioner[qIdx], ...updates };
        kegiatan[kIdx] = { ...kegiatan[kIdx], kuisioner };
        onChange({ ...proposal, kegiatan });
    }

    function addKuisioner(kIdx: number) {
        const kegiatan = [...proposal.kegiatan];
        kegiatan[kIdx] = { ...kegiatan[kIdx], kuisioner: [...kegiatan[kIdx].kuisioner, emptyProposalKuisioner()] };
        onChange({ ...proposal, kegiatan });
    }

    function removeKuisioner(kIdx: number, qIdx: number) {
        const kegiatan = [...proposal.kegiatan];
        const kuisioner = [...kegiatan[kIdx].kuisioner];
        kuisioner.splice(qIdx, 1);
        kegiatan[kIdx] = { ...kegiatan[kIdx], kuisioner };
        onChange({ ...proposal, kegiatan });
    }

    function addFromTemplate(kIdx: number, template: KuisionerTemplate) {
        const kegiatan = [...proposal.kegiatan];
        kegiatan[kIdx] = {
            ...kegiatan[kIdx],
            kuisioner: [...kegiatan[kIdx].kuisioner, {
                kode_kuisioner: template.kode,
                pertanyaan: template.pertanyaan,
                tipe: template.tipe,
                satuan: template.satuan || '',
            }],
        };
        onChange({ ...proposal, kegiatan });
    }

    return (
        <div className="space-y-4 rounded-lg border border-purple-200 bg-purple-50/30 p-4 dark:border-purple-800 dark:bg-purple-950/20">
            <div className="flex items-center gap-2">
                <h5 className="text-sm font-semibold">Informasi Program Baru</h5>
                <Badge className="bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">Di Luar Plafon</Badge>
            </div>

            {/* Program header fields */}
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label className="text-xs">Kategori Pelayanan *</Label>
                    <Select value={proposal.kode_kategori} onValueChange={(v) => updateField('kode_kategori', v)} disabled={isReadonly}>
                        <SelectTrigger className="mt-1 h-8 text-xs"><SelectValue placeholder="Pilih..." /></SelectTrigger>
                        <SelectContent>
                            {kodeRefs.kategori.map((k) => (
                                <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label className="text-xs">Nama Program *</Label>
                    <Input className="mt-1 h-8 text-xs" value={proposal.nama_program} onChange={(e) => updateField('nama_program', e.target.value)} disabled={isReadonly} maxLength={255} />
                </div>
                <div className="sm:col-span-2">
                    <Label className="text-xs">Deskripsi Program *</Label>
                    <Textarea className="mt-1 resize-y text-xs" rows={2} value={proposal.deskripsi_program} onChange={(e) => updateField('deskripsi_program', e.target.value)} disabled={isReadonly} />
                </div>
                <div className="sm:col-span-2">
                    <Label className="text-xs">Tujuan Program *</Label>
                    <Textarea className="mt-1 resize-y text-xs" rows={2} value={proposal.tujuan_program} onChange={(e) => updateField('tujuan_program', e.target.value)} disabled={isReadonly} />
                </div>
            </div>

            {/* Kegiatan & Anggaran */}
            <div className="space-y-3">
                <h6 className="text-xs font-semibold">Kegiatan & Anggaran</h6>
                {proposal.kegiatan.map((kegiatan, kIdx) => (
                    <KegiatanSection
                        key={kIdx}
                        kegiatan={kegiatan}
                        kIdx={kIdx}
                        kodeRefs={kodeRefs}
                        isReadonly={isReadonly}
                        canRemove={proposal.kegiatan.length > 1}
                        onUpdate={(u) => updateKegiatan(kIdx, u)}
                        onRemove={() => removeKegiatan(kIdx)}
                        onUpdateAnggaran={(aIdx, u) => updateAnggaran(kIdx, aIdx, u)}
                        onAddAnggaran={() => addAnggaran(kIdx)}
                        onRemoveAnggaran={(aIdx) => removeAnggaran(kIdx, aIdx)}
                        onUpdateKuisioner={(qIdx, u) => updateKuisioner(kIdx, qIdx, u)}
                        onAddKuisioner={() => addKuisioner(kIdx)}
                        onRemoveKuisioner={(qIdx) => removeKuisioner(kIdx, qIdx)}
                        onAddFromTemplate={(t) => addFromTemplate(kIdx, t)}
                    />
                ))}
                {!isReadonly && (
                    <Button variant="outline" size="sm" onClick={addKegiatan}>
                        <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Kegiatan
                    </Button>
                )}
            </div>
        </div>
    );
}

// ─── KegiatanSection (inside ProposalBaruForm) ─────────

function KegiatanSection({
    kegiatan, kIdx, kodeRefs, isReadonly, canRemove,
    onUpdate, onRemove,
    onUpdateAnggaran, onAddAnggaran, onRemoveAnggaran,
    onUpdateKuisioner, onAddKuisioner, onRemoveKuisioner, onAddFromTemplate,
}: {
    kegiatan: ProposalKegiatan;
    kIdx: number;
    kodeRefs: Props['kodeRefs'];
    isReadonly: boolean;
    canRemove: boolean;
    onUpdate: (u: Partial<ProposalKegiatan>) => void;
    onRemove: () => void;
    onUpdateAnggaran: (aIdx: number, u: Partial<ProposalAnggaran>) => void;
    onAddAnggaran: () => void;
    onRemoveAnggaran: (aIdx: number) => void;
    onUpdateKuisioner: (qIdx: number, u: Partial<ProposalKuisioner>) => void;
    onAddKuisioner: () => void;
    onRemoveKuisioner: (qIdx: number) => void;
    onAddFromTemplate: (t: KuisionerTemplate) => void;
}) {
    const [collapsed, setCollapsed] = useState(false);
    const [showTemplatePicker, setShowTemplatePicker] = useState(false);
    const anggaranTotal = kegiatan.anggaran.reduce((s, a) => s + (a.nominal_anggaran || 0), 0);

    return (
        <div className="rounded-md border bg-background p-3">
            <div className="flex items-center justify-between">
                <button type="button" className="flex items-center gap-2 text-xs font-medium" onClick={() => setCollapsed(!collapsed)}>
                    {collapsed ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronUp className="h-3.5 w-3.5" />}
                    Kegiatan {kIdx + 1}: {kegiatan.nama_kegiatan || '(belum diisi)'}
                    <span className="text-muted-foreground">— {formatRupiah(anggaranTotal)}</span>
                </button>
                {!isReadonly && canRemove && (
                    <Button variant="ghost" size="sm" onClick={onRemove} className="h-6 w-6 p-0 text-destructive">
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                )}
            </div>

            {!collapsed && (
                <div className="mt-3 space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs">Nama Kegiatan *</Label>
                            <Textarea className="mt-1 resize-y text-xs" rows={1} value={kegiatan.nama_kegiatan}
                                onChange={(e) => onUpdate({ nama_kegiatan: e.target.value.replace(/\n/g, '') })}
                                onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
                                disabled={isReadonly} maxLength={255} />
                        </div>
                        <div>
                            <Label className="text-xs">Bulan *</Label>
                            <Select value={kegiatan.bulan?.toString() ?? ''} onValueChange={(v) => onUpdate({ bulan: Number(v) })} disabled={isReadonly}>
                                <SelectTrigger className="mt-1 h-8 text-xs"><SelectValue placeholder="Pilih bulan..." /></SelectTrigger>
                                <SelectContent>
                                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                        <SelectItem key={m} value={m.toString()}>{BULAN_NAMES[m]}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Anggaran table */}
                    <div>
                        <Label className="text-xs font-medium">Anggaran</Label>
                        <div className="mt-1 overflow-x-auto">
                            <table className="min-w-[700px] text-xs">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-2 py-1 text-left font-medium">Bidang</th>
                                        <th className="px-2 py-1 text-left font-medium">Sub Bidang</th>
                                        <th className="px-2 py-1 text-left font-medium">Jenis</th>
                                        <th className="min-w-[120px] px-2 py-1 text-left font-medium">Mata Anggaran</th>
                                        <th className="min-w-[100px] px-2 py-1 text-right font-medium">Nominal</th>
                                        <th className="w-8 px-2 py-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.anggaran.map((a, aIdx) => (
                                        <tr key={aIdx} className="border-b last:border-0">
                                            <td className="px-1 py-1">
                                                <Select value={a.kode_bidang} onValueChange={(v) => onUpdateAnggaran(aIdx, { kode_bidang: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="..." /></SelectTrigger>
                                                    <SelectContent>{kodeRefs.bidang.map((k) => <SelectItem key={k.kode} value={k.kode}>{k.kode}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </td>
                                            <td className="px-1 py-1">
                                                <Select value={a.kode_sub_bidang} onValueChange={(v) => onUpdateAnggaran(aIdx, { kode_sub_bidang: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="..." /></SelectTrigger>
                                                    <SelectContent>{kodeRefs.subBidang.map((k) => <SelectItem key={k.kode} value={k.kode}>{k.kode}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </td>
                                            <td className="px-1 py-1">
                                                <Select value={a.kode_jenis} onValueChange={(v) => onUpdateAnggaran(aIdx, { kode_jenis: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="..." /></SelectTrigger>
                                                    <SelectContent>{kodeRefs.jenis.map((k) => <SelectItem key={k.kode} value={k.kode}>{k.kode}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </td>
                                            <td className="px-1 py-1">
                                                <Textarea className="min-w-[100px] resize-y text-xs" rows={1} value={a.mata_anggaran}
                                                    onChange={(e) => onUpdateAnggaran(aIdx, { mata_anggaran: e.target.value.replace(/\n/g, '') })}
                                                    onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
                                                    disabled={isReadonly} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <RupiahInput value={a.nominal_anggaran} onChange={(v) => onUpdateAnggaran(aIdx, { nominal_anggaran: v })} disabled={isReadonly} className="h-7 text-xs" />
                                            </td>
                                            <td className="px-1 py-1">
                                                {!isReadonly && kegiatan.anggaran.length > 1 && (
                                                    <Button variant="ghost" size="sm" onClick={() => onRemoveAnggaran(aIdx)} className="h-6 w-6 p-0 text-destructive">
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {!isReadonly && (
                            <Button variant="outline" size="sm" className="mt-1" onClick={onAddAnggaran}>
                                <Plus className="mr-1 h-3 w-3" /> Tambah Anggaran
                            </Button>
                        )}
                    </div>

                    {/* Kuisioner table */}
                    <div>
                        <Label className="text-xs font-medium">Kuisioner</Label>
                        <div className="mt-1 overflow-x-auto">
                            <table className="min-w-[500px] text-xs">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="min-w-[180px] px-2 py-1 text-left font-medium">Pertanyaan</th>
                                        <th className="px-2 py-1 text-left font-medium">Tipe</th>
                                        <th className="px-2 py-1 text-left font-medium">Satuan</th>
                                        <th className="w-8 px-2 py-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.kuisioner.map((q, qIdx) => {
                                        const isTemplate = !!q.kode_kuisioner;
                                        return (
                                            <tr key={qIdx} className="border-b last:border-0">
                                                <td className="px-1 py-1">
                                                    <Textarea className="min-w-[160px] resize-y text-xs" rows={1} value={q.pertanyaan}
                                                        onChange={(e) => onUpdateKuisioner(qIdx, { pertanyaan: e.target.value.replace(/\n/g, '') })}
                                                        onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
                                                        disabled={isReadonly || isTemplate} />
                                                </td>
                                                <td className="px-1 py-1">
                                                    <Select value={q.tipe} onValueChange={(v) => onUpdateKuisioner(qIdx, { tipe: v })} disabled={isReadonly || isTemplate}>
                                                        <SelectTrigger className="h-7 text-xs"><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="Kualitatif">Kualitatif</SelectItem>
                                                            <SelectItem value="Kuantitatif">Kuantitatif</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </td>
                                                <td className="px-1 py-1">
                                                    <Input className="h-7 text-xs" value={q.satuan} onChange={(e) => onUpdateKuisioner(qIdx, { satuan: e.target.value })} disabled={isReadonly || isTemplate} />
                                                </td>
                                                <td className="px-1 py-1">
                                                    {!isReadonly && (
                                                        <Button variant="ghost" size="sm" onClick={() => onRemoveKuisioner(qIdx)} className="h-6 w-6 p-0 text-destructive">
                                                            <Trash2 className="h-3 w-3" />
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!isReadonly && (
                            <div className="mt-1 flex gap-2">
                                <Button variant="outline" size="sm" onClick={onAddKuisioner}>
                                    <Plus className="mr-1 h-3 w-3" /> Tambah Kuisioner
                                </Button>
                                {kodeRefs.kuisioner.length > 0 && (
                                    <Button variant="outline" size="sm" onClick={() => setShowTemplatePicker(!showTemplatePicker)}>
                                        Dari Template
                                    </Button>
                                )}
                            </div>
                        )}
                        {showTemplatePicker && (
                            <div className="mt-2 max-h-40 overflow-y-auto rounded border bg-muted/30 p-2">
                                {kodeRefs.kuisioner.map((t) => (
                                    <button key={t.kode} type="button" className="block w-full rounded px-2 py-1 text-left text-xs hover:bg-muted"
                                        onClick={() => { onAddFromTemplate(t); setShowTemplatePicker(false); }}>
                                        <span className="font-medium">{t.kode}</span> — {t.pertanyaan}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Main component ──────────────────────────────────

export default function Pabd02a({
    workflow, stepData, pabd01ChecklistData, pabd01Submitter, futureAnggaranItems,
    kodeRefs, budgetCounter, workflowMeta, mode, canDraft, canSubmit, canComment,
    actionRoles, activeRoleName, teamName, scope, basePath,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [items, setItems] = useState<ChangeItem[]>(() => {
        const init = initChangeItemsFromServer(stepData.items);
        return init.length > 0 ? init : [];
    });
    const [pickerOpen, setPickerOpen] = useState<number | null>(null);

    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'team' ? 'Tim' : 'Admin', href: `/${scope}` },
        { title: 'Anggaran Bulanan', href: `/${scope}/workflows/pabd` },
        { title: workflow.label, href: basePath },
        { title: 'PABD02A: Perubahan Anggaran', href: '#' },
    ];

    // Selected tarik_maju anggaran IDs (for picker disabled state)
    const selectedAnggaranIds = items
        .filter((i) => i.tipe_perubahan === 'tarik_maju' && i.pk04_anggaran_id)
        .map((i) => i.pk04_anggaran_id!);

    // Live summary
    const summary = useMemo(() => {
        let tarikMajuNominal = 0;
        let tarikMajuCount = 0;
        let proposalBaruNominal = 0;
        let proposalBaruCount = 0;

        for (const item of items) {
            if (item.tipe_perubahan === 'tarik_maju' && item.anggaran_detail) {
                tarikMajuNominal += item.anggaran_detail.nominal;
                tarikMajuCount++;
            } else if (item.tipe_perubahan === 'proposal_baru') {
                for (const k of item.proposal.kegiatan) {
                    for (const a of k.anggaran) {
                        proposalBaruNominal += a.nominal_anggaran || 0;
                    }
                }
                proposalBaruCount++;
            }
        }

        return { tarikMajuNominal, tarikMajuCount, proposalBaruNominal, proposalBaruCount };
    }, [items]);

    // PABD01 checklist summary
    const checklistSummary = useMemo(() => {
        let total = 0; let totalNominal = 0;
        let dicairkan = 0; let dicairkanNominal = 0;
        let tidakDicairkan = 0; let tidakDicairkanNominal = 0;
        for (const p of pabd01ChecklistData) {
            for (const k of p.kegiatan) {
                for (const a of k.anggaran) {
                    total++; totalNominal += a.nominal;
                    if (a.dicairkan) { dicairkan++; dicairkanNominal += a.nominal; }
                    else { tidakDicairkan++; tidakDicairkanNominal += a.nominal; }
                }
            }
        }
        return { total, totalNominal, dicairkan, dicairkanNominal, tidakDicairkan, tidakDicairkanNominal };
    }, [pabd01ChecklistData]);

    // Item CRUD
    function addItem() {
        setItems([...items, emptyChangeItem()]);
    }

    function updateItem(idx: number, updates: Partial<ChangeItem>) {
        const next = [...items];
        next[idx] = { ...next[idx], ...updates };
        setItems(next);
    }

    function removeItem(idx: number) {
        setItems(items.filter((_, i) => i !== idx));
    }

    // Tarik maju picker handler
    function handlePickerSelect(itemIdx: number, anggaranId: number, detail: ChangeItem['anggaran_detail'], bulanAwal: number) {
        updateItem(itemIdx, {
            pk04_anggaran_id: anggaranId,
            bulan_awal: bulanAwal,
            anggaran_detail: detail,
        });
        setPickerOpen(null);
    }

    // Valid bulan_tujuan options: from PABD target month up to bulan_awal - 1.
    // Floor is bulan_anggaran (not current calendar month) so a January PABD can
    // always pull items to January even when processed in a later month.
    function getValidBulanTujuan(bulanAwal: number | null): number[] {
        if (!bulanAwal) return [];
        const floor = workflowMeta.bulan_anggaran;
        const months: number[] = [];
        for (let m = floor; m < bulanAwal; m++) {
            months.push(m);
        }
        return months;
    }

    // Build form data
    function buildFormData(notes: string, actionFiles: File[]) {
        const data: Record<string, unknown> = {
            items: items.map((item) => ({
                tipe_perubahan: item.tipe_perubahan || undefined,
                pk04_anggaran_id: item.pk04_anggaran_id,
                bulan_awal: item.bulan_awal,
                bulan_tujuan: item.bulan_tujuan,
                komentar: item.komentar,
                _existing_pk_workflow_id: item.pk_workflow_id,
                proposal: item.tipe_perubahan === 'proposal_baru' ? item.proposal : undefined,
                item_files: item.item_files.length > 0 ? item.item_files : undefined,
            })),
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
        };

        if (actionFiles.length > 0) {
            data['files'] = actionFiles;
        }

        return data;
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `${basePath}/pabd02a/${stepData.id}/${action}`;
        router.post(url, buildFormData(notes, files) as Record<string, unknown>, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PABD02A: Perubahan Anggaran — ${workflowMeta.team_name}`} />
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Heading title="PABD02A: Perubahan Anggaran" />
                            <StepStatusBadge mode={mode} scope={scope} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {workflowMeta.bulan_label} {workflowMeta.tahun_anggaran} — {teamName}
                        </p>
                    </div>
                </div>

                {/* Banners */}
                {mode === 'readonly' && scope !== 'admin' && (
                    <div className="flex gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
                        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
                        <p className="text-sm text-green-800 dark:text-green-200">
                            Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                        </p>
                    </div>
                )}

                {(scope === 'admin' || (mode !== 'readonly' && !canDraft && !canSubmit)) && (
                    <div className="flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                        <p className="text-sm text-blue-800 dark:text-blue-200">
                            Anda hanya dapat melihat data pada step ini.
                        </p>
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="pabd02a"
                    canComment={canComment}
                    finalSteps={['PABD05']}
                />

                {/* Budget Reference */}
                <BudgetReferenceCard counter={budgetCounter} variant="readonly" />

                {/* PABD01 Checklist (read-only) */}
                <SectionCard
                    title="PABD01 — Checklist Pencairan"
                    headerRight={
                        pabd01Submitter ? (
                            <span className="text-xs text-muted-foreground">
                                Diisi oleh: {pabd01Submitter.name} ({pabd01Submitter.role}{pabd01Submitter.team ? ` · ${pabd01Submitter.team}` : ''}) — {pabd01Submitter.at}
                            </span>
                        ) : undefined
                    }
                >
                    {pabd01ChecklistData.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Tidak ada data checklist.</p>
                    ) : (
                        <div className="space-y-4">
                            {pabd01ChecklistData.map((program) => (
                                <div key={program.program_id}>
                                    <h4 className="mb-1 text-sm font-semibold">
                                        {program.program_name}{' '}
                                        <span className="text-muted-foreground">({program.kode_kategori})</span>
                                        {program.tipe === 'proposal' && (
                                            <Badge className="ml-2 bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">Di Luar Plafon</Badge>
                                        )}
                                    </h4>
                                    {program.kegiatan.map((kegiatan) => (
                                        <div key={kegiatan.kegiatan_id} className="mb-3 ml-2">
                                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                                {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                            </p>
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b bg-muted/50">
                                                        <th className="w-10 px-3 py-1 text-center text-xs font-medium">✓/✗</th>
                                                        <th className="px-3 py-1 text-left text-xs font-medium">Kode Anggaran</th>
                                                        <th className="px-3 py-1 text-left text-xs font-medium">Mata Anggaran</th>
                                                        <th className="px-3 py-1 text-right text-xs font-medium">Nominal (Rp)</th>
                                                        <th className="px-3 py-1 text-left text-xs font-medium">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {kegiatan.anggaran.map((item) => (
                                                        <tr key={item.pabd01_item_id} className="border-b last:border-0">
                                                            <td className="px-3 py-1 text-center text-sm">
                                                                {item.dicairkan ? '✓' : '✗'}
                                                            </td>
                                                            <td className="px-3 py-1">
                                                                <KodeAnggaranFromString kode={item.kode_anggaran_baru} />
                                                            </td>
                                                            <td className="px-3 py-1 text-xs">{item.mata_anggaran}</td>
                                                            <td className="px-3 py-1 text-right text-xs tabular-nums">{formatRupiah(item.nominal)}</td>
                                                            <td className="px-3 py-1">
                                                                {item.status_label && (
                                                                    <Badge className={item.status_item === 'ditarik_maju'
                                                                        ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
                                                                        : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'}>
                                                                        {item.status_label}
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ))}
                                </div>
                            ))}
                            {/* Checklist summary */}
                            <div className="rounded-md border bg-muted/30 p-3">
                                <dl className="grid grid-cols-3 gap-2 text-xs">
                                    <div>
                                        <dt className="text-muted-foreground">Total Anggaran</dt>
                                        <dd className="font-medium">{formatRupiah(checklistSummary.totalNominal)} ({checklistSummary.total} item)</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Dicairkan</dt>
                                        <dd className="font-medium text-green-700 dark:text-green-400">{formatRupiah(checklistSummary.dicairkanNominal)} ({checklistSummary.dicairkan} item)</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Tidak Dicairkan</dt>
                                        <dd className="font-medium text-red-700 dark:text-red-400">{formatRupiah(checklistSummary.tidakDicairkanNominal)} ({checklistSummary.tidakDicairkan} item)</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    )}
                </SectionCard>

                {/* Ringkasan Perubahan (live counter) */}
                {items.length > 0 && (
                    <div className="rounded-md border bg-muted/30 p-3">
                        <h4 className="mb-2 text-xs font-semibold text-muted-foreground">Ringkasan Perubahan</h4>
                        <dl className="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <dt className="text-muted-foreground">Tarik Maju (perubahan ini)</dt>
                                <dd className="font-medium">{formatRupiah(summary.tarikMajuNominal)} ({summary.tarikMajuCount} item)</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Proposal Baru (perubahan ini){' '}
                                    <Badge className="ml-1 bg-purple-100 text-[10px] text-purple-700 dark:bg-purple-900 dark:text-purple-300">Di Luar Plafon</Badge>
                                </dt>
                                <dd className="font-medium">{formatRupiah(summary.proposalBaruNominal)} ({summary.proposalBaruCount} item)</dd>
                            </div>
                        </dl>
                    </div>
                )}

                {/* Daftar Perubahan Anggaran */}
                <SectionCard
                    title={`Daftar Perubahan Anggaran (${items.length} item)`}
                    headerRight={
                        !isReadonly ? (
                            <Button variant="outline" size="sm" onClick={addItem}>
                                <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Item Perubahan
                            </Button>
                        ) : undefined
                    }
                >
                    {items.length === 0 ? (
                        <div className="py-8 text-center">
                            <p className="text-sm text-muted-foreground">Belum ada item perubahan.</p>
                            {!isReadonly && (
                                <Button variant="outline" size="sm" className="mt-3" onClick={addItem}>
                                    <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Item Perubahan
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {items.map((item, idx) => (
                                <div key={idx} className="rounded-lg border p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h5 className="text-sm font-semibold">Item {idx + 1}</h5>
                                        {!isReadonly && (
                                            <Button variant="ghost" size="sm" onClick={() => removeItem(idx)} className="text-destructive">
                                                <Trash2 className="mr-1 h-3.5 w-3.5" /> Hapus
                                            </Button>
                                        )}
                                    </div>

                                    {/* Tipe Perubahan */}
                                    <div className="mb-3">
                                        <Label className="text-xs font-medium">Tipe Perubahan *</Label>
                                        <div className="mt-1 flex gap-4">
                                            <label className="flex cursor-pointer items-center gap-2">
                                                <input type="radio" name={`tipe_${idx}`} value="tarik_maju"
                                                    checked={item.tipe_perubahan === 'tarik_maju'}
                                                    onChange={() => updateItem(idx, { tipe_perubahan: 'tarik_maju' })}
                                                    disabled={isReadonly} className="h-3.5 w-3.5" />
                                                <span className="text-xs">Tarik Anggaran Maju</span>
                                            </label>
                                            <label className="flex cursor-pointer items-center gap-2">
                                                <input type="radio" name={`tipe_${idx}`} value="proposal_baru"
                                                    checked={item.tipe_perubahan === 'proposal_baru'}
                                                    onChange={() => updateItem(idx, { tipe_perubahan: 'proposal_baru', proposal: emptyProposal() })}
                                                    disabled={isReadonly} className="h-3.5 w-3.5" />
                                                <span className="text-xs">Proposal Baru</span>
                                            </label>
                                        </div>
                                    </div>

                                    {/* Tarik Maju fields */}
                                    {item.tipe_perubahan === 'tarik_maju' && (
                                        <div className="mb-3 space-y-3">
                                            <div>
                                                <Label className="text-xs">Anggaran Sumber *</Label>
                                                {item.anggaran_detail ? (
                                                    <div className="mt-1 rounded-md border bg-muted/30 p-2 text-xs">
                                                        <p><span className="text-muted-foreground">Program:</span> {item.anggaran_detail.program_name}</p>
                                                        <p><span className="text-muted-foreground">Kegiatan:</span> {item.anggaran_detail.kegiatan_name} — {item.anggaran_detail.bulan_label}</p>
                                                        <p><span className="text-muted-foreground">Kode:</span> <KodeAnggaranFromString kode={item.anggaran_detail.kode_anggaran_baru} /></p>
                                                        <p><span className="text-muted-foreground">Mata Anggaran:</span> {item.anggaran_detail.mata_anggaran}</p>
                                                        <p><span className="text-muted-foreground">Nominal:</span> {formatRupiah(item.anggaran_detail.nominal)}</p>
                                                        {!isReadonly && (
                                                            <Button variant="outline" size="sm" className="mt-2" onClick={() => setPickerOpen(idx)}>
                                                                Ganti Anggaran
                                                            </Button>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <div className="mt-1">
                                                        <Button variant="outline" size="sm" onClick={() => setPickerOpen(idx)} disabled={isReadonly}>
                                                            Pilih Anggaran
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <Label className="text-xs">Bulan Asal</Label>
                                                    <Input className="mt-1 h-8 text-xs" value={item.bulan_awal ? BULAN_NAMES[item.bulan_awal] || '' : ''} disabled />
                                                </div>
                                                <div>
                                                    <Label className="text-xs">Bulan Tujuan *</Label>
                                                    <Select
                                                        value={item.bulan_tujuan?.toString() ?? ''}
                                                        onValueChange={(v) => updateItem(idx, { bulan_tujuan: Number(v) })}
                                                        disabled={isReadonly}
                                                    >
                                                        <SelectTrigger className="mt-1 h-8 text-xs"><SelectValue placeholder="Pilih bulan tujuan..." /></SelectTrigger>
                                                        <SelectContent>
                                                            {getValidBulanTujuan(item.bulan_awal).map((m) => (
                                                                <SelectItem key={m} value={m.toString()}>{BULAN_NAMES[m]}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Proposal Baru mini-form */}
                                    {item.tipe_perubahan === 'proposal_baru' && (
                                        <div className="mb-3">
                                            <ProposalBaruForm
                                                proposal={item.proposal}
                                                onChange={(p) => updateItem(idx, { proposal: p })}
                                                kodeRefs={kodeRefs}
                                                isReadonly={isReadonly}
                                            />
                                        </div>
                                    )}

                                    {/* Komentar */}
                                    {item.tipe_perubahan && (
                                        <>
                                            <div className="mb-3">
                                                <Label className="text-xs">Alasan Perubahan *</Label>
                                                <Textarea className="mt-1 resize-y text-xs" rows={3}
                                                    value={item.komentar}
                                                    onChange={(e) => updateItem(idx, { komentar: e.target.value })}
                                                    disabled={isReadonly}
                                                    placeholder="Jelaskan alasan perubahan ini (min. 10 karakter)..." />
                                            </div>

                                            {/* File upload */}
                                            <div>
                                                <Label className="text-xs">
                                                    Lampiran {item.tipe_perubahan === 'proposal_baru' ? '*' : '(opsional)'}
                                                </Label>
                                                {item.existing_files.length > 0 && (
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {item.existing_files.map((f) => (
                                                            <span key={f.id} className="rounded bg-muted px-2 py-0.5 text-xs">{f.name}</span>
                                                        ))}
                                                    </div>
                                                )}
                                                {!isReadonly && (
                                                    <input type="file" multiple className="mt-1 text-xs"
                                                        onChange={(e) => updateItem(idx, { item_files: Array.from(e.target.files || []) })} />
                                                )}
                                                <p className="mt-0.5 text-[10px] text-muted-foreground">Maks 25MB per file</p>
                                            </div>
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={<Button variant="secondary" disabled={processing}>Simpan Draft</Button>}
                                title="Simpan Draft"
                                description="Data perubahan anggaran akan disimpan sebagai draft."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={<Button disabled={processing || items.length === 0}>Submit</Button>}
                                title="Submit Perubahan Anggaran"
                                description="Perubahan anggaran akan disubmit untuk review oleh Bendahara Umum (PABD02B)."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                    </div>
                )}

                {/* Tarik Maju Picker Modal */}
                {pickerOpen !== null && (
                    <TarikMajuPicker
                        programs={futureAnggaranItems}
                        selectedIds={selectedAnggaranIds}
                        onSelect={(id, detail, bulanAwal) => handlePickerSelect(pickerOpen, id, detail, bulanAwal)}
                        onClose={() => setPickerOpen(null)}
                    />
                )}
            </div>
        </AppLayout>
    );
}

<?php

namespace App\Services;

use App\Models\File;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class Prbl05PdfExportService
{
    /**
     * Generate a PDF file and return the temp file path.
     */
    public function generate(Prbl05LaporanBulanan $prbl05): string
    {
        $prbl05->loadMissing([
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.itemKuisioner.pk04Kuisioner',
            'itemKegiatan.pk04Kegiatan.pk04ProgramTahunan',
            'itemRealisasi.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan',
            'rekeningOrganisasi',
            'bukti.file',
            'prblWorkflow.team',
        ]);

        $workflow = $prbl05->prblWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $tahun = $workflow?->tahun_laporan ?? now()->year;
        $bulan = $workflow?->bulan_laporan ?? 1;
        $ppLabel = $this->resolvePpLabel($workflow);
        $chronology = $this->buildChronology($workflow);
        $groupedKegiatan = $this->buildGroupedKegiatan($prbl05);
        $groupedRealisasi = $this->buildGroupedRealisasi($prbl05);

        $bulanLabels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $bulanLabel = $bulanLabels[$bulan] ?? '-';
        $logoBase64 = base64_encode(file_get_contents(public_path('images/app-logo-black.png')));

        $buktiImages = $prbl05->bukti->mapWithKeys(fn ($b) => [
            $b->id => $this->fileToImageData($b->file),
        ])->all();

        $pdf = Pdf::loadView('exports.prbl05-pdf', [
            'prbl05' => $prbl05,
            'workflow' => $workflow,
            'teamName' => $teamName,
            'tahun' => $tahun,
            'bulan' => $bulan,
            'bulanLabel' => $bulanLabel,
            'bulanLabels' => $bulanLabels,
            'ppLabel' => $ppLabel,
            'chronology' => $chronology,
            'groupedKegiatan' => $groupedKegiatan,
            'groupedRealisasi' => $groupedRealisasi,
            'logoBase64' => $logoBase64,
            'buktiImages' => $buktiImages,
            'label' => "PRBL-{$bulanLabel} {$tahun}-{$teamName}",
            'judul' => "Laporan Bulanan {$bulanLabel} {$tahun} — {$teamName}",
            'revision' => 0,
            'dikompilasi' => $prbl05->created_at->format('d F Y, H:i').' WIB',
        ]);
        $pdf->setPaper('a4', 'portrait');

        $tempPath = tempnam(sys_get_temp_dir(), 'prbl05_').'.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Build grouped kegiatan items: program -> kegiatan -> narratives + kuisioner + files.
     *
     * @return list<array{
     *   nama_program: string,
     *   nomer_program: string|null,
     *   kegiatan: list<array{
     *     nama_kegiatan: string,
     *     nomer_kegiatan: string|null,
     *     bulan: int|null,
     *     masalah: string|null,
     *     langkah_penanganan: string|null,
     *     harapan: string|null,
     *     catatan_tim: string|null,
     *     kuisioner: list<array{kode: string|null, pertanyaan: string, tipe: string, satuan: string|null, jawaban: string|null}>,
     *     foto_kegiatan: list<array{filename: string, data_uri: string|null}>,
     *     nota_pengeluaran: list<array{filename: string, data_uri: string|null}>,
     *   }>,
     * }>
     */
    private function buildGroupedKegiatan(Prbl05LaporanBulanan $prbl05): array
    {
        $programs = [];

        foreach ($prbl05->itemKegiatan as $item) {
            $pk04Kegiatan = $item->pk04Kegiatan;
            $pk04Program = $pk04Kegiatan?->pk04ProgramTahunan;
            $programKey = $pk04Program?->id ?? 0;

            if (! isset($programs[$programKey])) {
                $programs[$programKey] = [
                    'nama_program' => $pk04Program?->nama_program ?? '-',
                    'nomer_program' => $pk04Program?->nomer_program ?? null,
                    'kegiatan' => [],
                ];
            }

            $kuisioner = $item->itemKuisioner->map(fn ($ik) => [
                'kode' => $ik->pk04Kuisioner?->kode_kuisioner,
                'pertanyaan' => $ik->pk04Kuisioner?->pertanyaan ?? '-',
                'tipe' => $ik->pk04Kuisioner?->tipe ?? '-',
                'satuan' => $ik->pk04Kuisioner?->satuan,
                'jawaban' => $ik->jawaban,
            ])->all();

            $fotoKegiatan = $item->fotoKegiatan
                ->map(fn ($f) => $this->fileToImageData($f->file))
                ->all();

            $notaPengeluaran = $item->notaPengeluaran
                ->map(fn ($n) => $this->fileToImageData($n->file))
                ->all();

            $programs[$programKey]['kegiatan'][] = [
                'nama_kegiatan' => $pk04Kegiatan?->nama_kegiatan ?? '-',
                'nomer_kegiatan' => $pk04Kegiatan?->nomer_kegiatan ?? null,
                'bulan' => $pk04Kegiatan?->bulan,
                'masalah' => $item->masalah,
                'langkah_penanganan' => $item->langkah_penanganan,
                'harapan' => $item->harapan,
                'catatan_tim' => $item->catatan_tim,
                'kuisioner' => $kuisioner,
                'foto_kegiatan' => $fotoKegiatan,
                'nota_pengeluaran' => $notaPengeluaran,
            ];
        }

        return array_values($programs);
    }

    /**
     * Convert a File model to an array with its filename and base64 data URI (images only).
     *
     * @return array{filename: string, data_uri: string|null}
     */
    private function fileToImageData(?File $file): array
    {
        if (! $file) {
            return ['filename' => 'file', 'data_uri' => null];
        }

        $dataUri = null;

        if (str_starts_with((string) $file->mime_type, 'image/')) {
            try {
                $contents = Storage::disk($file->disk)->get($file->path);
                if ($contents !== null) {
                    $dataUri = 'data:'.$file->mime_type.';base64,'.base64_encode($contents);
                }
            } catch (\Throwable) {
                // File unreadable — fall back to filename only
            }
        }

        return ['filename' => $file->original_filename, 'data_uri' => $dataUri];
    }

    /**
     * Build grouped realisasi items: program -> kegiatan -> anggaran with realisasi.
     *
     * @return array{
     *   programs: list<array{
     *     nama_program: string,
     *     nomer_program: string|null,
     *     kegiatan: list<array{
     *       nama_kegiatan: string,
     *       nomer_kegiatan: string|null,
     *       items: list<array{
     *         kode_anggaran: string|null,
     *         mata_anggaran: string,
     *         nominal_anggaran: float,
     *         nominal_realisasi: float,
     *         selisih: float,
     *         komentar: string|null,
     *       }>,
     *       subtotal_anggaran: float,
     *       subtotal_realisasi: float,
     *       subtotal_selisih: float,
     *     }>,
     *   }>,
     *   total_anggaran: float,
     *   total_realisasi: float,
     *   total_refund: float,
     * }
     */
    private function buildGroupedRealisasi(Prbl05LaporanBulanan $prbl05): array
    {
        $programs = [];
        $totalAnggaran = 0;
        $totalRealisasi = 0;
        $totalRefund = 0;

        foreach ($prbl05->itemRealisasi as $item) {
            $pk04Anggaran = $item->pk04Anggaran;
            $pk04Kegiatan = $pk04Anggaran?->pk04Kegiatan;
            $pk04Program = $pk04Kegiatan?->pk04ProgramTahunan;

            $programKey = $pk04Program?->id ?? 0;
            $kegiatanKey = $pk04Kegiatan?->id ?? 0;

            if (! isset($programs[$programKey])) {
                $programs[$programKey] = [
                    'nama_program' => $pk04Program?->nama_program ?? '-',
                    'nomer_program' => $pk04Program?->nomer_program ?? null,
                    'kegiatan_map' => [],
                ];
            }

            if (! isset($programs[$programKey]['kegiatan_map'][$kegiatanKey])) {
                $programs[$programKey]['kegiatan_map'][$kegiatanKey] = [
                    'nama_kegiatan' => $pk04Kegiatan?->nama_kegiatan ?? '-',
                    'nomer_kegiatan' => $pk04Kegiatan?->nomer_kegiatan ?? null,
                    'items' => [],
                    'subtotal_anggaran' => 0,
                    'subtotal_realisasi' => 0,
                    'subtotal_selisih' => 0,
                ];
            }

            $nominalAnggaran = (float) $item->nominal_anggaran;
            $nominalRealisasi = (float) $item->nominal_realisasi;
            $selisih = (float) $item->selisih;

            $programs[$programKey]['kegiatan_map'][$kegiatanKey]['items'][] = [
                'kode_anggaran' => $pk04Anggaran?->kode_anggaran_baru ?? $pk04Anggaran?->kode_anggaran_lama,
                'mata_anggaran' => $pk04Anggaran?->mata_anggaran ?? '-',
                'nominal_anggaran' => $nominalAnggaran,
                'nominal_realisasi' => $nominalRealisasi,
                'selisih' => $selisih,
                'komentar' => $item->komentar_realisasi,
            ];

            $programs[$programKey]['kegiatan_map'][$kegiatanKey]['subtotal_anggaran'] += $nominalAnggaran;
            $programs[$programKey]['kegiatan_map'][$kegiatanKey]['subtotal_realisasi'] += $nominalRealisasi;
            $programs[$programKey]['kegiatan_map'][$kegiatanKey]['subtotal_selisih'] += $selisih;

            $totalAnggaran += $nominalAnggaran;
            $totalRealisasi += $nominalRealisasi;
            $totalRefund += $selisih;
        }

        // Flatten kegiatan_map to kegiatan list
        $result = [];
        foreach ($programs as $program) {
            $result[] = [
                'nama_program' => $program['nama_program'],
                'nomer_program' => $program['nomer_program'],
                'kegiatan' => array_values($program['kegiatan_map']),
            ];
        }

        return [
            'programs' => $result,
            'total_anggaran' => $totalAnggaran,
            'total_realisasi' => $totalRealisasi,
            'total_refund' => $totalRefund,
        ];
    }

    /**
     * Build chronological entries from workflow history.
     *
     * @return list<array{
     *   is_separator: bool,
     *   at?: string,
     *   step?: string,
     *   action?: string,
     *   action_label?: string,
     *   user?: string,
     *   role?: string,
     *   team?: string,
     *   notes?: string|null,
     *   files?: list<string>,
     * }>
     */
    private function buildChronology(?PrblWorkflow $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        $history = $workflow->history ?? [];

        // Pre-load all users and roles
        $userIds = collect($history)->pluck('by')->filter()->unique()->all();
        $roleIds = collect($history)->pluck('role')->filter()->unique()->all();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');
        $roles = Role::withTrashed()->whereIn('id', $roleIds)->with('team')->get()->keyBy('id');

        // Resolve file names for attachments
        $allFileIds = collect($history)->pluck('files')->filter()->flatten()
            ->merge(collect($history)->pluck('extra.files')->filter()->flatten())
            ->unique()->all();
        $fileNames = ! empty($allFileIds) ? File::whereIn('id', $allFileIds)->pluck('original_filename', 'id') : collect();

        $actionLabels = [
            'created' => 'Dibuat',
            'drafted' => 'Draft Disimpan',
            'submitted' => 'Disubmit',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'completed' => 'Selesai',
            'commented' => 'Komentar',
            'reset' => 'Direset',
            'skipped' => 'Dilewati',
        ];

        $entries = [];

        foreach ($history as $entry) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;
            $role = $roleId ? $roles->get($roleId) : null;
            $step = $entry['step'] ?? '-';
            $action = $entry['action'] ?? '-';

            // Resolve attached file names
            $entryFileNames = [];
            if (! empty($entry['files'])) {
                foreach ($entry['files'] as $fileId) {
                    $entryFileNames[] = $fileNames[$fileId] ?? "file #{$fileId}";
                }
            }
            if (! empty($entry['extra']['files'])) {
                foreach ($entry['extra']['files'] as $fileId) {
                    $entryFileNames[] = $fileNames[$fileId] ?? "file #{$fileId}";
                }
            }

            $entries[] = [
                'is_separator' => false,
                'at' => isset($entry['at']) ? \Carbon\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-',
                'step' => $step,
                'action' => $action,
                'action_label' => $actionLabels[$action] ?? $action,
                'user' => $userId ? ($users[$userId] ?? 'Unknown') : 'System',
                'role' => $role?->name ?? '-',
                'team' => $role?->team?->name ?? '-',
                'notes' => $entry['notes'] ?? null,
                'files' => $entryFileNames,
            ];
        }

        return $entries;
    }

    /**
     * Resolve PP label from the workflow's PP reference.
     */
    private function resolvePpLabel(?PrblWorkflow $workflow): string
    {
        $pp01 = $workflow?->ppWorkflow?->latestPp01();
        if (! $pp01) {
            return '-';
        }

        return 'PP '.($pp01->tahun ?? '-');
    }
}

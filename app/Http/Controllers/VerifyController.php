<?php

namespace App\Http\Controllers;

use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Services\PabdCompileService;
use App\Services\PkCompileService;
use App\Services\PpCompileService;
use Illuminate\Http\JsonResponse;

class VerifyController extends Controller
{
    public function __invoke(string $code, PpCompileService $ppCompileService, PkCompileService $pkCompileService, PabdCompileService $pabdCompileService): JsonResponse
    {
        // Try PP06 first
        $pp06 = Pp06PeriodeTahunan::where('verification_code', $code)->first();
        if ($pp06) {
            return $this->verifyPp06($pp06, $code, $ppCompileService);
        }

        // Try PK04
        $pk04 = Pk04ProgramTahunan::where('verification_code', $code)->first();
        if ($pk04) {
            return $this->verifyPk04($pk04, $code, $pkCompileService);
        }

        // Try PABD05
        $pabd05 = Pabd05PengajuanBulanan::where('verification_code', $code)->first();
        if ($pabd05) {
            return $this->verifyPabd05($pabd05, $code, $pabdCompileService);
        }

        // TODO: PRBL05 — uncomment when Prbl05PelaporanBulanan model exists
        // $prbl05 = Prbl05PelaporanBulanan::where('verification_code', $code)->first();
        // if ($prbl05) {
        //     return $this->verifyPrbl05($prbl05, $code);
        // }

        return response()->json([
            'status' => 'not_found',
            'message' => 'Kode verifikasi tidak ditemukan.',
        ], 404);
    }

    private function verifyPp06(Pp06PeriodeTahunan $pp06, string $code, PpCompileService $compileService): JsonResponse
    {
        $currentCode = $compileService->generateVerificationCode($pp06);
        $tampered = $currentCode !== $code;

        if ($tampered) {
            $pp06->update(['verification_code' => $code]);
        }

        $pp06->loadMissing([
            'ppWorkflow',
            'itemKuisioner',
            'itemPlafonAnggaran.team',
            'itemDokumenSop',
            'kodeBidangPelayanan',
            'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan',
            'kodeJenisProgram',
        ]);

        $workflow = $pp06->ppWorkflow;
        $totalPlafon = $pp06->itemPlafonAnggaran->sum('plafon_anggaran');

        return response()->json([
            'status' => $tampered ? 'tampered' : 'valid',
            'message' => $tampered
                ? 'Data telah berubah sejak dokumen ini dikompilasi.'
                : 'Dokumen terverifikasi — data tidak berubah.',
            'document_type' => 'Periode Tahunan (PP)',
            'label' => $workflow->label ?? "PP #{$workflow->id}",
            'tahun' => $pp06->tahun,
            'revision' => $pp06->revision,
            'compiled_at' => $pp06->created_at->toIso8601String(),
            'organization' => $pp06->pp01_created_by_organization_name,
            'total_plafon' => (int) $totalPlafon,
            'kuisioner_count' => $pp06->itemKuisioner->count(),
            'dokumen_count' => $pp06->itemDokumenSop->count(),
            'kode_count' => $pp06->kodeBidangPelayanan->count()
                + $pp06->kodeSubBidangPelayanan->count()
                + $pp06->kodeKategoriPelayanan->count()
                + $pp06->kodeJenisProgram->count(),
        ]);
    }

    private function verifyPk04(Pk04ProgramTahunan $pk04, string $code, PkCompileService $compileService): JsonResponse
    {
        $currentCode = $compileService->generateVerificationCode($pk04);
        $tampered = $currentCode !== $code;

        if ($tampered) {
            $pk04->update(['verification_code' => $code]);
        }

        $pk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner', 'pkWorkflow.team']);
        $workflow = $pk04->pkWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $totalAnggaran = $pk04->kegiatan->flatMap->anggaran->sum('nominal_anggaran');

        return response()->json([
            'status' => $tampered ? 'tampered' : 'valid',
            'message' => $tampered
                ? 'Data telah berubah sejak dokumen ini dikompilasi.'
                : 'Dokumen terverifikasi — data tidak berubah.',
            'document_type' => 'Program Tahunan (PK)',
            'label' => "PK-{$teamName}",
            'nama_program' => $pk04->nama_program,
            'revision' => $pk04->revision,
            'compiled_at' => $pk04->created_at->toIso8601String(),
            'team' => $teamName,
            'total_anggaran' => (int) $totalAnggaran,
            'kegiatan_count' => $pk04->kegiatan->count(),
            'kuisioner_count' => $pk04->kegiatan->flatMap->kuisioner->count(),
        ]);
    }

    private function verifyPabd05(Pabd05PengajuanBulanan $pabd05, string $code, PabdCompileService $compileService): JsonResponse
    {
        $currentCode = $compileService->generateVerificationCode($pabd05);
        $tampered = $currentCode !== $code;

        if ($tampered) {
            $pabd05->update(['verification_code' => $code]);
        }

        $pabd05->loadMissing(['itemAnggaran', 'buktiTransfer', 'pabdWorkflow.team']);
        $workflow = $pabd05->pabdWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $bulanNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
        $bulan = $bulanNames[$workflow?->bulan_anggaran ?? 0] ?? 'Unknown';

        return response()->json([
            'status' => $tampered ? 'tampered' : 'valid',
            'message' => $tampered
                ? 'Data telah berubah sejak dokumen ini dikompilasi.'
                : 'Dokumen terverifikasi — data tidak berubah.',
            'document_type' => 'Pengajuan Anggaran Bulanan (PABD)',
            'label' => "PABD-{$teamName}-{$bulan}/{$workflow?->tahun_anggaran}",
            'compiled_at' => $pabd05->created_at->toIso8601String(),
            'team' => $teamName,
            'total_anggaran_dicairkan' => (int) $pabd05->total_anggaran_dicairkan,
            'total_item_dicairkan' => $pabd05->total_item_dicairkan,
            'total_item_hangus' => $pabd05->total_item_hangus,
            'bukti_transfer_count' => $pabd05->buktiTransfer->count(),
        ]);
    }
}

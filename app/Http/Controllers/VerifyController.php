<?php

namespace App\Http\Controllers;

use App\Models\Pp\Pp06PeriodeTahunan;
use App\Services\PpCompileService;
use Illuminate\Http\JsonResponse;

class VerifyController extends Controller
{
    public function __invoke(string $code, PpCompileService $compileService): JsonResponse
    {
        $pp06 = Pp06PeriodeTahunan::where('verification_code', $code)->first();

        if (! $pp06) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Kode verifikasi tidak ditemukan.',
            ], 404);
        }

        // Recompute hash from current data to detect tampering
        $currentCode = $compileService->generateVerificationCode($pp06);
        $tampered = $currentCode !== $code;

        // If tampered, the verification_code was just overwritten — restore original
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
            'document_type' => 'Periode Tahunan',
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
}

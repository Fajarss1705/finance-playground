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

        $workflow = $pp06->ppWorkflow;

        return response()->json([
            'status' => $tampered ? 'tampered' : 'valid',
            'document_type' => 'Periode Tahunan',
            'label' => $workflow->label ?? "PP #{$workflow->id}",
            'revision' => $pp06->revision,
            'compiled_at' => $pp06->created_at->toIso8601String(),
            'message' => $tampered
                ? 'Data telah berubah sejak dokumen ini dikompilasi.'
                : 'Dokumen terverifikasi — data tidak berubah.',
        ]);
    }
}

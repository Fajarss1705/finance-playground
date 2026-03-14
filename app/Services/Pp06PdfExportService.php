<?php

namespace App\Services;

use App\Models\Pp\Pp06PeriodeTahunan;
use Barryvdh\DomPDF\Facade\Pdf;

class Pp06PdfExportService
{
    /**
     * Generate a PDF file and return the temp file path.
     */
    public function generate(Pp06PeriodeTahunan $pp06): string
    {
        $pp06->loadMissing([
            'kodeBidangPelayanan', 'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan', 'kodeJenisProgram',
            'itemKuisioner', 'itemPlafonAnggaran.team', 'itemDokumenSop.file',
            'ppWorkflow',
        ]);

        $totalPlafon = $pp06->itemPlafonAnggaran->sum('plafon_anggaran');

        $pdf = Pdf::loadView('exports.pp06-pdf', [
            'pp06' => $pp06,
            'workflow' => $pp06->ppWorkflow,
            'totalPlafon' => $totalPlafon,
        ]);
        $pdf->setPaper('a4', 'portrait');

        $tempPath = tempnam(sys_get_temp_dir(), 'pp06_').'.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }
}

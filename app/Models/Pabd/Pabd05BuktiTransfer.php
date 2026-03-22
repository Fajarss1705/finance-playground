<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pabd05BuktiTransfer extends Model
{
    protected $table = 'pabd05_bukti_transfer';

    protected $fillable = [
        'pabd05_pengajuan_bulanan_id',
        'file_id',
    ];

    public function pabd05PengajuanBulanan(): BelongsTo
    {
        return $this->belongsTo(Pabd05PengajuanBulanan::class, 'pabd05_pengajuan_bulanan_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}

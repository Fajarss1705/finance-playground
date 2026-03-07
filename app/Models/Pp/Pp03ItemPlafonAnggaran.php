<?php

namespace App\Models\Pp;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp03ItemPlafonAnggaran extends Model
{
    protected $table = 'pp03_item_plafon_anggaran';

    protected $fillable = [
        'pp03_data_id',
        'team_id',
        'kode_team',
        'plafon_anggaran',
        'nama_bank',
        'nama_rekening',
        'nomor_rekening',
        'catatan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plafon_anggaran' => 'decimal:2',
        ];
    }

    public function pp03Data(): BelongsTo
    {
        return $this->belongsTo(Pp03Data::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

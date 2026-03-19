<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pabd04ItemBuktiTransfer extends Model
{
    protected $table = 'pabd04_item_bukti_transfer';

    protected $fillable = [
        'pabd04_data_id',
        'file_id',
    ];

    public function pabd04Data(): BelongsTo
    {
        return $this->belongsTo(Pabd04Data::class, 'pabd04_data_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}

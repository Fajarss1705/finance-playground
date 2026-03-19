<?php

namespace App\Models\Pabd;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pabd02bItemReview extends Model
{
    protected $table = 'pabd02b_item_review';

    protected $fillable = [
        'pabd02b_data_id',
        'pabd02a_item_perubahan_id',
        'komentar_approval',
    ];

    public function pabd02bData(): BelongsTo
    {
        return $this->belongsTo(Pabd02bData::class, 'pabd02b_data_id');
    }

    public function pabd02aItemPerubahan(): BelongsTo
    {
        return $this->belongsTo(Pabd02aItemPerubahan::class, 'pabd02a_item_perubahan_id');
    }
}

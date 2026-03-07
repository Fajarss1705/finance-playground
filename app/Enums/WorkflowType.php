<?php

namespace App\Enums;

enum WorkflowType: string
{
    case PP = 'pp';
    case PK = 'pk';
    case PABD = 'pabd';
    case PRBL = 'prbl';

    public function label(): string
    {
        return match ($this) {
            self::PP => 'Perencanaan Periode',
            self::PK => 'Perencanaan Kegiatan dan Anggaran',
            self::PABD => 'Pengajuan Anggaran Bulan Depan',
            self::PRBL => 'Pelaporan dan Refund Bulan Lalu',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PP => 'PP',
            self::PK => 'PK',
            self::PABD => 'PABD',
            self::PRBL => 'PRBL',
        };
    }
}

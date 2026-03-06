export const pabdWorkflowList = [
    {
        id: 1,
        uuid: 'pabd-uuid-001',
        kode: 'PABD-2027-KA-03',
        team: 'Divisi Pendidikan',
        bulan: 'Maret',
        bulanNum: 3,
        tahun: 2027,
        status: 'active' as const,
        currentStep: 'PABD01',
        createdAt: '2027-02-15T08:00:00Z',
    },
    {
        id: 2,
        uuid: 'pabd-uuid-002',
        kode: 'PABD-2027-KA-02',
        team: 'Divisi Pendidikan',
        bulan: 'Februari',
        bulanNum: 2,
        tahun: 2027,
        status: 'completed' as const,
        currentStep: 'PABD05',
        createdAt: '2027-01-15T08:00:00Z',
    },
    {
        id: 3,
        uuid: 'pabd-uuid-003',
        kode: 'PABD-2027-MG-03',
        team: 'Divisi MUGER',
        bulan: 'Maret',
        bulanNum: 3,
        tahun: 2027,
        status: 'active' as const,
        currentStep: 'PABD02B',
        createdAt: '2027-02-15T08:00:00Z',
    },
    {
        id: 4,
        uuid: 'pabd-uuid-004',
        kode: 'PABD-2027-DW-03',
        team: 'Divisi Dewasa',
        bulan: 'Maret',
        bulanNum: 3,
        tahun: 2027,
        status: 'active' as const,
        currentStep: 'PABD03',
        createdAt: '2027-02-15T08:00:00Z',
    },
];

export const pabdHistory = [
    { action: 'created', by: 'Sistem', role: 'System', at: '2027-02-15T08:00:00Z', table: 'pabd01_data' },
    { action: 'drafted', by: 'Rina Wijaya', role: 'Ketua', team: 'Divisi Pendidikan', at: '2027-02-18T10:00:00Z', table: 'pabd01_data' },
    { action: 'commented', by: 'Rina Wijaya', role: 'Ketua', team: 'Divisi Pendidikan', at: '2027-02-19T10:00:00Z', notes: 'Perlu konfirmasi item konsumsi yang ditarik maju' },
    { action: 'commented', by: 'Sari Dewi', role: 'Bendahara Umum 1', team: 'Tim Bendahara Umum', at: '2027-02-19T14:00:00Z', notes: 'Silakan lanjutkan, plafon masih mencukupi', files: ['memo_plafon_2027.pdf'] },
    {
        action: 'submitted',
        by: 'Rina Wijaya',
        role: 'Ketua',
        team: 'Divisi Pendidikan',
        at: '2027-02-20T14:00:00Z',
        table: 'pabd01_data',
        notes: 'Ada perubahan tarik maju untuk konsumsi',
    },
    { action: 'drafted', by: 'Rina Wijaya', role: 'Ketua', team: 'Divisi Pendidikan', at: '2027-02-21T09:00:00Z', table: 'pabd02a_data' },
    { action: 'submitted', by: 'Rina Wijaya', role: 'Ketua', team: 'Divisi Pendidikan', at: '2027-02-22T11:00:00Z', table: 'pabd02a_data', files: ['surat_permohonan_tarik_maju.pdf'] },
];

export const pabdComments = [
    {
        by: 'Rina Wijaya',
        role: 'Ketua',
        at: '2027-02-19T10:00:00Z',
        text: 'Perlu konfirmasi item konsumsi yang ditarik maju',
    },
    {
        by: 'Sari Dewi',
        role: 'Bendahara Umum 1',
        at: '2027-02-19T14:00:00Z',
        text: 'Silakan lanjutkan, plafon masih mencukupi',
    },
];

export const pabdSteps = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const },
    { code: 'PABD02B', label: 'Persetujuan', status: 'active' as const },
    { code: 'PABD03', label: 'Verifikasi', status: 'pending' as const },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'pending' as const },
    { code: 'PABD05', label: 'Final', status: 'pending' as const },
];

export const pabd01Anggaran = [
    {
        id: 1,
        kodeAnggaran: '04.02.10.01.01.003.001.001.2027.03.00',
        mataAnggaran: 'Konsumsi',
        nominal: 750000,
        kegiatan: 'Persiapan Perayaan Tahunan',
        program: 'PERAYAAN TAHUNAN',
        programKode: 'PK-2027-003',
        bulan: 'Maret',
        statusItem: 'active',
        checked: true,
    },
    {
        id: 2,
        kodeAnggaran: '04.02.10.01.01.003.001.002.2027.03.00',
        mataAnggaran: 'Alat peraga',
        nominal: 500000,
        kegiatan: 'Persiapan Perayaan Tahunan',
        program: 'PERAYAAN TAHUNAN',
        programKode: 'PK-2027-003',
        bulan: 'Maret',
        statusItem: 'active',
        checked: true,
    },
    {
        id: 3,
        kodeAnggaran: '04.02.10.01.01.001.001.001.2027.03.00',
        mataAnggaran: 'Snack',
        nominal: 200000,
        kegiatan: 'Pelaksanaan SM Bulan Maret',
        program: 'SEKOLAH MINGGU RUTIN',
        programKode: 'PK-2027-001',
        bulan: 'Maret',
        statusItem: 'active',
        checked: true,
    },
    {
        id: 4,
        kodeAnggaran: '04.02.10.01.01.001.001.002.2027.03.00',
        mataAnggaran: 'Alat tulis',
        nominal: 150000,
        kegiatan: 'Pelaksanaan SM Bulan Maret',
        program: 'SEKOLAH MINGGU RUTIN',
        programKode: 'PK-2027-001',
        bulan: 'Maret',
        statusItem: 'active',
        checked: false,
    },
    {
        id: 5,
        kodeAnggaran: '04.02.10.01.01.001.002.001.2027.03.00',
        mataAnggaran: 'Konsumsi retret',
        nominal: 0,
        kegiatan: 'Retret Anak',
        program: 'SEKOLAH MINGGU RUTIN',
        programKode: 'PK-2027-001',
        bulan: 'Maret',
        statusItem: 'tarik_maju',
        checked: false,
    },
];

// Available anggaran items from PK04 for selection in PABD02A
// Tarik Maju: active items from future months (bulan > bulan_anggaran)
// Tarik Mundur: active items from current month (bulan = bulan_anggaran)
export const pabd02aAvailableItems = {
    tarik_maju: [
        { id: 10, kode: '04.02.10.01.01.003.001.001.2027.05.00', mata: 'Konsumsi', nominal: 750000, kegiatan: 'Persiapan Perayaan Tahunan', bulan: 'Mei', bulanNum: 5 },
        { id: 11, kode: '04.02.10.01.01.001.002.001.2027.06.00', mata: 'Transport', nominal: 300000, kegiatan: 'Retret Anak', bulan: 'Juni', bulanNum: 6 },
        { id: 12, kode: '04.02.10.01.01.004.001.001.2027.12.00', mata: 'Dekorasi', nominal: 400000, kegiatan: 'Perayaan Akhir Tahun Anak', bulan: 'Desember', bulanNum: 12 },
        { id: 13, kode: '04.02.10.01.01.001.001.002.2027.04.00', mata: 'Alat peraga', nominal: 250000, kegiatan: 'Pelaksanaan SM', bulan: 'April', bulanNum: 4 },
        { id: 14, kode: '04.02.10.01.01.001.001.001.2027.05.00', mata: 'Snack', nominal: 200000, kegiatan: 'Pelaksanaan SM', bulan: 'Mei', bulanNum: 5 },
    ],
    tarik_mundur: [
        { id: 1, kode: '04.02.10.01.01.003.001.001.2027.03.00', mata: 'Konsumsi', nominal: 750000, kegiatan: 'Persiapan Perayaan Tahunan', bulan: 'Maret', bulanNum: 3 },
        { id: 2, kode: '04.02.10.01.01.003.001.002.2027.03.00', mata: 'Alat peraga', nominal: 500000, kegiatan: 'Persiapan Perayaan Tahunan', bulan: 'Maret', bulanNum: 3 },
        { id: 3, kode: '04.02.10.01.01.001.001.001.2027.03.00', mata: 'Snack', nominal: 200000, kegiatan: 'Pelaksanaan SM Bulan Maret', bulan: 'Maret', bulanNum: 3 },
        { id: 4, kode: '04.02.10.01.01.001.001.002.2027.03.00', mata: 'Alat tulis', nominal: 150000, kegiatan: 'Pelaksanaan SM Bulan Maret', bulan: 'Maret', bulanNum: 3 },
    ],
};

// Bulan Tujuan options
// Tarik Maju: bulan <= bulan_anggaran (Maret = 3)
// Tarik Mundur: bulan > bulan_anggaran
export const bulanOptions = [
    { value: 1, label: 'Januari' },
    { value: 2, label: 'Februari' },
    { value: 3, label: 'Maret' },
    { value: 4, label: 'April' },
    { value: 5, label: 'Mei' },
    { value: 6, label: 'Juni' },
    { value: 7, label: 'Juli' },
    { value: 8, label: 'Agustus' },
    { value: 9, label: 'September' },
    { value: 10, label: 'Oktober' },
    { value: 11, label: 'November' },
    { value: 12, label: 'Desember' },
];

export const pabd02aPerubahan = [
    {
        id: 1,
        tipe: 'tarik_maju' as const,
        selectedItemId: 10,
        bulanAwalNum: 5,
        bulanTujuanNum: 3,
        nominal: 750000,
        alasan: 'Kegiatan persiapan perlu konsumsi lebih awal untuk koordinasi dengan vendor',
    },
    {
        id: 2,
        tipe: 'tarik_mundur' as const,
        selectedItemId: 4,
        bulanAwalNum: 3,
        bulanTujuanNum: 4,
        nominal: 150000,
        alasan: 'Alat tulis belum perlu bulan ini, mundurkan ke April',
    },
    {
        id: 3,
        tipe: 'penambahan' as const,
        program: 'Pelatihan Darurat Guru SM',
        kegiatan: 'Pelatihan Darurat',
        bulanNum: 3,
        anggaran: { mataAnggaran: 'Honorarium', deskripsi: 'Honor narasumber pelatihan darurat', nominal: 2000000 },
        alasan: 'Dibutuhkan pelatihan mendadak untuk guru SM baru yang bergabung bulan ini',
        lampiran: 'proposal_pelatihan.pdf',
    },
];

export const pabd03DaftarPencairan = [
    {
        program: 'PERAYAAN TAHUNAN',
        programKode: 'PK-2027-003',
        kegiatan: [
            {
                nama: 'Persiapan Perayaan Tahunan',
                bulan: 'Maret',
                items: [
                    { kode: '04.02.10.01.01.003.001.001.2027.03.00', mata: 'Konsumsi', nominal: 750000 },
                    { kode: '04.02.10.01.01.003.001.002.2027.03.00', mata: 'Alat peraga', nominal: 500000 },
                ],
                subtotal: 1250000,
            },
        ],
    },
    {
        program: 'SEKOLAH MINGGU RUTIN',
        programKode: 'PK-2027-001',
        kegiatan: [
            {
                nama: 'Pelaksanaan SM Bulan Maret',
                bulan: 'Maret',
                items: [{ kode: '04.02.10.01.01.001.001.001.2027.03.00', mata: 'Snack', nominal: 200000 }],
                subtotal: 200000,
            },
        ],
    },
];

export const pabd05FinalData = {
    team: 'Divisi Pendidikan',
    bulan: 'Februari',
    bulanNum: 2,
    tahun: 2027,
    diajukanOleh: 'Rina Wijaya (Ketua)',
    tanggalSubmit: '20 Jan 2027, 10:00',
    adaPerubahan: false,
    perubahanDisetujui: null as string | null,
    verifikasiOleh: 'Sari Dewi (Bendahara Umum 1)',
    tanggalVerifikasi: '25 Jan 2027, 14:00',
    buktiOleh: 'Rina Wijaya (Ketua)',
    tanggalBukti: '27 Jan 2027, 09:00',
    rekeningTim: {
        nama: 'Rina Wijaya',
        nomor: '0881234501',
        bank: 'BCA',
    },
    totalDicairkan: 1450000,
    totalItem: 3,
    itemHangus: 0,
    daftarPencairan: pabd03DaftarPencairan,
    buktiTransfer: ['bukti_transfer_feb_2027.jpg'],
};

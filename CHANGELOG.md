# Changelog

Semua perubahan penting pada aplikasi ini akan dicatat di sini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/),
dan project ini mengikuti [Semantic Versioning](https://semver.org/).

---

## [2.0.0] — 2026-04-28

Rilis perdana versi 2 — penulisan ulang total dari aplikasi Finance Playground lama.
Migrasi dari arsitektur lama ke stack Laravel 12 + Inertia 2 + React 19.

### Added — Workflow

- **PP (Perencanaan Periode)** — workflow penyusunan rencana 5-tahunan tim,
  termasuk kuisioner, penetapan rekening organisasi, dan kompilasi PP05.
- **PK (Perencanaan Kerja)** — workflow penyusunan rencana kerja tahunan,
  termasuk anggaran detail dan kompilasi snapshot tahunan PK05.
- **PABD (Pencairan Anggaran Bulanan Dana)** — workflow pencairan bulanan
  dengan dua jalur (langsung & batch change), persetujuan BU, upload bukti
  transfer Kantor Pusat, dan kompilasi PABD05.
- **PRBL (Pelaporan Bulanan)** — workflow pelaporan bulanan dengan
  laporan kegiatan, evaluasi paralel narasi & anggaran, kalkulasi refund,
  review final BU, dan kompilasi PRBL05.

### Added — Sistem

- Multi-workspace (tenant) dengan scoping per workspace di seluruh data.
- RBAC granular berbasis role + permission, dengan 60+ permission khusus
  untuk PABD/PRBL dan permission admin terpisah.
- Sistem notifikasi (in-app + email) untuk setiap step workflow.
- Dashboard personal, tim, dan admin dengan task queue per role.
- Sistem file upload terstandarisasi dengan permission `admin.files.*`.
- Email aktivasi & reset password (terlokalisasi Bahasa Indonesia).
- Manajemen admin: user, role/permission, workspace, organization & team,
  trash & restore, recompile PABD.

### Added — UX

- Helpers waktu WIB konsisten (`formatDateTime`, `formatDate`).
- Komponen status badge dengan warna konsisten dan kode anggaran tooltip.
- Copy buttons di tabel anggaran/realisasi untuk akses cepat ke nilai.
- `kode_anggaran_lama` ditampilkan berdampingan dengan kode baru.
- Dokumen panduan (PDF manual) — Bab 00–11 dalam tahap draft.

### Changed

- Migrasi penuh dari aplikasi lama; tidak ada path upgrade dari v1.
- Domain utama akan dipindahkan ke aplikasi baru ini, aplikasi lama
  dipensiunkan.

### Notes

- Versi ini adalah snapshot rilis pertama. Beberapa bab manual masih
  berstatus DRAFT dan akan disempurnakan setelah browser testing demo.
- Lihat tag git `v2.0.0` untuk commit canonical rilis ini.

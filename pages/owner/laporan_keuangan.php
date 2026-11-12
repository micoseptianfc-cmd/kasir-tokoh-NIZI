<?php
// pages/owner/laporan_keuangan.php
session_start();
if (!isset($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}
require_once '../../config/koneksi.php';

$pesan = $_SESSION['pesan_pengeluaran'] ?? "";
unset($_SESSION['pesan_pengeluaran']);

if (empty($_SESSION['csrf_token_laporan_keuangan'])) {
    $_SESSION['csrf_token_laporan_keuangan'] = bin2hex(random_bytes(32));
}

// Filter Tanggal
$tgl_mulai = isset($_GET['tgl_mulai']) ? $_GET['tgl_mulai'] : date('Y-m-01');
$tgl_selesai = isset($_GET['tgl_selesai']) ? $_GET['tgl_selesai'] : date('Y-m-t');
$label_periode = date('d/m/Y', strtotime($tgl_mulai)) . ' - ' . date('d/m/Y', strtotime($tgl_selesai));

$pesan_gagal = false;

// Hapus satu catatan arus keluar dari periode yang sedang ditampilkan.
if (isset($_POST['hapus_arus_keluar'])) {
    $token = $_POST['csrf_token'] ?? '';
    $tipe = $_POST['tipe_arus'] ?? '';
    $id_catatan = filter_var($_POST['id_catatan'] ?? null, FILTER_VALIDATE_INT);
    $tanggal_mulai_hapus = $_POST['tgl_mulai'] ?? '';
    $tanggal_selesai_hapus = $_POST['tgl_selesai'] ?? '';
    $tanggal_valid = static function ($tanggal) {
        if (!is_string($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return false;
        }
        $bagian = explode('-', $tanggal);
        return checkdate((int)$bagian[1], (int)$bagian[2], (int)$bagian[0]);
    };

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token_laporan_keuangan'], $token)) {
        $pesan = "Permintaan tidak valid. Muat ulang halaman lalu coba kembali.";
        $pesan_gagal = true;
    } elseif (
        !$id_catatan ||
        !in_array($tipe, ['manual', 'stok'], true) ||
        !$tanggal_valid($tanggal_mulai_hapus) ||
        !$tanggal_valid($tanggal_selesai_hapus) ||
        $tanggal_mulai_hapus > $tanggal_selesai_hapus
    ) {
        $pesan = "Data catatan atau rentang tanggal tidak valid.";
        $pesan_gagal = true;
    } else {
        try {
            $pdo->beginTransaction();

            if ($tipe === 'manual') {
                $stmt_hapus = $pdo->prepare(
                    "DELETE FROM pengeluaran WHERE id_pengeluaran = ? AND tanggal BETWEEN ? AND ?"
                );
                $stmt_hapus->execute([$id_catatan, $tanggal_mulai_hapus, $tanggal_selesai_hapus]);
                if ($stmt_hapus->rowCount() !== 1) {
                    throw new RuntimeException("Catatan pengeluaran tidak ditemukan pada periode tersebut.");
                }
                $pesan_hapus = "Catatan pengeluaran berhasil dihapus.";
            } else {
                $stmt_stok = $pdo->prepare(
                    "SELECT produk_id, jumlah FROM stok_masuk WHERE id_stok = ? AND tanggal BETWEEN ? AND ? FOR UPDATE"
                );
                $stmt_stok->execute([$id_catatan, $tanggal_mulai_hapus, $tanggal_selesai_hapus]);
                $catatan_stok = $stmt_stok->fetch();
                if (!$catatan_stok) {
                    throw new RuntimeException("Catatan stok masuk tidak ditemukan pada periode tersebut.");
                }

                $cek_id_produk = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
                $kolom_id_produk = $cek_id_produk->rowCount() > 0 ? 'id' : 'id_produk';
                $stmt_produk = $pdo->prepare("SELECT stok FROM produk WHERE $kolom_id_produk = ? FOR UPDATE");
                $stmt_produk->execute([$catatan_stok['produk_id']]);
                $stok_produk = $stmt_produk->fetchColumn();

                if ($stok_produk === false) {
                    throw new RuntimeException("Produk pada catatan stok tidak ditemukan. Catatan tidak dihapus.");
                }
                if ((int)$stok_produk < (int)$catatan_stok['jumlah']) {
                    throw new RuntimeException("Stok tersedia lebih sedikit daripada jumlah yang dikoreksi. Catatan tidak dihapus agar stok tidak menjadi negatif.");
                }

                $stmt_koreksi_stok = $pdo->prepare(
                    "UPDATE produk SET stok = stok - ? WHERE $kolom_id_produk = ? AND stok >= ?"
                );
                $stmt_koreksi_stok->execute([
                    $catatan_stok['jumlah'],
                    $catatan_stok['produk_id'],
                    $catatan_stok['jumlah']
                ]);
                if ($stmt_koreksi_stok->rowCount() !== 1) {
                    throw new RuntimeException("Stok produk gagal dikoreksi. Catatan tidak dihapus.");
                }

                $stmt_hapus = $pdo->prepare(
                    "DELETE FROM stok_masuk WHERE id_stok = ? AND tanggal BETWEEN ? AND ?"
                );
                $stmt_hapus->execute([$id_catatan, $tanggal_mulai_hapus, $tanggal_selesai_hapus]);
                if ($stmt_hapus->rowCount() !== 1) {
                    throw new RuntimeException("Catatan stok masuk gagal dihapus.");
                }
                $pesan_hapus = "Catatan stok masuk dihapus dan jumlah stok produk berhasil dikoreksi.";
            }

            $pdo->commit();
            $_SESSION['pesan_pengeluaran'] = $pesan_hapus;
            header("Location: laporan_keuangan.php?" . http_build_query([
                'tgl_mulai' => $tanggal_mulai_hapus,
                'tgl_selesai' => $tanggal_selesai_hapus
            ]));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan = "Gagal menghapus catatan: " . $e->getMessage();
            $pesan_gagal = true;
        }
    }
}

// Bersihkan seluruh data keuangan dalam periode yang sedang ditampilkan.
if (isset($_POST['bersihkan_data'])) {
    $token = $_POST['csrf_token'] ?? '';
    $tanggal_mulai_hapus = $_POST['tgl_mulai'] ?? '';
    $tanggal_selesai_hapus = $_POST['tgl_selesai'] ?? '';
    $tanggal_valid = static function ($tanggal) {
        if (!is_string($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return false;
        }
        $bagian = explode('-', $tanggal);
        return checkdate((int)$bagian[1], (int)$bagian[2], (int)$bagian[0]);
    };

    if (!hash_equals($_SESSION['csrf_token_laporan_keuangan'], $token)) {
        $pesan = "Permintaan tidak valid. Muat ulang halaman lalu coba kembali.";
        $pesan_gagal = true;
    } elseif (
        !$tanggal_valid($tanggal_mulai_hapus) ||
        !$tanggal_valid($tanggal_selesai_hapus) ||
        $tanggal_mulai_hapus > $tanggal_selesai_hapus
    ) {
        $pesan = "Rentang tanggal tidak valid. Periksa tanggal mulai dan tanggal selesai.";
        $pesan_gagal = true;
    } else {
        $tanggal_transaksi_hapus = "COALESCE(DATE(NULLIF(tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(no_struk, 7, 8), '%Y%m%d'))";

        try {
            $pdo->beginTransaction();

            $stmt_detail_hapus = $pdo->prepare(
                "DELETE d FROM detail_transaksi d
                 INNER JOIN transaksi t ON t.id_transaksi = d.id_transaksi
                 WHERE COALESCE(DATE(NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d')) BETWEEN ? AND ?"
            );
            $stmt_detail_hapus->execute([$tanggal_mulai_hapus, $tanggal_selesai_hapus]);
            $jumlah_detail = $stmt_detail_hapus->rowCount();

            $stmt_transaksi_hapus = $pdo->prepare(
                "DELETE FROM transaksi WHERE $tanggal_transaksi_hapus BETWEEN ? AND ?"
            );
            $stmt_transaksi_hapus->execute([$tanggal_mulai_hapus, $tanggal_selesai_hapus]);
            $jumlah_transaksi = $stmt_transaksi_hapus->rowCount();

            $stmt_pengeluaran_hapus = $pdo->prepare(
                "DELETE FROM pengeluaran WHERE tanggal BETWEEN ? AND ?"
            );
            $stmt_pengeluaran_hapus->execute([$tanggal_mulai_hapus, $tanggal_selesai_hapus]);
            $jumlah_pengeluaran = $stmt_pengeluaran_hapus->rowCount();

            $jumlah_stok = 0;
            if ($pdo->query("SHOW TABLES LIKE 'stok_masuk'")->rowCount() > 0) {
                $stmt_stok_hapus = $pdo->prepare(
                    "DELETE FROM stok_masuk WHERE tanggal BETWEEN ? AND ?"
                );
                $stmt_stok_hapus->execute([$tanggal_mulai_hapus, $tanggal_selesai_hapus]);
                $jumlah_stok = $stmt_stok_hapus->rowCount();
            }

            $pdo->commit();

            $jumlah_total = $jumlah_transaksi + $jumlah_detail + $jumlah_pengeluaran + $jumlah_stok;
            $_SESSION['pesan_pengeluaran'] = $jumlah_total > 0
                ? "Data periode " . $tanggal_mulai_hapus . " s/d " . $tanggal_selesai_hapus . " berhasil dibersihkan. Transaksi: $jumlah_transaksi, rincian: $jumlah_detail, pengeluaran: $jumlah_pengeluaran, stok masuk: $jumlah_stok."
                : "Tidak ada data pada periode " . $tanggal_mulai_hapus . " s/d " . $tanggal_selesai_hapus . " untuk dibersihkan.";

            header("Location: laporan_keuangan.php?" . http_build_query([
                'tgl_mulai' => $tanggal_mulai_hapus,
                'tgl_selesai' => $tanggal_selesai_hapus
            ]));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan = "Gagal membersihkan data keuangan: " . $e->getMessage();
            $pesan_gagal = true;
        }
    }
}

$tanggal_transaksi = "COALESCE(DATE(NULLIF(tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(no_struk, 7, 8), '%Y%m%d'))";

// 1. Hitung pemasukan; tanggal pada nomor struk menjadi fallback untuk data lama tanpa waktu.
$stmt_masuk = $pdo->prepare("SELECT SUM(total_belanja) as total_masuk FROM transaksi WHERE $tanggal_transaksi BETWEEN ? AND ?");
$stmt_masuk->execute([$tgl_mulai, $tgl_selesai]);
$pemasukan = $stmt_masuk->fetch()['total_masuk'] ?? 0;
$pemasukan_bon = 0;
$data_bon_lunas = [];
$pengeluaran_gaji = 0;
$data_gaji_dibayar = [];
$ada_catatan_pribadi = $pdo->query("SHOW TABLES LIKE 'catatan_pribadi'")->rowCount() > 0;
if ($ada_catatan_pribadi) {
    $kolom_catatan = $pdo->query('SHOW COLUMNS FROM catatan_pribadi')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('paid_at', $kolom_catatan, true)) {
        $pdo->exec('ALTER TABLE catatan_pribadi ADD COLUMN paid_at DATE NULL');
    }
    $pdo->exec(
        "UPDATE catatan_pribadi
         SET paid_at = tanggal
         WHERE paid_at IS NULL AND status = 'sudah'
           AND kategori IN ('bon', 'gaji_karyawan') AND tanggal IS NOT NULL"
    );
    $stmt_bon = $pdo->prepare(
        "SELECT id, judul, paid_at, nominal FROM catatan_pribadi
         WHERE kategori = 'bon' AND status = 'sudah' AND paid_at BETWEEN ? AND ?
         ORDER BY paid_at DESC, id DESC"
    );
    $stmt_bon->execute([$tgl_mulai, $tgl_selesai]);
    $data_bon_lunas = $stmt_bon->fetchAll();
    foreach ($data_bon_lunas as $bon_lunas) {
        $pemasukan_bon += (float)$bon_lunas['nominal'];
    }
    $pemasukan += $pemasukan_bon;
}

// 2. Hitung Total Pengeluaran dari Tabel Pengeluaran Manual
$stmt_keluar = $pdo->prepare("SELECT SUM(jumlah_biaya) as total_keluar FROM pengeluaran WHERE tanggal BETWEEN ? AND ?");
$stmt_keluar->execute([$tgl_mulai, $tgl_selesai]);
$pengeluaran_manual = $stmt_keluar->fetch()['total_keluar'] ?? 0;
if ($ada_catatan_pribadi) {
    $stmt_gaji = $pdo->prepare(
        "SELECT id, judul, paid_at, nominal FROM catatan_pribadi
         WHERE kategori = 'gaji_karyawan' AND status = 'sudah' AND paid_at BETWEEN ? AND ?
         ORDER BY paid_at DESC, id DESC"
    );
    $stmt_gaji->execute([$tgl_mulai, $tgl_selesai]);
    $data_gaji_dibayar = $stmt_gaji->fetchAll();
    foreach ($data_gaji_dibayar as $gaji_dibayar) {
        $pengeluaran_gaji += (float)$gaji_dibayar['nominal'];
    }
}

// 3. Hitung Total Pengeluaran dari Stok Masuk (Kulakan / Pembelian Barang)
$pengeluaran_stok = 0;
$data_stok_masuk = [];
try {
    // Cek apakah tabel stok_masuk ada
    $check_stok = $pdo->query("SHOW TABLES LIKE 'stok_masuk'")->rowCount();
    if ($check_stok > 0) {
        $check_id_produk = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
        $kolom_id_produk = $check_id_produk->rowCount() > 0 ? 'id' : 'id_produk';
        $sql_stok = "SELECT sm.id_stok, sm.produk_id, sm.jumlah, sm.tanggal, CONCAT('Kulakan: ', p.nama_produk, ' (', sm.jumlah, ' ', p.satuan, ')') as keterangan, (sm.jumlah * p.harga_beli) as total_biaya 
                     FROM stok_masuk sm 
                     LEFT JOIN produk p ON sm.produk_id = p.$kolom_id_produk 
                     WHERE sm.tanggal BETWEEN ? AND ?";
        $stmt_s = $pdo->prepare($sql_stok);
        $stmt_s->execute([$tgl_mulai, $tgl_selesai]);
        $data_stok_masuk = $stmt_s->fetchAll();

        foreach($data_stok_masuk as $ds) {
            $pengeluaran_stok += (float)$ds['total_biaya'];
        }
    }
} catch (Exception $e) {
    $pengeluaran_stok = 0;
}

// Total pengeluaran mencakup biaya lama, kulakan, dan gaji yang sudah dibayar.
$total_pengeluaran = $pengeluaran_manual + $pengeluaran_stok + $pengeluaran_gaji;

// Hitung Selisih (Laba Bersih)
$laba_bersih = $pemasukan - $total_pengeluaran;

// Ambil Riwayat Pengeluaran Manual
$stmt_list_keluar = $pdo->prepare("SELECT id_pengeluaran, tanggal, keterangan, jumlah_biaya FROM pengeluaran WHERE tanggal BETWEEN ? AND ?");
$stmt_list_keluar->execute([$tgl_mulai, $tgl_selesai]);
$data_pengeluaran_manual = $stmt_list_keluar->fetchAll();

// Gabungkan riwayat pengeluaran manual dan pengeluaran stok masuk untuk ditampilkan bersamaan
$gabungan_riwayat = array_merge(array_map(function($item) {
    return [
        'tipe' => 'manual',
        'id_catatan' => $item['id_pengeluaran'],
        'tanggal' => $item['tanggal'],
        'keterangan' => $item['keterangan'],
        'jumlah_biaya' => $item['jumlah_biaya']
    ];
}, $data_pengeluaran_manual), array_map(function($item) {
    return [
        'tipe' => 'stok',
        'id_catatan' => $item['id_stok'],
        'tanggal' => $item['tanggal'],
        'keterangan' => $item['keterangan'],
        'jumlah_biaya' => $item['total_biaya']
    ];
}, $data_stok_masuk));
if ($data_gaji_dibayar) {
    $gabungan_riwayat = array_merge($gabungan_riwayat, array_map(function($item) {
        return [
            'tipe' => 'gaji',
            'id_catatan' => $item['id'],
            'tanggal' => $item['paid_at'],
            'keterangan' => 'Gaji karyawan: ' . $item['judul'],
            'jumlah_biaya' => $item['nominal']
        ];
    }, $data_gaji_dibayar));
}

// Urutkan berdasarkan tanggal terbaru
usort($gabungan_riwayat, function($a, $b) {
    return strtotime($b['tanggal']) - strtotime($a['tanggal']);
});
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan & Arus Kas - Toko Nizi</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #eef2f5; display: flex; height: 100vh; overflow: hidden; }
        
        .sidebar { width: 270px; background: #1e2b3c; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 25px 20px; box-shadow: 2px 0 10px rgba(0,0,0,0.1); z-index: 10; }
        .sidebar h2 { font-size: 28px; color: #fff; letter-spacing: 1px; margin-bottom: 2px; }
        .subtitle { font-size: 11px; color: #e74c3c; letter-spacing: 2px; margin-bottom: 20px; display: block; border-bottom: 1px solid #34495e; padding-bottom: 15px; }
        
        .menu-label { font-size: 11px; color: #7f8c8d; font-weight: bold; letter-spacing: 1px; margin-bottom: 12px; margin-top: 15px; display: block; }
        .menu-list { list-style: none; }
        .menu-list li { margin-bottom: 12px; }
        .menu-list a { color: #ecf0f1; text-decoration: none; font-size: 15px; font-weight: 500; display: flex; align-items: center; padding: 14px 15px; border-radius: 8px; background: #2c3e50; border: 1px solid #3a5068; box-shadow: 0 5px 0 #151e27; transition: all 0.15s ease; }
        .menu-list a:hover { background: #34495e; transform: translateY(-2px); box-shadow: 0 7px 0 #151e27; }
        .menu-list a.active { background: #e74c3c; border-color: #c0392b; box-shadow: 0 5px 0 #962d22; color: white; font-weight: bold; }
        .btn-keluar { background: #2c3e50 !important; color: #e74c3c !important; }
        
        .main-content { flex: 1; display: flex; flex-direction: column; padding: 20px 30px; overflow-y: auto; }
        .header-box { background: white; padding: 20px 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        
        .cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-left: 5px solid #2980b9; }
        .card.hijau { border-left-color: #27ae60; }
        .card.merah { border-left-color: #c0392b; }
        .card span { font-size: 13px; color: #7f8c8d; font-weight: bold; text-transform: uppercase; }
        .card h2 { font-size: 26px; font-weight: bold; margin-top: 5px; color: #2c3e50; }

        .content-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
        .panel { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; }
        .panel h3 { margin-bottom: 15px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; color: #34495e; margin-bottom: 5px; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; font-size: 15px; box-shadow: 0 4px 0 #1e8449; }
        .btn-submit:hover { background: #2ecc71; }

        .table-container { max-height: 250px; overflow-y: auto; border: 1px solid #ecf0f1; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #34495e; color: white; padding: 10px; text-align: left; font-size: 13px; position: sticky; top: 0; }
        table td { padding: 10px; text-align: left; border-bottom: 1px solid #ecf0f1; font-size: 13px; color: #333; }
        table tr:nth-child(even) { background-color: #f8f9fa; }
        
        .filter-box { background: white; padding: 15px 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; }
        .alert-sukses { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #c3e6cb; font-size: 14px; }
        .alert-gagal { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #f5c6cb; font-size: 14px; }
        .clear-data-box { display: flex; justify-content: space-between; align-items: center; gap: 15px; background: #fff5f5; border: 1px solid #f1b0b7; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; color: #721c24; font-size: 13px; }
        .btn-clear-data { background: #c0392b; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: bold; cursor: pointer; white-space: nowrap; }
        .btn-clear-data:hover { background: #a93226; }
        .btn-hapus-baris { background: #c0392b; color: white; border: none; padding: 6px 9px; border-radius: 5px; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-hapus-baris:hover { background: #a93226; }
        .btn-cetak { background: #8e44ad; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: bold; cursor: pointer; height: 38px; white-space: nowrap; }
        .btn-cetak:hover { background: #732d91; }
        .periode-cetak { display: none; }
        @media (max-width: 700px) { .clear-data-box { align-items: flex-start; flex-direction: column; } }
        @media print {
            @page { size: landscape; margin: 12mm; }
            body { display: block; height: auto; overflow: visible; background: white; }
            .sidebar, .no-print { display: none !important; }
            .main-content { display: block; overflow: visible; padding: 0; }
            .header-box, .panel, .card { box-shadow: none; border: 1px solid #d7dce0; }
            .header-box { margin-bottom: 10px; padding: 12px 16px; }
            .periode-cetak { display: block; margin-top: 6px; color: #555; font-size: 12px; }
            .cards-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 10px; }
            .card { padding: 12px; break-inside: avoid; }
            .card h2 { font-size: 20px; }
            .content-grid { display: block; }
            .panel { display: block; padding: 14px; }
            .table-container { max-height: none; overflow: visible; border: 0; }
            table { width: 100%; }
            table th { position: static; background: #34495e !important; color: white !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            table tr { break-inside: avoid; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div>
            <h2>Panel Pemilik</h2>
            <span class="subtitle">ADMINISTRATOR AREA</span>
            <span class="menu-label">LAPORAN & KEUANGAN</span>
            <ul class="menu-list">
                <li><a href="laporan.php">📊 Rekap Laporan</a></li>
                <li><a href="riwayat_penjualan.php">📋 Riwayat Penjualan</a></li>
                <li><a href="laporan_keuangan.php" class="active">💰 Laporan Keuangan</a></li>
            </ul>
            <span class="menu-label">MANAJEMEN TOKO</span>
            <ul class="menu-list">
                <li><a href="kelola_produk.php">📦 Kelola Produk</a></li>
                <li><a href="stok_masuk.php">📥 Stok Masuk</a></li>
                <li><a href="notes_pribadi.php">📝 Notes Pribadi</a></li>
            </ul>
        </div>
        <div>
            <ul class="menu-list">
                <li><a href="../kasir.php" style="background: #2980b9;">🖥️ Ke Kasir</a></li>
                <li><a href="../../proses/proses_logout.php" class="btn-keluar">🚪 Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <div class="header-box">
            <div>
                <h1>Laporan Keuangan & Arus Kas Toko</h1>
                <p style="font-size: 13px; color: #7f8c8d;">Pemasukan otomatis dari penjualan kasir dan pengeluaran otomatis dari stok masuk/kulakan.</p>
                <p class="periode-cetak">Periode laporan: <?= htmlspecialchars($label_periode) ?></p>
            </div>
        </div>

        <?php if(!empty($pesan)): ?>
            <div class="no-print <?= $pesan_gagal ? 'alert-gagal' : 'alert-sukses' ?>"><?= htmlspecialchars($pesan) ?></div>
        <?php endif; ?>

        <!-- Filter Periode -->
        <form method="GET" class="filter-box no-print" id="filter-periode">
            <div class="form-group" style="margin-bottom:0;">
                <label>Pilih Periode Cepat:</label>
                <select id="periode-cepat" style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; height: 38px;">
                    <option value="">Pilih harian / mingguan / bulanan / tahunan</option>
                    <option value="harian">Hari ini</option>
                    <option value="mingguan">Minggu ini</option>
                    <option value="bulanan">Bulan ini</option>
                    <option value="tahunan">Tahun ini</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Dari Tanggal:</label>
                <input type="date" name="tgl_mulai" value="<?= htmlspecialchars($tgl_mulai) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Sampai Tanggal:</label>
                <input type="date" name="tgl_selesai" value="<?= htmlspecialchars($tgl_selesai) ?>">
            </div>
            <button type="submit" style="background: #2980b9; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; height: 38px;">Filter Data</button>
            <button type="button" class="btn-cetak" onclick="window.print();">🖨️ Cetak / Simpan PDF</button>
        </form>

        <form method="POST" class="clear-data-box no-print" onsubmit="return confirm('PERINGATAN: Semua transaksi, rincian produk, pengeluaran, dan data stok masuk pada periode ini akan dihapus permanen. Jumlah stok produk tidak berubah. Lanjutkan?');">
            <div>
                <strong>Bersihkan data periode laporan</strong><br>
                Menghapus transaksi penjualan, rincian produk, pengeluaran manual, dan catatan stok masuk untuk tanggal yang sedang dipilih. Jumlah stok produk tidak diubah.
            </div>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_laporan_keuangan']) ?>">
            <input type="hidden" name="tgl_mulai" value="<?= htmlspecialchars($tgl_mulai) ?>">
            <input type="hidden" name="tgl_selesai" value="<?= htmlspecialchars($tgl_selesai) ?>">
            <button type="submit" name="bersihkan_data" class="btn-clear-data">🗑️ Bersihkan Data</button>
        </form>

        <!-- Ringkasan Kartu Keuangan -->
        <div class="cards-grid">
            <div class="card hijau">
                <span>Total Pemasukan (Penjualan &amp; Bon Lunas)</span>
                <h2 style="color: #27ae60;">Rp <?= number_format($pemasukan) ?></h2>
            </div>
            <div class="card merah">
                <span>Total Pengeluaran (Kulakan, Biaya &amp; Gaji Dibayar)</span>
                <h2 style="color: #c0392b;">Rp <?= number_format($total_pengeluaran) ?></h2>
            </div>
            <div class="card">
                <span>Perkiraan Laba Bersih</span>
                <h2 style="color: #2980b9;">Rp <?= number_format($laba_bersih) ?></h2>
            </div>
        </div>

        <div class="content-grid">
            <!-- Panel Riwayat Arus Keluar -->
            <div class="panel">
                <h3>📋 Riwayat Arus Keluar (Kulakan, Biaya &amp; Gaji Dibayar)</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Keterangan</th>
                                <th style="text-align: right;">Biaya</th>
                                <th class="no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($gabungan_riwayat)): ?>
                                <tr>
                                <td colspan="4" style="text-align: center; color: #95a5a6; padding: 20px; font-style: italic;">Belum ada pengeluaran, stok masuk, atau gaji dibayar pada periode ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($gabungan_riwayat as $gr): ?>
                                <tr>
                                    <td><?= htmlspecialchars($gr['tanggal']) ?></td>
                                    <td><?= htmlspecialchars($gr['keterangan']) ?></td>
                                    <td style="text-align: right; font-weight: bold; color: #c0392b;">Rp <?= number_format($gr['jumlah_biaya']) ?></td>
                                    <td>
                                        <?php if ($gr['tipe'] === 'gaji'): ?>
                                            <a class="no-print" href="notes_pribadi.php?edit=<?= (int)$gr['id_catatan'] ?>">Lihat Catatan</a>
                                        <?php else: ?>
                                            <form method="POST" class="no-print" onsubmit="return confirm('Hapus catatan ini? Catatan kulakan akan mengurangi kembali stok produk sebanyak jumlah yang tercatat.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_laporan_keuangan']) ?>">
                                                <input type="hidden" name="tipe_arus" value="<?= htmlspecialchars($gr['tipe']) ?>">
                                                <input type="hidden" name="id_catatan" value="<?= (int)$gr['id_catatan'] ?>">
                                                <input type="hidden" name="tgl_mulai" value="<?= htmlspecialchars($tgl_mulai) ?>">
                                                <input type="hidden" name="tgl_selesai" value="<?= htmlspecialchars($tgl_selesai) ?>">
                                                <button type="submit" name="hapus_arus_keluar" class="btn-hapus-baris">Hapus</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel">
                <h3>📥 Riwayat Pemasukan Bon Lunas</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal Lunas</th>
                                <th>Nama Pelanggan</th>
                                <th style="text-align: right;">Pemasukan</th>
                                <th class="no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data_bon_lunas)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #95a5a6; padding: 20px; font-style: italic;">Belum ada bon lunas pada periode ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($data_bon_lunas as $bon_lunas): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($bon_lunas['paid_at']) ?></td>
                                        <td><?= htmlspecialchars($bon_lunas['judul']) ?></td>
                                        <td style="text-align: right; font-weight: bold; color: #27ae60;">Rp <?= number_format((float)$bon_lunas['nominal']) ?></td>
                                        <td><a class="no-print" href="notes_pribadi.php?edit=<?= (int)$bon_lunas['id'] ?>">Lihat Catatan</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('periode-cepat').addEventListener('change', function () {
            if (!this.value) {
                return;
            }

            const start = new Date();
            const end = new Date();
            start.setHours(0, 0, 0, 0);
            end.setHours(0, 0, 0, 0);

            if (this.value === 'mingguan') {
                const dayOffset = (start.getDay() + 6) % 7;
                start.setDate(start.getDate() - dayOffset);
                end.setTime(start.getTime());
                end.setDate(end.getDate() + 6);
            } else if (this.value === 'bulanan') {
                start.setDate(1);
                end.setMonth(end.getMonth() + 1, 0);
            } else if (this.value === 'tahunan') {
                start.setMonth(0, 1);
                end.setMonth(11, 31);
            }

            const toInputDate = function (date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            };

            document.querySelector('[name="tgl_mulai"]').value = toInputDate(start);
            document.querySelector('[name="tgl_selesai"]').value = toInputDate(end);
            document.getElementById('filter-periode').submit();
        });
    </script>
</body>
</html>
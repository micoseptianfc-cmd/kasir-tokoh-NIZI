<?php
// pages/owner/kelola_produk.php
session_start();
if (!isset($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}
require_once '../../config/koneksi.php';

$pesan = "";
$kata_kunci_produk = trim($_GET['cari_produk'] ?? '');

if (empty($_SESSION['csrf_token_kelola_produk'])) {
    $_SESSION['csrf_token_kelola_produk'] = bin2hex(random_bytes(32));
}

// Cek dan tentukan nama kolom ID utama di tabel produk (id atau id_produk)
try {
    $cek_id = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
    $kolom_id = ($cek_id->rowCount() > 0) ? 'id' : 'id_produk';
} catch (Exception $e) {
    $kolom_id = 'id_produk'; // Fallback default
}

// 1. PROSES TAMBAH PRODUK BARU
if (isset($_POST['tambah_produk'])) {
    $barcode = trim($_POST['barcode'] ?? '');
    $kode_produk = trim($_POST['kode_produk'] ?? '');
    $nama_produk = trim($_POST['nama_produk'] ?? '');
    $satuan = trim($_POST['satuan'] ?? '');
    $harga_beli = trim($_POST['harga_beli'] ?? '');
    $harga_beli = $harga_beli === '' ? 0 : $harga_beli;
    $harga_jual = $_POST['harga_jual'] ?? 0;
    $harga_jual_grosir = trim($_POST['harga_jual_grosir'] ?? '');
    $harga_jual_grosir = $harga_jual_grosir === '' ? 0 : $harga_jual_grosir;
    $stok_awal = trim($_POST['stok_awal'] ?? '');
    $stok_awal = $stok_awal === '' ? 0 : $stok_awal;

    if ($nama_produk !== '' && is_numeric($harga_jual) && $harga_jual > 0 &&
        is_numeric($harga_jual_grosir) && $harga_jual_grosir >= 0 &&
        is_numeric($harga_beli) && $harga_beli >= 0 &&
        filter_var($stok_awal, FILTER_VALIDATE_INT) !== false && $stok_awal >= 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO produk (barcode, kode_produk, nama_produk, satuan, harga_beli, harga_jual, harga_jual_grosir, stok, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktif')");
            $stmt->execute([$barcode, $kode_produk, $nama_produk, $satuan, $harga_beli, $harga_jual, $harga_jual_grosir, $stok_awal]);
            $id_produk_baru = $pdo->lastInsertId();

            if ((int)$stok_awal > 0) {
                $stmt_stok = $pdo->prepare("INSERT INTO stok_masuk (produk_id, jumlah, tanggal) VALUES (?, ?, ?)");
                $stmt_stok->execute([$id_produk_baru, $stok_awal, date('Y-m-d')]);
            }

            $pdo->commit();
            $pesan = (int)$stok_awal > 0
                ? "Produk berhasil ditambahkan. Stok awal juga dicatat sebagai pembelian di laporan keuangan."
                : "Produk baru berhasil ditambahkan ke katalog!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan = "Gagal menambah produk: " . $e->getMessage();
        }
    } else {
        $pesan = "Nama produk, harga jual, harga grosiran, harga beli, dan stok awal harus diisi dengan nilai yang valid.";
    }
}

// Perbarui harga modal dan harga jual saat ini; transaksi lama tetap memakai harga saat transaksi.
if (isset($_POST['ubah_harga_produk'])) {
    $id_produk = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
    $harga_beli = trim($_POST['harga_beli'] ?? '');
    $harga_jual = trim($_POST['harga_jual'] ?? '');
    $harga_jual_grosir = trim($_POST['harga_jual_grosir'] ?? '');

    if ($id_produk !== false && $id_produk > 0 &&
        is_numeric($harga_beli) && $harga_beli >= 0 &&
        is_numeric($harga_jual) && $harga_jual > 0 &&
        is_numeric($harga_jual_grosir) && $harga_jual_grosir >= 0) {
        try {
            $stmt_update_harga = $pdo->prepare(
                "UPDATE produk SET harga_beli = ?, harga_jual = ?, harga_jual_grosir = ? WHERE $kolom_id = ? AND status = 'aktif'"
            );
            $stmt_update_harga->execute([$harga_beli, $harga_jual, $harga_jual_grosir, $id_produk]);
            $pesan = $stmt_update_harga->rowCount() > 0
                ? "Harga modal dan harga jual produk berhasil diperbarui. Transaksi sebelumnya tetap menggunakan harga saat transaksi."
                : "Tidak ada perubahan harga atau produk tidak ditemukan.";
        } catch (Exception $e) {
            $pesan = "Gagal memperbarui harga produk: " . $e->getMessage();
        }
    } else {
        $pesan = "Harga modal tidak boleh kurang dari 0, harga jual harus lebih dari 0, dan harga grosiran tidak boleh kurang dari 0.";
    }
}

// Koreksi jumlah stok tersedia tanpa menghapus riwayat stok masuk.
if (isset($_POST['kurangi_stok'])) {
    $token = $_POST['csrf_token'] ?? '';
    $id_produk = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
    $jumlah_kurangi = filter_var($_POST['jumlah_kurangi'] ?? null, FILTER_VALIDATE_INT);
    $url_kembali = 'kelola_produk.php' . ($kata_kunci_produk !== ''
        ? '?' . http_build_query(['cari_produk' => $kata_kunci_produk])
        : '');

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token_kelola_produk'], $token)) {
        $_SESSION['pesan_kelola_produk'] = "Permintaan tidak valid. Muat ulang halaman lalu coba kembali.";
    } elseif ($id_produk === false || $id_produk < 1 || $jumlah_kurangi === false || $jumlah_kurangi < 1) {
        $_SESSION['pesan_kelola_produk'] = "Produk dan jumlah pengurangan stok harus diisi dengan nilai yang valid.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt_cek_stok = $pdo->prepare(
                "SELECT stok FROM produk WHERE $kolom_id = ? AND status = 'aktif' FOR UPDATE"
            );
            $stmt_cek_stok->execute([$id_produk]);
            $stok_saat_ini = $stmt_cek_stok->fetchColumn();

            if ($stok_saat_ini === false) {
                throw new RuntimeException("Produk aktif tidak ditemukan.");
            }
            if ($jumlah_kurangi > (int)$stok_saat_ini) {
                throw new RuntimeException("Jumlah pengurangan tidak boleh melebihi stok tersedia.");
            }

            $stmt_kurangi_stok = $pdo->prepare(
                "UPDATE produk SET stok = stok - ? WHERE $kolom_id = ? AND status = 'aktif' AND stok >= ?"
            );
            $stmt_kurangi_stok->execute([$jumlah_kurangi, $id_produk, $jumlah_kurangi]);
            if ($stmt_kurangi_stok->rowCount() !== 1) {
                throw new RuntimeException("Stok produk gagal diperbarui.");
            }

            $pdo->commit();
            $_SESSION['pesan_kelola_produk'] = "Stok produk berhasil dikurangi. Riwayat stok masuk tidak diubah.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['pesan_kelola_produk'] = "Gagal mengurangi stok: " . $e->getMessage();
        }
    }

    header("Location: " . $url_kembali);
    exit();
}

// 2. PROSES SEMBUNYIKAN PRODUK (SOFT DELETE)
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    try {
        // Mengubah status menjadi 'tidak_aktif' (Bukan menghapus permanen)
        $stmt_hapus = $pdo->prepare("UPDATE produk SET status = 'tidak_aktif' WHERE $kolom_id = ?");
        $stmt_hapus->execute([$id_hapus]);

        echo "<script>alert('Produk berhasil dihapus/disembunyikan dari aplikasi!'); window.location='kelola_produk.php';</script>";
        exit();
    } catch (Exception $e) {
        $pesan = "Gagal menyembunyikan produk: " . $e->getMessage();
    }
}

$jumlah_produk_stok_menipis = 0;
$produk_stok_menipis = [];

// 3. AMBIL DAFTAR PRODUK (HANYA YANG STATUSNYA 'AKTIF')
try {
    $stmt_ringkasan = $pdo->query(
        "SELECT COALESCE(SUM(CASE WHEN stok <= 5 THEN 1 ELSE 0 END), 0) AS jumlah_stok_menipis
         FROM produk WHERE status = 'aktif'"
    );
    $ringkasan_produk = $stmt_ringkasan->fetch();
    $jumlah_produk_stok_menipis = (int)$ringkasan_produk['jumlah_stok_menipis'];

    $stmt_stok_menipis = $pdo->query(
        "SELECT nama_produk, stok, satuan FROM produk
         WHERE status = 'aktif' AND stok <= 5
         ORDER BY stok ASC, nama_produk ASC"
    );
    $produk_stok_menipis = $stmt_stok_menipis->fetchAll();

    $sql_produk = "SELECT $kolom_id as id_prod, barcode, kode_produk, nama_produk, satuan, harga_beli, harga_jual, harga_jual_grosir, stok FROM produk WHERE status = 'aktif'";
    if ($kata_kunci_produk !== '') {
        $sql_produk .= " AND (nama_produk LIKE ? OR barcode LIKE ? OR kode_produk LIKE ?)";
        $stmt_produk = $pdo->prepare($sql_produk . " ORDER BY id_prod DESC");
        $pola_cari = '%' . $kata_kunci_produk . '%';
        $stmt_produk->execute([$pola_cari, $pola_cari, $pola_cari]);
    } else {
        $stmt_produk = $pdo->query($sql_produk . " ORDER BY id_prod DESC");
    }
    $daftar_produk = $stmt_produk->fetchAll();
} catch (Exception $e) {
    $daftar_produk = [];
    $pesan = "Gagal memuat daftar produk: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katalog & Kelola Produk - Panel Pemilik</title>
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
        .header-box { background: white; padding: 20px 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; }
        
        .content-grid { display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
        .panel { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .panel h3 { margin-bottom: 15px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; color: #34495e; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .form-cari-produk { display: flex; gap: 8px; margin-bottom: 15px; }
        .form-cari-produk input { flex: 1; min-width: 0; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .btn-cari-produk { border: 0; border-radius: 6px; padding: 0 15px; background: #2980b9; color: white; font-weight: bold; cursor: pointer; }
        .btn-reset-cari { display: inline-flex; align-items: center; padding: 0 12px; border-radius: 6px; background: #ecf0f1; color: #34495e; text-decoration: none; font-size: 13px; }
        .ringkasan-produk { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 14px; }
        .kartu-info-produk { min-height: 100px; padding: 18px; border: 2px solid #dce3e8; border-radius: 10px; background: #f8fbfd; }
        .kartu-info-produk h4 { margin-bottom: 12px; color: #263849; font-size: 16px; }
        .daftar-stok-menipis { border-color: #e6a39b; background: #fff5f3; color: #7f2d23; font-size: 15px; line-height: 1.8; }
        .daftar-stok-menipis h4 { color: #a93226; }
        .daftar-stok-menipis ul { padding-left: 22px; }
        .daftar-stok-menipis li { padding-left: 3px; }
        .stok-aman { color: #27ae60; font-weight: bold; }
        .stok-menipis { color: #c0392b; font-weight: bold; }
        .label-stok-menipis { display: inline-block; margin-top: 5px; padding: 5px 8px; border-radius: 5px; background: #c0392b; color: white; font-size: 12px; }
        .baris-stok-menipis { background: #fff0ed !important; }
        .table-container td input[type="number"] { width: 105px; min-width: 85px; padding: 7px; border: 1px solid #ccc; border-radius: 5px; }
        .harga-edit-form { display: inline-flex; align-items: center; gap: 6px; }
        .btn-simpan-harga { background: #2980b9; color: white; border: 0; border-radius: 5px; padding: 7px 9px; cursor: pointer; font-weight: bold; }
        .stok-koreksi-form { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: 8px; }
        .btn-kurangi-stok { background: #e67e22; color: white; border: 0; border-radius: 5px; padding: 7px 9px; cursor: pointer; font-weight: bold; }
        
        .btn-submit { background: #27ae60; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; font-size: 14px; box-shadow: 0 4px 0 #1e8449; }
        .btn-submit:hover { background: #2ecc71; }
        
        .alert-sukses { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #c3e6cb; font-size: 14px; }
        
        .table-container { max-height: 500px; overflow-y: auto; border: 1px solid #ecf0f1; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #34495e; color: white; padding: 12px; text-align: left; font-size: 13px; position: sticky; top: 0; }
        table td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; font-size: 13px; color: #333; }
        table tr:nth-child(even) { background-color: #f8f9fa; }
        @media (max-width: 900px) { .ringkasan-produk { grid-template-columns: 1fr; } }
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
                <li><a href="laporan_keuangan.php">💰 Laporan Keuangan</a></li>
            </ul>
            <span class="menu-label">MANAJEMEN TOKO</span>
            <ul class="menu-list">
                <li><a href="kelola_produk.php" class="active">📦 Kelola Produk</a></li>
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
            <h1>Katalog & Kelola Produk</h1>
            <p style="font-size: 13px; color: #7f8c8d; margin-top: 5px;">Scan barcode produk baru untuk merekam data dan menentukan harga jualnya.</p>
        </div>

        <?php if (!empty($_SESSION['pesan_kelola_produk'])): ?>
            <?php $pesan = $_SESSION['pesan_kelola_produk']; unset($_SESSION['pesan_kelola_produk']); ?>
        <?php endif; ?>
        <?php if(!empty($pesan)): ?>
            <div class="alert-sukses"><?= htmlspecialchars($pesan) ?></div>
        <?php endif; ?>

        <div class="content-grid">
            <!-- Form Input Produk -->
            <div class="panel">
                <h3>Tambah Produk / Scan Barcode</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Kode Barcode (opsional, boleh kosong jika tidak ada):</label>
                        <input type="text" name="barcode" placeholder="Scan barcode kemasan jika tersedia..." autofocus>
                    </div>
                    <div class="form-group">
                        <label>Kode Produk (Internal):</label>
                        <input type="text" name="kode_produk" placeholder="Contoh: PRD001">
                    </div>
                    <div class="form-group">
                        <label>Nama Produk:</label>
                        <input type="text" name="nama_produk" placeholder="Contoh: Minuman Botol" required>
                    </div>
                    <div class="form-group">
                        <label>Satuan:</label>
                        <input type="text" name="satuan" placeholder="Pcs / Botol / Dus">
                    </div>
                    <div class="form-group">
                        <label>Harga Beli (Rp):</label>
                        <input type="number" name="harga_beli" placeholder="Contoh: 4000">
                    </div>
                    <div class="form-group">
                        <label>Harga Jual (Rp) - Atur Sendiri:</label>
                        <input type="number" name="harga_jual" placeholder="Contoh: 5000" required>
                    </div>
                    <div class="form-group">
                        <label>Harga Jual Grosiran (Rp, opsional):</label>
                        <input type="number" name="harga_jual_grosir" placeholder="Kosongkan jika tidak ada harga grosiran" min="0">
                    </div>
                    <div class="form-group">
                        <label>Stok Awal:</label>
                        <input type="number" name="stok_awal" placeholder="Contoh: 50">
                    </div>
                    <button type="submit" name="tambah_produk" class="btn-submit">💾 Simpan & Daftarkan Produk</button>
                </form>
            </div>

            <!-- Tabel Daftar Produk -->
            <div class="panel">
                <h3>Daftar Katalog Produk</h3>
                <div class="ringkasan-produk">
                    <div class="kartu-info-produk daftar-stok-menipis">
                        <h4>Produk dengan Stok 5 Pcs atau Kurang (<?= $jumlah_produk_stok_menipis ?>)</h4>
                        <?php if (empty($produk_stok_menipis)): ?>
                            <p>Semua produk memiliki stok lebih dari 5.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($produk_stok_menipis as $produk_menipis): ?>
                                    <li>
                                        <?= htmlspecialchars($produk_menipis['nama_produk']) ?> —
                                        <strong><?= (int)$produk_menipis['stok'] ?> <?= htmlspecialchars($produk_menipis['satuan'] ?: 'pcs') ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="GET" class="form-cari-produk">
                    <input type="search" name="cari_produk" value="<?= htmlspecialchars($kata_kunci_produk) ?>" placeholder="Cari nama, barcode, atau kode produk..." aria-label="Cari produk">
                    <button type="submit" class="btn-cari-produk">Cari</button>
                    <?php if ($kata_kunci_produk !== ''): ?>
                        <a href="kelola_produk.php" class="btn-reset-cari">Reset</a>
                    <?php endif; ?>
                </form>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Barcode</th>
                                <th>Nama Produk</th>
                                <th>Harga Modal</th>
                                <th>Harga Jual</th>
                                <th>Harga Grosiran</th>
                                <th>Stok</th>
                                <th style="text-align: center;">Aksi / Simpan Harga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($daftar_produk)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: #95a5a6; padding: 20px; font-style: italic;"><?= $kata_kunci_produk !== '' ? 'Produk yang cocok dengan pencarian tidak ditemukan.' : 'Belum ada produk aktif di katalog.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; foreach($daftar_produk as $p): ?>
                                <?php $stok_produk = (int)($p['stok'] ?? 0); ?>
                                <tr class="<?= $stok_produk <= 5 ? 'baris-stok-menipis' : '' ?>">
                                    <td><?= $no++ ?></td>
                                    <td style="color: #2980b9; font-weight: bold;"><?= htmlspecialchars($p['barcode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($p['nama_produk'] ?? '') ?></td>
                                    <td>
                                        <input form="form-harga-<?= (int)$p['id_prod'] ?>" type="number" name="harga_beli" aria-label="Harga modal <?= htmlspecialchars($p['nama_produk'] ?? 'produk') ?>" value="<?= htmlspecialchars((string)($p['harga_beli'] ?? 0)) ?>" min="0" step="any" required>
                                    </td>
                                    <td>
                                        <input form="form-harga-<?= (int)$p['id_prod'] ?>" type="number" name="harga_jual" aria-label="Harga jual eceran <?= htmlspecialchars($p['nama_produk'] ?? 'produk') ?>" value="<?= htmlspecialchars((string)($p['harga_jual'] ?? 0)) ?>" min="1" step="any" required>
                                    </td>
                                    <td>
                                        <input form="form-harga-<?= (int)$p['id_prod'] ?>" type="number" name="harga_jual_grosir" aria-label="Harga jual grosiran <?= htmlspecialchars($p['nama_produk'] ?? 'produk') ?>" value="<?= htmlspecialchars((string)($p['harga_jual_grosir'] ?? 0)) ?>" min="0" step="any" required>
                                    </td>
                                    <td class="<?= $stok_produk <= 5 ? 'stok-menipis' : 'stok-aman' ?>">
                                        <?= $stok_produk ?> <?= htmlspecialchars($p['satuan'] ?: 'pcs') ?>
                                        <?php if ($stok_produk <= 5): ?><br><span class="label-stok-menipis">Stok menipis</span><?php endif; ?>
                                    </td>
                                    
                                    <!-- TOMBOL HAPUS -->
                                    <td style="text-align: center;">
                                        <form method="POST" action="kelola_produk.php<?= $kata_kunci_produk !== '' ? '?' . http_build_query(['cari_produk' => $kata_kunci_produk]) : '' ?>" id="form-harga-<?= (int)$p['id_prod'] ?>" class="harga-edit-form">
                                            <input type="hidden" name="id_produk" value="<?= (int)$p['id_prod'] ?>">
                                            <button type="submit" name="ubah_harga_produk" class="btn-simpan-harga">Simpan Harga</button>
                                        </form>
                                        <a href="kelola_produk.php?hapus=<?= $p['id_prod'] ?>" 
                                           onclick="return confirm('Yakin ingin menghapus produk ini? Produk tidak akan tampil lagi di aplikasi.');" 
                                           style="background: #e74c3c; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-block;">
                                           🗑️ Hapus
                                        </a>
                                        <form method="POST" action="kelola_produk.php<?= $kata_kunci_produk !== '' ? '?' . http_build_query(['cari_produk' => $kata_kunci_produk]) : '' ?>" class="stok-koreksi-form" onsubmit="return confirm('Kurangi stok produk ini? Riwayat stok masuk tidak akan dihapus.');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_kelola_produk']) ?>">
                                            <input type="hidden" name="id_produk" value="<?= (int)$p['id_prod'] ?>">
                                            <input type="number" name="jumlah_kurangi" min="1" max="<?= (int)$p['stok'] ?>" required aria-label="Jumlah stok yang dikurangi untuk <?= htmlspecialchars($p['nama_produk'] ?? 'produk') ?>" placeholder="Jumlah dikurangi">
                                            <button type="submit" name="kurangi_stok" class="btn-kurangi-stok">Kurangi Stok</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
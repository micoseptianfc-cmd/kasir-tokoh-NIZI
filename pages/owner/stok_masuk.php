<?php
// pages/owner/stok_masuk.php
session_start();
if (!isset($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}
require_once '../../config/koneksi.php';

$pesan = "";

// Proses Tambah Stok Masuk
if (isset($_POST['tambah_stok'])) {
    $identitas_produk = trim($_POST['identitas_produk'] ?? '');
    $jumlah = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
    $tanggal = date('Y-m-d');

    if ($identitas_produk === '' || $jumlah === false || $jumlah < 1) {
        $pesan = "Masukkan nama/barcode/kode produk dan jumlah stok yang valid.";
    } else {
        try {
            $cek_kolom = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
            $kolom_id = ($cek_kolom->rowCount() > 0) ? 'id' : 'id_produk';
            $stmt_produk = $pdo->prepare(
                "SELECT $kolom_id AS id_produk FROM produk
                 WHERE (nama_produk = ? OR barcode = ? OR kode_produk = ?) AND status = 'aktif'"
            );
            $stmt_produk->execute([$identitas_produk, $identitas_produk, $identitas_produk]);
            $produk_cocok = $stmt_produk->fetchAll();

            if (count($produk_cocok) === 0) {
                $pesan = "Produk dengan nama, barcode, atau kode tersebut tidak ada dalam daftar produk aktif.";
            } elseif (count($produk_cocok) > 1) {
                $pesan = "Nama atau kode produk cocok dengan lebih dari satu produk. Gunakan barcode atau kode yang lebih spesifik.";
            } else {
                $produk_id = $produk_cocok[0]['id_produk'];
                $pdo->beginTransaction();

                $stmt_insert = $pdo->prepare("INSERT INTO stok_masuk (produk_id, jumlah, tanggal) VALUES (?, ?, ?)");
                $stmt_insert->execute([$produk_id, $jumlah, $tanggal]);

                $stmt_update = $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE $kolom_id = ? AND status = 'aktif'");
                $stmt_update->execute([$jumlah, $produk_id]);
                if ($stmt_update->rowCount() !== 1) {
                    throw new RuntimeException("Produk tidak ditemukan saat memperbarui stok.");
                }

                $pdo->commit();
                $pesan = "Stok berhasil ditambahkan dan tercatat di laporan keuangan!";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan = "Gagal menambah stok: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Masuk (Restock) - Panel Pemilik</title>
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
        
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .panel { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .panel h3 { margin-bottom: 15px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; color: #34495e; margin-bottom: 5px; }
        .form-group select, .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .form-group .search-hint { display: block; margin-top: 5px; color: #7f8c8d; font-size: 12px; }
        .no-search-results { display: none; margin-top: 6px; color: #c0392b; font-size: 12px; }
        .search-results { display: none; max-height: 240px; overflow-y: auto; margin-top: 6px; border: 1px solid #dce3e8; border-radius: 6px; }
        .search-result { display: block; width: 100%; padding: 10px 12px; border: 0; border-bottom: 1px solid #ecf0f1; background: white; color: #34495e; text-align: left; cursor: pointer; font-size: 13px; }
        .search-result:last-child { border-bottom: 0; }
        .search-result:hover, .search-result:focus { background: #eef6fb; outline: none; }
        .search-result-count { padding: 8px 12px; color: #7f8c8d; font-size: 12px; background: #f8f9fa; }
        .selected-product { display: none; margin-top: 6px; color: #218c52; font-size: 12px; }
        
        .btn-submit { background: #2980b9; color: white; border: none; padding: 12px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; font-size: 15px; box-shadow: 0 4px 0 #1b5275; }
        .btn-submit:hover { background: #2471a3; }
        
        .alert-sukses { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #c3e6cb; font-size: 14px; }
        .info-box { background: #f8f9fa; border-left: 4px solid #2980b9; padding: 15px; border-radius: 6px; font-size: 14px; color: #34495e; line-height: 1.6; }
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
                <li><a href="kelola_produk.php">📦 Kelola Produk</a></li>
                <li><a href="stok_masuk.php" class="active">📥 Stok Masuk</a></li>
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
            <h1>Stok Masuk (Restock Barang)</h1>
            <p style="font-size: 13px; color: #7f8c8d; margin-top: 5px;">Tambah pasokan barang baru untuk menambah stok gudang sekaligus mencatat pengeluaran keuangan secara otomatis.</p>
        </div>

        <?php if(!empty($pesan)): ?>
            <div class="alert-sukses"><?= $pesan ?></div>
        <?php endif; ?>

        <div class="content-grid">
            <div class="panel">
                <h3>Form Input Stok Masuk</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="identitasProduk">Nama / Barcode / Kode Produk:</label>
                        <input type="text" name="identitas_produk" id="identitasProduk" placeholder="Scan barcode atau ketik nama/kode produk..." required autocomplete="off" autofocus>
                        <small class="search-hint">Scan barcode dengan scanner atau masukkan nama/kode produk aktif sesuai katalog.</small>
                    </div>
                    <div class="form-group">
                        <label>Jumlah Stok Masuk:</label>
                        <input type="number" name="jumlah" placeholder="Contoh: 20" required min="1">
                    </div>
                    <button type="submit" name="tambah_stok" class="btn-submit">➕ Tambah Stok & Catat Pengeluaran</button>
                </form>
            </div>

            <div class="panel">
                <h3>Informasi Sistem Restock</h3>
                <div class="info-box">
                    <p><strong>Cara Kerja:</strong></p>
                    <ol style="padding-left: 20px; margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
                        <li>Scan barcode atau masukkan nama/kode produk aktif sesuai yang terdaftar di katalog.</li>
                        <li>Masukkan jumlah barang baru yang masuk dari supplier.</li>
                        <li>Klik <strong>Tambah Stok</strong>. Data stok produk akan bertambah secara otomatis di database.</li>
                        <li>Biaya kulakan akan langsung masuk ke Total Pengeluaran pada halaman Laporan Keuangan!</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
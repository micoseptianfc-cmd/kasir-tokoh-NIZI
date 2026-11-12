<?php
// pages/owner/notes_pribadi.php
session_start();

if (empty($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}

require_once '../../config/koneksi.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS catatan_pribadi (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        judul VARCHAR(200) NOT NULL,
        isi LONGTEXT NOT NULL,
        kategori VARCHAR(30) NOT NULL DEFAULT 'umum',
        jumlah DECIMAL(12, 2) NULL,
        pemasok VARCHAR(200) NULL,
        harga_modal DECIMAL(12, 2) NULL,
        nominal DECIMAL(12, 2) NULL,
        tanggal DATE NULL,
        status VARCHAR(20) NULL,
        paid_at DATE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_catatan_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$kolom_catatan = $pdo->query('SHOW COLUMNS FROM catatan_pribadi')->fetchAll(PDO::FETCH_COLUMN);
$kolom_baru = [
    'kategori' => "VARCHAR(30) NOT NULL DEFAULT 'umum'",
    'jumlah' => 'DECIMAL(12, 2) NULL',
    'pemasok' => 'VARCHAR(200) NULL',
    'harga_modal' => 'DECIMAL(12, 2) NULL',
    'nominal' => 'DECIMAL(12, 2) NULL',
    'tanggal' => 'DATE NULL',
    'status' => 'VARCHAR(20) NULL',
    'paid_at' => 'DATE NULL'
];
foreach ($kolom_baru as $nama_kolom => $definisi_kolom) {
    if (!in_array($nama_kolom, $kolom_catatan, true)) {
        $pdo->exec("ALTER TABLE catatan_pribadi ADD COLUMN $nama_kolom $definisi_kolom");
    }
}
$pdo->exec(
    "UPDATE catatan_pribadi
     SET paid_at = tanggal
     WHERE paid_at IS NULL AND status = 'sudah'
       AND kategori IN ('bon', 'gaji_karyawan') AND tanggal IS NOT NULL"
);

if (empty($_SESSION['csrf_token_notes'])) {
    $_SESSION['csrf_token_notes'] = bin2hex(random_bytes(32));
}

$pesan = '';
$jenis_pesan = 'error';
$kategori_form = 'barang_masuk';
$judul_form = '';
$isi_form = '';
$jumlah_form = '';
$pemasok_form = '';
$harga_modal_form = '';
$nominal_form = '';
$tanggal_form = date('Y-m-d');
$status_form = 'belum';
$id_edit = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token_notes'], $token)) {
        $pesan = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.';
    } elseif (isset($_POST['simpan_catatan'])) {
        $id_input = $_POST['id_catatan'] ?? 0;
        $id_edit = is_scalar($id_input) ? filter_var($id_input, FILTER_VALIDATE_INT) : false;
        $id_edit = $id_edit === false || $id_edit < 0 ? 0 : $id_edit;
        $judul_input = $_POST['judul'] ?? '';
        $isi_input = $_POST['isi'] ?? '';
        $judul_form = is_string($judul_input) ? trim($judul_input) : '';
        $isi_form = is_string($isi_input) ? trim($isi_input) : '';
        $kategori_input = $_POST['kategori'] ?? '';
        $kategori_form = is_string($kategori_input) ? $kategori_input : '';
        $jumlah_input = $_POST['jumlah'] ?? '';
        $pemasok_input = $_POST['pemasok'] ?? '';
        $harga_modal_input = $_POST['harga_modal'] ?? '';
        $nominal_input = $_POST['nominal'] ?? '';
        $tanggal_input = $_POST['tanggal'] ?? '';
        $status_input = $_POST['status'] ?? '';
        $jumlah_form = is_string($jumlah_input) ? trim($jumlah_input) : '';
        $pemasok_form = is_string($pemasok_input) ? trim($pemasok_input) : '';
        $harga_modal_form = is_string($harga_modal_input) ? trim($harga_modal_input) : '';
        $nominal_form = is_string($nominal_input) ? trim($nominal_input) : '';
        $tanggal_form = is_string($tanggal_input) ? trim($tanggal_input) : '';
        $status_form = is_string($status_input) ? $status_input : '';
        $panjang_judul = function_exists('mb_strlen')
            ? mb_strlen($judul_form, 'UTF-8')
            : strlen($judul_form);

        $kategori_valid = in_array($kategori_form, ['barang_masuk', 'gaji_karyawan', 'bon', 'umum'], true);
        $tanggal_valid = false;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_form)) {
            [$tahun, $bulan, $hari] = array_map('intval', explode('-', $tanggal_form));
            $tanggal_valid = checkdate($bulan, $hari, $tahun);
        }

        if (!$kategori_valid) {
            $pesan = 'Jenis catatan tidak valid.';
        } elseif ($judul_form === '') {
            $pesan = 'Nama barang, karyawan, atau pelanggan wajib diisi.';
        } elseif ($panjang_judul > 200) {
            $pesan = 'Nama maksimal 200 karakter.';
        } elseif ($kategori_form !== 'umum' && !$tanggal_valid) {
            $pesan = 'Tanggal catatan tidak valid.';
        } elseif ($kategori_form === 'barang_masuk' &&
            (!is_numeric($jumlah_form) || (float)$jumlah_form <= 0)) {
            $pesan = 'Jumlah barang harus lebih dari 0.';
        } elseif ($kategori_form === 'barang_masuk' && (float)$jumlah_form > 9999999999.99) {
            $pesan = 'Jumlah barang melebihi batas yang dapat disimpan.';
        } elseif ($kategori_form === 'barang_masuk' &&
            (!is_numeric($harga_modal_form) || (float)$harga_modal_form <= 0)) {
            $pesan = 'Harga/modal barang harus lebih dari 0.';
        } elseif ($kategori_form === 'barang_masuk' && (float)$harga_modal_form > 9999999999.99) {
            $pesan = 'Harga/modal melebihi batas yang dapat disimpan.';
        } elseif ($kategori_form === 'barang_masuk' &&
            (function_exists('mb_strlen') ? mb_strlen($pemasok_form, 'UTF-8') : strlen($pemasok_form)) > 200) {
            $pesan = 'Nama pemasok maksimal 200 karakter.';
        } elseif (in_array($kategori_form, ['gaji_karyawan', 'bon'], true) &&
            (!is_numeric($nominal_form) || (float)$nominal_form <= 0)) {
            $pesan = 'Nominal harus lebih dari 0.';
        } elseif (in_array($kategori_form, ['gaji_karyawan', 'bon'], true) && (float)$nominal_form > 9999999999.99) {
            $pesan = 'Nominal melebihi batas yang dapat disimpan.';
        } elseif (in_array($kategori_form, ['gaji_karyawan', 'bon'], true) &&
            !in_array($status_form, ['sudah', 'belum'], true)) {
            $pesan = 'Status pembayaran tidak valid.';
        } else {
            $jumlah_simpan = $kategori_form === 'barang_masuk' ? (float)$jumlah_form : null;
            $pemasok_simpan = $kategori_form === 'barang_masuk' && $pemasok_form !== '' ? $pemasok_form : null;
            $harga_modal_simpan = $kategori_form === 'barang_masuk' ? (float)$harga_modal_form : null;
            $nominal_simpan = in_array($kategori_form, ['gaji_karyawan', 'bon'], true) ? (float)$nominal_form : null;
            $tanggal_simpan = $kategori_form !== 'umum' ? $tanggal_form : null;
            $status_simpan = in_array($kategori_form, ['gaji_karyawan', 'bon'], true) ? $status_form : null;
            $isi_simpan = $isi_form !== '' ? $isi_form : '';
            $tanggal_dibayar_simpan = null;

            if ($id_edit > 0) {
                $pdo->beginTransaction();
                try {
                    $stmt_lama = $pdo->prepare(
                        'SELECT kategori, status, paid_at FROM catatan_pribadi WHERE id = ? FOR UPDATE'
                    );
                    $stmt_lama->execute([$id_edit]);
                    $catatan_lama = $stmt_lama->fetch();
                    if (!$catatan_lama) {
                        $pesan = 'Catatan yang akan diperbarui tidak ditemukan.';
                    } else {
                        if (in_array($kategori_form, ['bon', 'gaji_karyawan'], true) && $status_form === 'sudah') {
                            $tetap_dibayar = $catatan_lama['kategori'] === $kategori_form
                                && $catatan_lama['status'] === 'sudah'
                                && !empty($catatan_lama['paid_at']);
                            $tanggal_dibayar_simpan = $tetap_dibayar ? $catatan_lama['paid_at'] : date('Y-m-d');
                        }

                        $stmt = $pdo->prepare(
                            'UPDATE catatan_pribadi
                             SET judul = ?, isi = ?, kategori = ?, jumlah = ?, pemasok = ?, harga_modal = ?, nominal = ?, tanggal = ?, status = ?, paid_at = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $judul_form,
                            $isi_simpan,
                            $kategori_form,
                            $jumlah_simpan,
                            $pemasok_simpan,
                            $harga_modal_simpan,
                            $nominal_simpan,
                            $tanggal_simpan,
                            $status_simpan,
                            $tanggal_dibayar_simpan,
                            $id_edit
                        ]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } else {
                if (in_array($kategori_form, ['bon', 'gaji_karyawan'], true) && $status_form === 'sudah') {
                    $tanggal_dibayar_simpan = date('Y-m-d');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO catatan_pribadi (judul, isi, kategori, jumlah, pemasok, harga_modal, nominal, tanggal, status, paid_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $judul_form,
                    $isi_simpan,
                    $kategori_form,
                    $jumlah_simpan,
                    $pemasok_simpan,
                    $harga_modal_simpan,
                    $nominal_simpan,
                    $tanggal_simpan,
                    $status_simpan,
                    $tanggal_dibayar_simpan
                ]);
            }

            if ($pesan === '') {
                header('Location: notes_pribadi.php?status=tersimpan');
                exit();
            }
        }
    } elseif (isset($_POST['hapus_catatan'])) {
        $id_input = $_POST['id_catatan'] ?? 0;
        $id_hapus = is_scalar($id_input) ? filter_var($id_input, FILTER_VALIDATE_INT) : false;
        if ($id_hapus === false || $id_hapus < 1) {
            $pesan = 'Catatan yang dipilih tidak valid.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM catatan_pribadi WHERE id = ?');
            $stmt->execute([$id_hapus]);
            if ($stmt->rowCount() === 0) {
                $pesan = 'Catatan yang akan dihapus tidak ditemukan.';
            } else {
                header('Location: notes_pribadi.php?status=dihapus');
                exit();
            }
        }
    } else {
        $pesan = 'Aksi tidak dikenal.';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $id_input = $_GET['edit'];
    $id_edit = is_scalar($id_input) ? filter_var($id_input, FILTER_VALIDATE_INT) : false;
    if ($id_edit === false || $id_edit < 1) {
        $id_edit = 0;
        $pesan = 'Catatan yang dipilih tidak valid.';
    } else {
        $stmt_edit = $pdo->prepare('SELECT id, judul, isi, kategori, jumlah, pemasok, harga_modal, nominal, tanggal, status FROM catatan_pribadi WHERE id = ?');
        $stmt_edit->execute([$id_edit]);
        $catatan_edit = $stmt_edit->fetch();
        if ($catatan_edit) {
            $judul_form = $catatan_edit['judul'];
            $isi_form = $catatan_edit['isi'];
            $kategori_form = $catatan_edit['kategori'];
            $jumlah_form = $catatan_edit['jumlah'] ?? '';
            $pemasok_form = $catatan_edit['pemasok'] ?? '';
            $harga_modal_form = $catatan_edit['harga_modal'] ?? '';
            $nominal_form = $catatan_edit['nominal'] ?? '';
            $tanggal_form = $catatan_edit['tanggal'] ?? '';
            $status_form = $catatan_edit['status'] ?? 'belum';
        } else {
            $id_edit = 0;
            $pesan = 'Catatan yang akan diedit tidak ditemukan.';
        }
    }
}

if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'tersimpan') {
        $pesan = 'Catatan berhasil disimpan.';
        $jenis_pesan = 'success';
    } elseif ($status === 'dihapus') {
        $pesan = 'Catatan berhasil dihapus.';
        $jenis_pesan = 'success';
    }
}

$cari_nama_input = $_GET['cari_nama'] ?? '';
$cari_nama = is_string($cari_nama_input) ? trim($cari_nama_input) : '';
$id_cetak = 0;
$mode_cetak = isset($_GET['print']);
if ($mode_cetak) {
    $id_input = $_GET['print'];
    $id_cetak = is_scalar($id_input) ? filter_var($id_input, FILTER_VALIDATE_INT) : false;
    if ($id_cetak === false || $id_cetak < 1) {
        $pesan = 'Catatan yang akan dicetak tidak valid.';
        $mode_cetak = false;
        $jenis_pesan = 'error';
    }
}

$kolom_tampil = 'id, judul, isi, kategori, jumlah, pemasok, harga_modal, nominal, tanggal, status, paid_at, created_at, updated_at';
if ($mode_cetak) {
    $stmt_catatan = $pdo->prepare("SELECT $kolom_tampil FROM catatan_pribadi WHERE id = ? ORDER BY updated_at DESC, id DESC");
    $stmt_catatan->execute([$id_cetak]);
    $catatan = $stmt_catatan->fetchAll();
    if (!$catatan) {
        $pesan = 'Catatan yang akan dicetak tidak ditemukan.';
        $mode_cetak = false;
        $jenis_pesan = 'error';
    }
} elseif ($cari_nama !== '') {
    $stmt_catatan = $pdo->prepare(
        "SELECT $kolom_tampil FROM catatan_pribadi WHERE judul LIKE ? ORDER BY updated_at DESC, id DESC"
    );
    $stmt_catatan->execute(['%' . $cari_nama . '%']);
    $catatan = $stmt_catatan->fetchAll();
} else {
    $catatan = $pdo->query(
        "SELECT $kolom_tampil FROM catatan_pribadi ORDER BY updated_at DESC, id DESC"
    )->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes Pribadi - Panel Pemilik</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: #eef2f5; display: flex; min-height: 100vh; }
        .sidebar { width: 270px; flex: 0 0 270px; background: #1e2b3c; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 25px 20px; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar h2 { font-size: 28px; color: #fff; letter-spacing: 1px; margin-bottom: 2px; }
        .subtitle { font-size: 11px; color: #e74c3c; letter-spacing: 2px; margin-bottom: 20px; display: block; border-bottom: 1px solid #34495e; padding-bottom: 15px; }
        .menu-label { font-size: 11px; color: #7f8c8d; font-weight: bold; letter-spacing: 1px; margin: 15px 0 12px; display: block; }
        .menu-list { list-style: none; }
        .menu-list li { margin-bottom: 12px; }
        .menu-list a { color: #ecf0f1; text-decoration: none; font-size: 15px; font-weight: 500; display: flex; align-items: center; padding: 14px 15px; border-radius: 8px; background: #2c3e50; border: 1px solid #3a5068; box-shadow: 0 5px 0 #151e27; }
        .menu-list a:hover { background: #34495e; }
        .menu-list a.active { background: #e74c3c; border-color: #c0392b; color: white; font-weight: bold; }
        .btn-keluar { color: #e74c3c !important; }
        .main-content { flex: 1; min-width: 0; padding: 25px 30px; }
        .header-box, .panel { background: white; padding: 24px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; }
        .header-box p { color: #7f8c8d; margin-top: 6px; }
        .content-grid { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 20px; align-items: start; }
        .panel h2 { font-size: 18px; color: #2c3e50; margin-bottom: 16px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; color: #34495e; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .form-group textarea { min-height: 220px; resize: vertical; line-height: 1.5; }
        .btn { display: inline-block; border: 0; border-radius: 6px; padding: 10px 14px; text-decoration: none; font-weight: bold; cursor: pointer; font-size: 13px; }
        .btn-simpan { background: #27ae60; color: white; }
        .btn-batal { background: #ecf0f1; color: #34495e; margin-left: 5px; }
        .alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .note-list { display: grid; gap: 14px; }
        .note-card { border: 1px solid #e5e9ec; border-radius: 8px; padding: 16px; background: #fff; }
        .note-card h3 { color: #2c3e50; margin-bottom: 7px; overflow-wrap: anywhere; }
        .note-category { display: inline-block; background: #eaf2f8; color: #2c3e50; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-bottom: 8px; }
        .note-date { color: #7f8c8d; font-size: 12px; margin-bottom: 12px; }
        .note-body { color: #34495e; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.55; }
        .note-details { display: grid; gap: 6px; margin: 10px 0; font-size: 13px; color: #34495e; }
        .note-actions { display: flex; gap: 8px; margin-top: 14px; }
        .btn-edit { background: #2980b9; color: white; }
        .btn-print { background: #596275; color: white; }
        .btn-hapus { background: #e74c3c; color: white; }
        .search-form { display: flex; gap: 8px; margin-bottom: 16px; }
        .search-form input { flex: 1; min-width: 0; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .btn-search { background: #2980b9; color: white; }
        .btn-reset { background: #ecf0f1; color: #34495e; }
        .empty { color: #7f8c8d; padding: 18px; background: #f8f9fa; border-radius: 6px; }
        @media (max-width: 900px) { body { display: block; } .sidebar { width: 100%; } .main-content { padding: 20px; } .content-grid { grid-template-columns: 1fr; } }
        @media print {
            body { display: block; min-height: 0; background: white; }
            .sidebar, .header-box, .alert, .form-panel, .search-form, .note-actions { display: none !important; }
            .main-content, .content-grid, .notes-panel { display: block; width: 100%; padding: 0; margin: 0; box-shadow: none; }
            .note-list { display: block; }
            .note-card { break-inside: avoid; border: 1px solid #999; margin-bottom: 12px; }
            body.print-single .note-card:not(.print-target) { display: none !important; }
        }
    </style>
</head>
<body class="<?= $mode_cetak ? 'print-single' : '' ?>">
    <div class="sidebar">
        <div>
            <h2>Panel Pemilik</h2>
            <span class="subtitle">ADMINISTRATOR AREA</span>
            <span class="menu-label">LAPORAN &amp; KEUANGAN</span>
            <ul class="menu-list">
                <li><a href="laporan.php">📊 Rekap Laporan</a></li>
                <li><a href="riwayat_penjualan.php">📋 Riwayat Penjualan</a></li>
                <li><a href="laporan_keuangan.php">💰 Laporan Keuangan</a></li>
            </ul>
            <span class="menu-label">MANAJEMEN TOKO</span>
            <ul class="menu-list">
                <li><a href="kelola_produk.php">📦 Kelola Produk</a></li>
                <li><a href="stok_masuk.php">📥 Stok Masuk</a></li>
                <li><a href="notes_pribadi.php" class="active">📝 Notes Pribadi</a></li>
            </ul>
        </div>
        <div>
            <ul class="menu-list">
                <li><a href="../kasir.php" style="background: #2980b9;">🖥️ Ke Kasir</a></li>
                <li><a href="../../proses/proses_logout.php" class="btn-keluar">🚪 Logout</a></li>
            </ul>
        </div>
    </div>

    <main class="main-content">
        <header class="header-box">
            <h1>Notes Pribadi</h1>
            <p>Bon lunas otomatis masuk sebagai pemasukan dan gaji yang sudah dibayar masuk sebagai pengeluaran di Kelola Keuangan.</p>
        </header>

        <?php if ($pesan !== ''): ?>
            <div class="alert <?= $jenis_pesan ?>"><?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="content-grid">
            <section class="panel form-panel">
                <h2><?= $id_edit > 0 ? 'Edit Catatan' : 'Tambah Catatan' ?></h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_notes'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="id_catatan" value="<?= (int)$id_edit ?>">
                    <div class="form-group">
                        <label for="kategori">Jenis catatan</label>
                        <select id="kategori" name="kategori" required>
                            <option value="barang_masuk" <?= $kategori_form === 'barang_masuk' ? 'selected' : '' ?>>Barang Masuk</option>
                            <option value="gaji_karyawan" <?= $kategori_form === 'gaji_karyawan' ? 'selected' : '' ?>>Gaji Karyawan</option>
                            <option value="bon" <?= $kategori_form === 'bon' ? 'selected' : '' ?>>Bon</option>
                            <?php if ($kategori_form === 'umum'): ?>
                                <option value="umum" selected>Catatan Umum (lama)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="judul" id="labelJudul">Nama barang</label>
                        <input type="text" id="judul" name="judul" maxlength="200" value="<?= htmlspecialchars($judul_form, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="form-group" data-kategori="barang_masuk">
                        <label for="jumlah">Jumlah barang (boleh desimal)</label>
                        <input type="number" id="jumlah" name="jumlah" min="0.01" step="0.01" value="<?= htmlspecialchars((string)$jumlah_form, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group" data-kategori="barang_masuk">
                        <label for="pemasok">Pemasok</label>
                        <input type="text" id="pemasok" name="pemasok" maxlength="200" value="<?= htmlspecialchars((string)$pemasok_form, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group" data-kategori="barang_masuk">
                        <label for="harga_modal">Harga/modal</label>
                        <input type="number" id="harga_modal" name="harga_modal" min="0.01" max="9999999999.99" step="0.01" value="<?= htmlspecialchars((string)$harga_modal_form, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group" data-kategori="gaji_karyawan,bon">
                        <label for="nominal" id="labelNominal">Nominal</label>
                        <input type="number" id="nominal" name="nominal" min="0.01" max="9999999999.99" step="0.01" value="<?= htmlspecialchars((string)$nominal_form, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group" data-kategori="barang_masuk,gaji_karyawan,bon">
                        <label for="tanggal">Tanggal</label>
                        <input type="date" id="tanggal" name="tanggal" value="<?= htmlspecialchars((string)$tanggal_form, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group" data-kategori="gaji_karyawan,bon">
                        <label for="status" id="labelStatus">Status</label>
                        <select id="status" name="status">
                            <option value="belum" <?= $status_form === 'belum' ? 'selected' : '' ?>>Belum</option>
                            <option value="sudah" <?= $status_form === 'sudah' ? 'selected' : '' ?>>Sudah</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="isi">Keterangan (opsional)</label>
                        <textarea id="isi" name="isi"><?= htmlspecialchars($isi_form, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <button type="submit" name="simpan_catatan" class="btn btn-simpan">Simpan Catatan</button>
                    <?php if ($id_edit > 0): ?>
                        <a href="notes_pribadi.php" class="btn btn-batal">Batal</a>
                    <?php endif; ?>
                </form>
            </section>

            <section class="panel notes-panel">
                <h2>Daftar Catatan (<?= count($catatan) ?><?= $cari_nama !== '' && !$mode_cetak ? ' hasil' : '' ?>)</h2>
                <?php if (!$mode_cetak): ?>
                    <form method="GET" class="search-form">
                        <input
                            type="search"
                            name="cari_nama"
                            placeholder="Cari nama barang, karyawan, atau pelanggan"
                            aria-label="Cari nama data"
                            value="<?= htmlspecialchars($cari_nama, ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button type="submit" class="btn btn-search">Cari</button>
                        <?php if ($cari_nama !== ''): ?>
                            <a href="notes_pribadi.php" class="btn btn-reset">Reset</a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
                <?php if (!$catatan): ?>
                    <p class="empty"><?= $cari_nama !== '' ? 'Data dengan nama tersebut tidak ditemukan.' : 'Belum ada catatan. Tambahkan catatan pertama melalui formulir.' ?></p>
                <?php else: ?>
                    <div class="note-list">
                        <?php foreach ($catatan as $item): ?>
                            <?php
                                $label_kategori = [
                                    'barang_masuk' => 'Barang Masuk',
                                    'gaji_karyawan' => 'Gaji Karyawan',
                                    'bon' => 'Bon',
                                    'umum' => 'Catatan Umum'
                                ][$item['kategori']] ?? 'Catatan Umum';
                            ?>
                            <article class="note-card <?= $mode_cetak && (int)$item['id'] === $id_cetak ? 'print-target' : '' ?>">
                                <span class="note-category"><?= htmlspecialchars($label_kategori, ENT_QUOTES, 'UTF-8') ?></span>
                                <h3><?= htmlspecialchars($item['judul'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <?php if ($item['tanggal']): ?>
                                    <p class="note-date">Tanggal: <?= htmlspecialchars(date('d-m-Y', strtotime($item['tanggal'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <?php if (!empty($item['paid_at'])): ?>
                                    <p class="note-date"><?= $item['kategori'] === 'bon' ? 'Tanggal lunas' : 'Tanggal dibayar' ?>: <?= htmlspecialchars(date('d-m-Y', strtotime($item['paid_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <div class="note-details">
                                    <?php if ($item['kategori'] === 'barang_masuk'): ?>
                                        <?php if ($item['jumlah'] !== null): ?><div>Jumlah: <strong><?= htmlspecialchars(rtrim(rtrim(number_format((float)$item['jumlah'], 2, ',', '.'), '0'), ','), ENT_QUOTES, 'UTF-8') ?></strong></div><?php endif; ?>
                                        <?php if ($item['pemasok']): ?><div>Pemasok: <strong><?= htmlspecialchars($item['pemasok'], ENT_QUOTES, 'UTF-8') ?></strong></div><?php endif; ?>
                                        <?php if ($item['harga_modal'] !== null): ?><div>Harga/modal: <strong>Rp <?= number_format((float)$item['harga_modal'], 0, ',', '.') ?></strong></div><?php endif; ?>
                                    <?php elseif (in_array($item['kategori'], ['gaji_karyawan', 'bon'], true)): ?>
                                        <?php if ($item['nominal'] !== null): ?><div>Nominal: <strong>Rp <?= number_format((float)$item['nominal'], 0, ',', '.') ?></strong></div><?php endif; ?>
                                        <?php if ($item['status'] !== null): ?>
                                            <?php
                                                $label_status = $item['kategori'] === 'bon'
                                                    ? ($item['status'] === 'sudah' ? 'Lunas' : 'Belum lunas')
                                                    : ($item['status'] === 'sudah' ? 'Sudah dibayar' : 'Belum dibayar');
                                            ?>
                                            <div>Status: <strong><?= htmlspecialchars($label_status, ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($item['isi'] !== ''): ?>
                                    <div class="note-body"><?= htmlspecialchars($item['isi'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <p class="note-date" style="margin-top: 10px;">Diperbarui: <?= htmlspecialchars(date('d-m-Y H:i', strtotime($item['updated_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="note-actions">
                                    <a class="btn btn-edit" href="notes_pribadi.php?edit=<?= (int)$item['id'] ?>">Edit</a>
                                    <a class="btn btn-print" href="notes_pribadi.php?print=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">Print</a>
                                    <form method="POST" onsubmit="return confirm('Hapus catatan ini?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_notes'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id_catatan" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" name="hapus_catatan" class="btn btn-hapus">Hapus</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <?php if ($mode_cetak): ?>
        <script>
            window.addEventListener('load', function() {
                window.print();
            });
        </script>
    <?php endif; ?>
    <script>
        const kategoriInput = document.getElementById('kategori');
        const labelJudul = document.getElementById('labelJudul');
        const labelNominal = document.getElementById('labelNominal');
        const labelStatus = document.getElementById('labelStatus');
        const tanggalInput = document.getElementById('tanggal');
        const jumlahInput = document.getElementById('jumlah');
        const hargaModalInput = document.getElementById('harga_modal');
        const nominalInput = document.getElementById('nominal');
        const statusInput = document.getElementById('status');

        function perbaruiFormCatatan() {
            const kategori = kategoriInput.value;
            document.querySelectorAll('[data-kategori]').forEach(function(elemen) {
                const kategoriElemen = elemen.dataset.kategori.split(',');
                const terlihat = kategoriElemen.includes(kategori);
                elemen.hidden = !terlihat;
                elemen.querySelectorAll('input, select').forEach(function(input) {
                    input.required = terlihat && ['jumlah', 'harga_modal', 'nominal', 'tanggal', 'status'].includes(input.name);
                });
            });

            labelJudul.textContent = kategori === 'barang_masuk'
                ? 'Nama barang'
                : kategori === 'gaji_karyawan'
                    ? 'Nama karyawan'
                    : kategori === 'bon'
                        ? 'Nama pelanggan'
                        : 'Judul catatan';
            if (labelNominal) {
                labelNominal.textContent = kategori === 'gaji_karyawan' ? 'Nominal gaji' : 'Nominal bon';
            }
            if (labelStatus) {
                labelStatus.textContent = kategori === 'gaji_karyawan' ? 'Status pembayaran gaji' : 'Status bon';
                statusInput.options[0].text = kategori === 'bon' ? 'Belum lunas' : 'Belum dibayar';
                statusInput.options[1].text = kategori === 'bon' ? 'Lunas' : 'Sudah dibayar';
            }
            tanggalInput.required = kategori !== 'umum';
            jumlahInput.required = kategori === 'barang_masuk';
            hargaModalInput.required = kategori === 'barang_masuk';
            nominalInput.required = kategori === 'gaji_karyawan' || kategori === 'bon';
            statusInput.required = kategori === 'gaji_karyawan' || kategori === 'bon';
        }

        kategoriInput.addEventListener('change', perbaruiFormCatatan);
        perbaruiFormCatatan();
    </script>
</body>
</html>

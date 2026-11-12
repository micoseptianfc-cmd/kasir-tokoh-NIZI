<?php
session_start();
require_once '../config/koneksi.php';

function tambahProdukKeKeranjang($produk, $kolom_id)
{
    $id_produk = $produk[$kolom_id] ?? null;
    $stok_tersedia = (int)($produk['stok'] ?? 0);
    $harga_eceran = $produk['harga_jual'] ?? $produk['harga'] ?? 0;
    $harga_grosir = $produk['harga_jual_grosir'] ?? 0;

    if ($id_produk === null) {
        return "Produk tidak ditemukan atau sudah dihapus dari sistem!";
    }
    if ($stok_tersedia <= 0) {
        return "Stok produk ini sudah habis!";
    }
    if (isset($_SESSION['keranjang'][$id_produk])) {
        if ((int)$_SESSION['keranjang'][$id_produk]['qty'] >= $stok_tersedia) {
            return "Stok produk ini tidak mencukupi lagi!";
        }
        $_SESSION['keranjang'][$id_produk]['qty'] += 1;
        $_SESSION['keranjang'][$id_produk]['harga_eceran'] = $harga_eceran;
        $_SESSION['keranjang'][$id_produk]['harga_grosir'] = $harga_grosir;
        $_SESSION['keranjang'][$id_produk]['stok_maks'] = $stok_tersedia;
        return '';
    }

    $_SESSION['keranjang'][$id_produk] = [
        'id' => $id_produk,
        'nama_produk' => $produk['nama_produk'] ?? $produk['nama'] ?? 'Produk Tanpa Nama',
        'harga_eceran' => $harga_eceran,
        'harga_grosir' => $harga_grosir,
        'jenis_harga' => 'eceran',
        'qty' => 1,
        'stok_maks' => $stok_tersedia
    ];

    return '';
}

// Inisialisasi keranjang belanja jika belum ada
if (!isset($_SESSION['keranjang'])) {
    $_SESSION['keranjang'] = [];
}

$pesan_error = $_SESSION['pesan_error_transaksi'] ?? $_SESSION['pesan_error_cek_struk'] ?? "";
unset($_SESSION['pesan_error_transaksi'], $_SESSION['pesan_error_cek_struk']);
$hasil_cek_struk = $_SESSION['hasil_cek_struk'] ?? null;
unset($_SESSION['hasil_cek_struk']);
$detail_struk = $hasil_cek_struk['detail'] ?? null;
$item_struk = $hasil_cek_struk['items'] ?? [];
$no_struk_cari = $hasil_cek_struk['no_struk'] ?? '';

// Cek nama kolom ID pada tabel produk (id atau id_produk)
try {
    $cek_id = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
    $kolom_id = ($cek_id->rowCount() > 0) ? 'id' : 'id_produk';
} catch (Exception $e) {
    $kolom_id = 'id_produk';
}

foreach ($_SESSION['keranjang'] as &$item_keranjang) {
    if (!isset($item_keranjang['jenis_harga']) || !in_array($item_keranjang['jenis_harga'], ['eceran', 'grosir'], true)) {
        $item_keranjang['jenis_harga'] = $_SESSION['jenis_harga'] ?? 'eceran';
    }
}
unset($item_keranjang);

// 1. PROSES TAMBAH ITEM KE KERANJANG (SCAN BARCODE)
$hasil_cari_nama = [];
if (isset($_POST['tambah_item'])) {
    $input_produk = trim($_POST['barcode'] ?? '');

    if ($input_produk !== '') {
        $stmt = $pdo->prepare("SELECT * FROM produk WHERE (barcode = ? OR kode_produk = ? OR $kolom_id = ?) AND status = 'aktif'");
        $stmt->execute([$input_produk, $input_produk, $input_produk]);
        $produk = $stmt->fetch();

        if ($produk) {
            $pesan_error = tambahProdukKeKeranjang($produk, $kolom_id);
        } else {
            $stmt_nama = $pdo->prepare(
                "SELECT * FROM produk WHERE nama_produk LIKE ? AND status = 'aktif' ORDER BY nama_produk ASC LIMIT 30"
            );
            $stmt_nama->execute(['%' . $input_produk . '%']);
            $hasil_cari_nama = $stmt_nama->fetchAll();

            if (count($hasil_cari_nama) === 1) {
                $pesan_error = tambahProdukKeKeranjang($hasil_cari_nama[0], $kolom_id);
                $hasil_cari_nama = [];
            } elseif (count($hasil_cari_nama) === 0) {
                $pesan_error = "Produk tidak ditemukan. Periksa barcode, kode, atau nama produk.";
            }
        }
    }
}

if (isset($_POST['pilih_hasil_nama'])) {
    $id_produk = trim((string)($_POST['id_produk'] ?? ''));
    $stmt_produk = $pdo->prepare("SELECT * FROM produk WHERE $kolom_id = ? AND status = 'aktif'");
    $stmt_produk->execute([$id_produk]);
    $produk = $stmt_produk->fetch();

    if ($produk) {
        $pesan_error = tambahProdukKeKeranjang($produk, $kolom_id);
        if ($pesan_error !== '') {
            $_SESSION['pesan_error_transaksi'] = $pesan_error;
        }
    } else {
        $_SESSION['pesan_error_transaksi'] = "Produk tidak ditemukan atau sudah tidak aktif.";
    }
    header("Location: kasir.php");
    exit();
}

if (isset($_POST['ubah_jenis_harga_item'])) {
    $id_item = trim((string)($_POST['id_item'] ?? ''));
    $jenis_harga_item = $_POST['jenis_harga_item'] ?? '';

    if ($id_item === '' || !isset($_SESSION['keranjang'][$id_item])) {
        $_SESSION['pesan_error_transaksi'] = "Produk tidak ditemukan di keranjang.";
    } elseif (!in_array($jenis_harga_item, ['eceran', 'grosir'], true)) {
        $_SESSION['pesan_error_transaksi'] = "Jenis penjualan tidak valid.";
    } else {
        $_SESSION['keranjang'][$id_item]['jenis_harga'] = $jenis_harga_item;
    }

    header("Location: kasir.php");
    exit();
}

if (isset($_POST['ubah_qty_item'])) {
    $id_item = trim((string)($_POST['id_item'] ?? ''));
    $qty_baru = filter_var($_POST['qty'] ?? null, FILTER_VALIDATE_INT);

    if ($id_item === '' || !isset($_SESSION['keranjang'][$id_item])) {
        $_SESSION['pesan_error_transaksi'] = "Produk tidak ditemukan di keranjang.";
    } elseif ($qty_baru === false || $qty_baru < 1) {
        $_SESSION['pesan_error_transaksi'] = "Jumlah produk harus minimal 1.";
    } elseif ($qty_baru > (int)$_SESSION['keranjang'][$id_item]['stok_maks']) {
        $_SESSION['pesan_error_transaksi'] = "Jumlah melebihi stok yang tersedia.";
    } else {
        $_SESSION['keranjang'][$id_item]['qty'] = $qty_baru;
    }

    header("Location: kasir.php");
    exit();
}

// 2. PROSES HAPUS / UBAH QTY ITEM DI KERANJANG
if (isset($_GET['aksi'])) {
    $id_p = $_GET['id'];
    if ($_GET['aksi'] == 'hapus') {
        unset($_SESSION['keranjang'][$id_p]);
    } elseif ($_GET['aksi'] == 'min') {
        if ($_SESSION['keranjang'][$id_p]['qty'] > 1) {
            $_SESSION['keranjang'][$id_p]['qty'] -= 1;
        } else {
            unset($_SESSION['keranjang'][$id_p]);
        }
    } elseif ($_GET['aksi'] == 'plus') {
        if ($_SESSION['keranjang'][$id_p]['qty'] < $_SESSION['keranjang'][$id_p]['stok_maks']) {
            $_SESSION['keranjang'][$id_p]['qty'] += 1;
        }
    }
    header("Location: kasir.php");
    exit();
}

// 3. PROSES CEK STRUK / KOMPLAIN OLEH KASIR
if (isset($_POST['cari_struk'])) {
    $no_struk_cari = trim($_POST['no_struk'] ?? '');
    if (!empty($no_struk_cari)) {
        $stmt_t = $pdo->prepare("SELECT * FROM transaksi WHERE no_struk = ?");
        $stmt_t->execute([$no_struk_cari]);
        $detail_struk = $stmt_t->fetch();

        if ($detail_struk) {
            $kolom_detail = $pdo->query("SHOW COLUMNS FROM detail_transaksi")->fetchAll(PDO::FETCH_COLUMN);
            $kolom_referensi = in_array('id_transaksi', $kolom_detail, true) ? 'id_transaksi' : 'no_struk';
            $kolom_qty = in_array('qty', $kolom_detail, true) ? 'qty' : 'jumlah';
            $kolom_harga = in_array('harga_saat_transaksi', $kolom_detail, true) ? 'harga_saat_transaksi' : 'harga';
            $nilai_referensi = $kolom_referensi === 'id_transaksi'
                ? $detail_struk['id_transaksi']
                : $no_struk_cari;
            $urutan_detail = in_array('id_detail', $kolom_detail, true) ? ' ORDER BY d.id_detail' : '';

            $stmt_d = $pdo->prepare(
                "SELECT d.$kolom_qty AS qty, d.$kolom_harga AS harga_saat_transaksi, d.subtotal, p.nama_produk
                 FROM detail_transaksi d
                 LEFT JOIN produk p ON p.$kolom_id = d.id_produk
                 WHERE d.$kolom_referensi = ?$urutan_detail"
            );
            $stmt_d->execute([$nilai_referensi]);
            $item_struk = $stmt_d->fetchAll();
        } else {
            $_SESSION['pesan_error_cek_struk'] = "Nomor struk tidak ditemukan dalam sistem!";
        }

        if ($detail_struk) {
            $_SESSION['hasil_cek_struk'] = [
                'detail' => $detail_struk,
                'items' => $item_struk,
                'no_struk' => $no_struk_cari
            ];
        }
        header("Location: kasir.php");
        exit();
    }
}

// Hitung Total Pembayaran Keranjang Aktif
$total_pembayaran = 0;
$produk_tanpa_harga_grosir = [];
foreach ($_SESSION['keranjang'] as $item) {
    $jenis_harga_item = $item['jenis_harga'] ?? 'eceran';
    $harga_item = $jenis_harga_item === 'grosir'
        ? ($item['harga_grosir'] ?? 0)
        : ($item['harga_eceran'] ?? $item['harga'] ?? 0);
    if ($jenis_harga_item === 'grosir' && (float)$harga_item <= 0) {
        $produk_tanpa_harga_grosir[] = $item['nama_produk'] ?? 'Produk';
    }
    $total_pembayaran += $harga_item * $item['qty'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasir - Toko Nizi</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #eef2f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h2 { color: #2c3e50; }
        .btn-owner { background: #e74c3c; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 13px; }
        
        .grid-kasir { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; }
        
        .form-scan { display: flex; gap: 10px; margin-bottom: 20px; }
        .form-scan input { flex: 1; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; }
        .btn-scan { background: #27ae60; color: white; border: none; padding: 0 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .qty-form { margin: 0; }
        .qty-input { width: 72px; padding: 6px; border: 1px solid #bdc3c7; border-radius: 5px; text-align: center; font-size: 14px; }
        .btn-tambah-hasil { background: #2980b9; color: white; border: 0; border-radius: 5px; padding: 7px 12px; font-weight: bold; cursor: pointer; }
        .btn-tambah-hasil:disabled { background: #bdc3c7; cursor: not-allowed; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .form-jenis-harga { display: flex; align-items: center; gap: 10px; margin: 0; }
        .form-jenis-harga label { font-size: 13px; font-weight: bold; color: #34495e; }
        .form-jenis-harga select { padding: 9px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .harga-mode { font-size: 12px; color: #7f8c8d; margin-top: 6px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th { background: #34495e; color: white; padding: 10px; text-align: left; font-size: 13px; }
        table td { padding: 10px; border-bottom: 1px solid #ecf0f1; font-size: 13px; }
        
        .total-box { background: #e74c3c; color: white; padding: 20px; border-radius: 8px; text-align: right; margin-bottom: 20px; }
        .total-box h1 { font-size: 32px; margin-top: 5px; }
        
        .form-bayar .form-group { margin-bottom: 15px; }
        .form-bayar label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px; color: #34495e; }
        .form-bayar input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
        .btn-bayar { background: #2980b9; color: white; border: none; padding: 14px; border-radius: 6px; width: 100%; font-weight: bold; font-size: 16px; cursor: pointer; }
        
        .alert-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 15px; font-size: 13px; border: 1px solid #c3e6cb; }
        .receipt-result { margin-top: 15px; padding: 18px; border: 1px solid #c8e6d0; border-radius: 8px; background: #f7fcf8; color: #2c3e50; }
        .receipt-result-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
        .receipt-result-header h4 { color: #218c52; font-size: 16px; margin-bottom: 4px; }
        .receipt-result-header p { color: #7f8c8d; font-size: 13px; overflow-wrap: anywhere; }
        .btn-tutup-hasil { border: 0; border-radius: 5px; padding: 7px 10px; background: #e9f0ec; color: #34495e; cursor: pointer; font-weight: 600; }
        .btn-cetak-ulang { display: inline-block; margin-top: 14px; padding: 10px 14px; border-radius: 5px; background: #27ae60; color: white; font-weight: bold; text-decoration: none; }
        .btn-cetak-ulang:hover { background: #219150; }
        .receipt-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .receipt-info div { padding: 10px; border-radius: 6px; background: white; border: 1px solid #e7efea; }
        .receipt-info span { display: block; color: #7f8c8d; font-size: 11px; margin-bottom: 4px; }
        .receipt-info strong { font-size: 13px; overflow-wrap: anywhere; }
        .receipt-table-wrap { overflow-x: auto; }
        .receipt-table { margin: 0; min-width: 440px; }
        .receipt-table th, .receipt-table td { padding: 9px 10px; }
        .receipt-table th { font-size: 12px; }
        .receipt-total { display: flex; justify-content: space-between; gap: 12px; padding-top: 12px; margin-top: 10px; border-top: 1px solid #dce8e0; font-size: 14px; }
        .receipt-total strong { color: #218c52; }
        @media (max-width: 600px) {
            .receipt-info { grid-template-columns: 1fr; }
            .receipt-result-header { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2> Kasir Toko Nizi</h2>
            <div>
                <a href="owner/laporan.php" class="btn-owner">Panel Pemilik (Admin)</a>
            </div>
        </div>

        <?php if(!empty($pesan_error)): ?>
            <div class="alert-error"><?= htmlspecialchars($pesan_error) ?></div>
        <?php endif; ?>

        <div class="grid-kasir">
            <!-- Kolom Kiri -->
            <div>
                <div class="card">
                    <h3>Daftar Belanjaan Kasir</h3>
                    <p class="harga-mode">Pilih jenis penjualan untuk setiap produk langsung pada daftar belanja.</p>
                    <?php if (!empty($produk_tanpa_harga_grosir)): ?>
                        <div class="alert-error" style="margin-top: 12px;">
                            Harga grosiran belum diatur untuk: <?= htmlspecialchars(implode(', ', $produk_tanpa_harga_grosir)) ?>. Atur harga di menu Kelola Produk sebelum pembayaran.
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="form-scan" style="margin-top: 15px;">
                        <input type="text" name="barcode" placeholder="Scan barcode, atau ketik kode/nama produk..." autofocus autocomplete="off">
                        <button type="submit" name="tambah_item" class="btn-scan">Cari / Tambah</button>
                    </form>
                    <?php if (!empty($hasil_cari_nama)): ?>
                        <div class="hasil-cari-produk" style="margin-bottom: 16px; overflow-x: auto;">
                            <p style="margin: 0 0 8px; font-size: 13px; color: #34495e;">Beberapa produk cocok. Pilih produk yang ingin ditambahkan:</p>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nama Produk</th>
                                        <th>Barcode / Kode</th>
                                        <th>Stok</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($hasil_cari_nama as $produk_cari): ?>
                                        <?php
                                            $kode_produk_tampil = $produk_cari['barcode'] ?? '';
                                            if ($kode_produk_tampil === '') {
                                                $kode_produk_tampil = $produk_cari['kode_produk'] ?? '';
                                            }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($produk_cari['nama_produk'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($kode_produk_tampil !== '' ? $kode_produk_tampil : 'Tanpa kode') ?></td>
                                            <td><?= (int)($produk_cari['stok'] ?? 0) ?></td>
                                            <td>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="id_produk" value="<?= htmlspecialchars((string)$produk_cari[$kolom_id]) ?>">
                                                    <button type="submit" name="pilih_hasil_nama" class="btn-tambah-hasil" <?= (int)($produk_cari['stok'] ?? 0) <= 0 ? 'disabled' : '' ?>>
                                                        <?= (int)($produk_cari['stok'] ?? 0) <= 0 ? 'Stok Habis' : 'Pilih' ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Barang</th>
                                <th>Jenis Penjualan</th>
                                <th>Harga</th>
                                <th style="text-align: center;">Qty</th>
                                <th>Subtotal</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($_SESSION['keranjang'])): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #95a5a6; padding: 30px; font-style: italic;">Belum ada item di keranjang. Silakan scan barcode atau ketik kode produk.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; foreach($_SESSION['keranjang'] as $item): ?>
                                <tr>
                                    <?php
                                        $jenis_harga_item = $item['jenis_harga'] ?? 'eceran';
                                        $harga_item = $jenis_harga_item === 'grosir'
                                            ? ($item['harga_grosir'] ?? 0)
                                            : ($item['harga_eceran'] ?? $item['harga'] ?? 0);
                                    ?>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($item['nama_produk'] ?? 'Produk') ?></td>
                                    <td>
                                        <form method="POST" class="form-jenis-harga">
                                            <input type="hidden" name="ubah_jenis_harga_item" value="1">
                                            <input type="hidden" name="id_item" value="<?= htmlspecialchars((string)$item['id']) ?>">
                                            <select name="jenis_harga_item" aria-label="Jenis penjualan <?= htmlspecialchars($item['nama_produk'] ?? 'produk') ?>" onchange="this.form.submit()">
                                                <option value="eceran" <?= $jenis_harga_item === 'eceran' ? 'selected' : '' ?>>Eceran</option>
                                                <option value="grosir" <?= $jenis_harga_item === 'grosir' ? 'selected' : '' ?>>Grosiran</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= $jenis_harga_item === 'grosir' && (float)$harga_item <= 0 ? 'Belum diatur' : 'Rp ' . number_format($harga_item) ?></td>
                                    <td style="text-align: center;">
                                        <form method="POST" class="qty-form">
                                            <input type="hidden" name="ubah_qty_item" value="1">
                                            <input type="hidden" name="id_item" value="<?= htmlspecialchars((string)$item['id']) ?>">
                                            <input
                                                type="number"
                                                name="qty"
                                                class="qty-input"
                                                value="<?= (int)$item['qty'] ?>"
                                                min="1"
                                                max="<?= (int)$item['stok_maks'] ?>"
                                                step="1"
                                                aria-label="Jumlah <?= htmlspecialchars($item['nama_produk'] ?? 'produk') ?>"
                                                title="Klik lalu ubah jumlah menggunakan keyboard"
                                                onfocus="this.select()"
                                                onchange="this.form.requestSubmit()"
                                            >
                                            <button type="submit" class="visually-hidden">Simpan jumlah</button>
                                        </form>
                                    </td>
                                    <td><?= $jenis_harga_item === 'grosir' && (float)$harga_item <= 0 ? 'Belum diatur' : 'Rp ' . number_format($harga_item * ($item['qty'] ?? 0)) ?></td>
                                    <td style="text-align: center;">
                                        <a href="kasir.php?aksi=hapus&id=<?= $item['id'] ?>" style="color: #e74c3c; text-decoration: none; font-weight: bold;">❌ Hapus</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Cek Struk -->
                <div class="card">
                    <h3>🔍 Cek Struk / Penanganan Komplain Pelanggan</h3>
                    <p style="font-size: 13px; color: #7f8c8d; margin: 8px 0 15px 0;">Masukkan nomor struk pelanggan untuk memeriksa kembali rincian transaksi sebelumnya.</p>
                    <form method="POST" class="form-scan">
                        <input type="text" name="no_struk" id="noStruk" placeholder="Contoh: STRUK-20261008-001" required value="<?= htmlspecialchars($no_struk_cari) ?>">
                        <button type="submit" name="cari_struk" class="btn-scan" style="background: #2980b9;">Cek Struk</button>
                    </form>

                    <?php if($detail_struk): ?>
                        <?php
                            $nilai_waktu_struk = $detail_struk['tanggal_waktu'] ?? $detail_struk['tanggal'] ?? '';
                            $tanggal_struk = null;

                            if ($nilai_waktu_struk !== '' && $nilai_waktu_struk !== '0000-00-00 00:00:00') {
                                try {
                                    $tanggal_struk = new DateTimeImmutable($nilai_waktu_struk);
                                } catch (Exception $e) {
                                    $tanggal_struk = null;
                                }
                            }

                            if (!$tanggal_struk && preg_match('/^STRUK-(\d{8})-/', $detail_struk['no_struk'], $tanggal_legacy)) {
                                $tanggal_struk = DateTimeImmutable::createFromFormat('!Ymd', $tanggal_legacy[1]) ?: null;
                            }

                            if ($tanggal_struk) {
                                $nama_hari = [
                                    1 => 'Senin',
                                    2 => 'Selasa',
                                    3 => 'Rabu',
                                    4 => 'Kamis',
                                    5 => 'Jumat',
                                    6 => 'Sabtu',
                                    7 => 'Minggu'
                                ];
                                $waktu_struk = $nama_hari[(int)$tanggal_struk->format('N')]
                                    . ' ' . $tanggal_struk->format('d-m-Y');
                                if ($nilai_waktu_struk !== '' && $nilai_waktu_struk !== '0000-00-00 00:00:00') {
                                    $waktu_struk .= ' ' . $tanggal_struk->format('H:i');
                                } else {
                                    $waktu_struk .= ' (jam tidak tersimpan)';
                                }
                            } else {
                                $waktu_struk = 'Tidak tersedia';
                            }
                            $total_struk = $detail_struk['total_belanja'] ?? $detail_struk['total'] ?? 0;
                            $tunai_struk = $detail_struk['tunai'] ?? $detail_struk['uang_tunai'] ?? 0;
                            $kembalian_struk = $detail_struk['kembalian'] ?? 0;
                        ?>
                        <div class="receipt-result" id="hasilCekStruk" role="region" aria-label="Hasil pengecekan struk">
                            <div class="receipt-result-header">
                                <div>
                                    <h4>Struk ditemukan</h4>
                                    <p><?= htmlspecialchars($detail_struk['no_struk']) ?></p>
                                </div>
                                <button type="button" class="btn-tutup-hasil" id="tutupHasilStruk">Tutup hasil</button>
                            </div>
                            <div class="receipt-info">
                                <div><span>Tanggal transaksi</span><strong><?= htmlspecialchars($waktu_struk) ?></strong></div>
                                <div><span>Uang tunai</span><strong>Rp <?= number_format((float)$tunai_struk) ?></strong></div>
                                <div><span>Kembalian</span><strong>Rp <?= number_format((float)$kembalian_struk) ?></strong></div>
                            </div>
                            <div class="receipt-table-wrap">
                                <table class="receipt-table">
                                    <thead>
                                        <tr>
                                            <th>Nama barang</th>
                                            <th>Qty</th>
                                            <th>Harga</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($item_struk)): ?>
                                            <?php foreach ($item_struk as $item): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($item['nama_produk'] ?? 'Produk tidak tersedia') ?></td>
                                                    <td><?= (int)$item['qty'] ?></td>
                                                    <td>Rp <?= number_format((float)$item['harga_saat_transaksi']) ?></td>
                                                    <td>Rp <?= number_format((float)$item['subtotal']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" style="text-align: center; color: #7f8c8d;">Rincian barang tidak tersedia.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="receipt-total">
                                <span>Total belanja</span>
                                <strong>Rp <?= number_format((float)$total_struk) ?></strong>
                            </div>
                            <a
                                class="btn-cetak-ulang"
                                href="transaksi_sukses.php?struk=<?= rawurlencode($detail_struk['no_struk']) ?>&amp;cetak_ulang=1"
                                target="_blank"
                                rel="noopener"
                            >Cetak Ulang Struk</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Kolom Kanan: Pembayaran -->
            <div class="card">
                <div class="total-box">
                    <span>TOTAL PEMBAYARAN</span>
                    <h1>Rp <?= number_format($total_pembayaran) ?></h1>
                </div>

                <form action="/tokoh-NIZI/proses/proses_transaksi.php" method="POST" class="form-bayar" id="formBayar">
                    <div class="form-group">
                        <label>Masukkan Uang Tunai (Rp):</label>
                        <input type="text" id="uang_tunai_view" placeholder="0" required autocomplete="off">
                        <input type="hidden" name="uang_tunai" id="uang_tunai_val">
                    </div>
                    <input type="hidden" name="total_belanja" value="<?= $total_pembayaran ?>">
                    <button type="submit" class="btn-bayar" <?= empty($_SESSION['keranjang']) || !empty($produk_tanpa_harga_grosir) ? 'disabled style="background: #bdc3c7; cursor: not-allowed;"' : '' ?>>PROSES BAYAR</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Skrip Pengaman Input Uang Tunai & Format Titik -->
    <script>
        const inputNoStruk = document.getElementById('noStruk');
        const hasilCekStruk = document.getElementById('hasilCekStruk');
        const tombolTutupHasil = document.getElementById('tutupHasilStruk');

        if (hasilCekStruk && tombolTutupHasil) {
            tombolTutupHasil.addEventListener('click', function() {
                hasilCekStruk.remove();
                inputNoStruk.value = '';
                inputNoStruk.focus();
            });

            inputNoStruk.addEventListener('input', function() {
                hasilCekStruk.remove();
            });
        }

        const formBayar = document.getElementById('formBayar');
        const inputView = document.getElementById('uang_tunai_view');
        const inputVal = document.getElementById('uang_tunai_val');

        inputView.addEventListener('input', function(e) {
            let angka = this.value.replace(/[^0-9]/g, '');
            inputVal.value = angka;

            if (angka) {
                this.value = Number(angka).toLocaleString('id-ID');
            } else {
                this.value = '';
            }
        });

        formBayar.addEventListener('submit', function(e) {
            let angkaMurni = inputView.value.replace(/[^0-9]/g, '');
            inputVal.value = angkaMurni;
            if(!inputVal.value) {
                inputVal.value = '0';
            }
        });
    </script>
</body>
</html>
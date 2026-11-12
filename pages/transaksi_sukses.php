<?php
// pages/transaksi_sukses.php
session_start();
require_once '../config/koneksi.php';

$no_struk = $_GET['struk'] ?? '';
$cetak_ulang = isset($_GET['cetak_ulang']) && $_GET['cetak_ulang'] === '1';
$transaksi = null;
$item_transaksi = [];

if ($no_struk !== '') {
    $stmt = $pdo->prepare("SELECT * FROM transaksi WHERE no_struk = ?");
    $stmt->execute([$no_struk]);
    $transaksi = $stmt->fetch();

    if ($transaksi) {
        $kolom_detail = $pdo->query("SHOW COLUMNS FROM detail_transaksi")->fetchAll(PDO::FETCH_COLUMN);
        $kolom_produk = $pdo->query("SHOW COLUMNS FROM produk")->fetchAll(PDO::FETCH_COLUMN);
        $kolom_referensi = in_array('id_transaksi', $kolom_detail, true) ? 'id_transaksi' : 'no_struk';
        $kolom_qty = in_array('qty', $kolom_detail, true) ? 'qty' : 'jumlah';
        $kolom_harga = in_array('harga_saat_transaksi', $kolom_detail, true) ? 'harga_saat_transaksi' : 'harga';
        $nilai_referensi = $kolom_referensi === 'id_transaksi' ? $transaksi['id_transaksi'] : $no_struk;
        $kolom_id_produk = in_array('id', $kolom_produk, true) ? 'id' : 'id_produk';
        $urutan_detail = in_array('id_detail', $kolom_detail, true) ? ' ORDER BY d.id_detail' : '';
        $pilih_nama_produk = in_array('nama_produk', $kolom_detail, true)
            ? 'COALESCE(p.nama_produk, d.nama_produk)'
            : 'p.nama_produk';

        $stmt_detail = $pdo->prepare(
            "SELECT d.$kolom_qty AS qty, d.$kolom_harga AS harga_saat_transaksi, d.subtotal,
                    $pilih_nama_produk AS nama_produk
             FROM detail_transaksi d
             LEFT JOIN produk p ON p.$kolom_id_produk = d.id_produk
             WHERE d.$kolom_referensi = ?$urutan_detail"
        );
        $stmt_detail->execute([$nilai_referensi]);
        $item_transaksi = $stmt_detail->fetchAll();
    }
}

$nama_hari = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];
$waktu_transaksi = 'Tidak tersedia';

if ($transaksi && !empty($transaksi['tanggal_waktu']) && $transaksi['tanggal_waktu'] !== '0000-00-00 00:00:00') {
    try {
        $tanggal_waktu = new DateTimeImmutable($transaksi['tanggal_waktu'], new DateTimeZone('Asia/Jakarta'));
        $waktu_transaksi = $nama_hari[(int)$tanggal_waktu->format('N')]
            . ' ' . $tanggal_waktu->format('d-m-Y H:i') . ' WIB';
    } catch (Exception $e) {
        $waktu_transaksi = 'Tidak tersedia';
    }
} elseif ($transaksi && preg_match('/^STRUK-(\d{8})-/', $no_struk, $tanggal_struk)) {
    $tanggal_legacy = DateTimeImmutable::createFromFormat('!Ymd', $tanggal_struk[1]);
    if ($tanggal_legacy) {
        $waktu_transaksi = $nama_hari[(int)$tanggal_legacy->format('N')]
            . ' ' . $tanggal_legacy->format('d-m-Y') . ' (jam tidak tersimpan)';
    }
}

$total_belanja = $transaksi['total_belanja'] ?? $transaksi['total'] ?? 0;
$uang_tunai = $_SESSION['last_uang_tunai'] ?? 0;
foreach (['tunai', 'uang_tunai'] as $kolom_tunai) {
    if (isset($transaksi[$kolom_tunai]) && (float)$transaksi[$kolom_tunai] > 0) {
        $uang_tunai = $transaksi[$kolom_tunai];
        break;
    }
}
$kembalian = $transaksi['kembalian'] ?? $_SESSION['last_kembalian'] ?? ($uang_tunai - $total_belanja);

if ($kembalian < 0) {
    $kembalian = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Transaksi - Toko Nizi</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #eee; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 24px; color: #111; font-family: 'Courier New', monospace; }
        .card-sukses { background: white; padding: 20px 18px; width: 340px; box-shadow: 0 2px 12px rgba(0,0,0,0.12); }
        .receipt-header, .receipt-footer { text-align: center; }
        .receipt-header h1 { font-size: 19px; margin: 0 0 5px; letter-spacing: 1px; }
        .receipt-header p, .receipt-meta, .receipt-footer { font-size: 11px; line-height: 1.5; }
        .receipt-meta { margin: 14px 0 8px; }
        .struk-id { margin-bottom: 3px; }
        .waktu-transaksi { margin-bottom: 0; }
        .receipt-line { border-top: 1px dashed #111; margin: 10px 0; }
        .detail-bayar { font-size: 12px; }
        .item { margin: 8px 0; }
        .item-name { font-weight: bold; overflow-wrap: anywhere; }
        .item-detail, .detail-row { display: flex; justify-content: space-between; gap: 10px; margin-top: 3px; }
        .daftar-barang { width: 100%; margin: 0; }
        .pesan-kosong { font-size: 11px; margin: 8px 0; }
        .detail-row { margin: 5px 0; }
        .detail-row.total { border-top: 1px dashed #111; padding-top: 7px; margin-top: 8px; font-weight: bold; }
        .footer-struk { padding-top: 4px; margin-top: 12px; font-size: 11px; line-height: 1.5; }
        .footer-struk p { margin: 0; }
        .footer-struk p + p { margin-top: 8px; font-weight: bold; }
        .btn-kembali, .btn-cetak { color: white; padding: 12px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block; cursor: pointer; border: 0; font: bold 14px Arial, sans-serif; }
        .btn-kembali { background: #2980b9; transition: background 0.2s; }
        .btn-kembali:hover { background: #2471a3; }
        .btn-cetak { background: #27ae60; margin-right: 8px; }
        @media print {
            @page { margin: 2mm; }
            html, body { min-height: 0; margin: 0; padding: 0; background: #fff; }
            body {
                display: block;
                color: #000 !important;
                font-family: Arial, sans-serif;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .card-sukses, .card-sukses * {
                color: #000 !important;
                opacity: 1 !important;
                visibility: visible;
            }
            .card-sukses {
                width: 48mm;
                max-width: 100% !important;
                margin: 0;
                padding: 0;
                box-shadow: none;
                background: #fff;
                font-size: 12px;
                font-weight: 700;
            }
            .no-print { display: none !important; }
            .receipt-header h1 { font-size: 18px; font-weight: 700; }
            .receipt-header p, .receipt-meta, .receipt-footer, .footer-struk {
                font-size: 11px;
                line-height: 1.35;
                font-weight: 700;
            }
            .receipt-meta { margin: 8px 0 6px; overflow-wrap: anywhere; }
            .receipt-line { margin: 6px 0; }
            .detail-bayar, .pesan-kosong { font-size: 11px; font-weight: 700; }
            .item { margin: 6px 0; }
            .item-name { font-weight: 700; overflow-wrap: anywhere; }
            .item-detail, .detail-row { gap: 3px; font-weight: 700; }
            .detail-row { margin: 4px 0; }
            .detail-row.total { font-size: 12px; }
            .footer-struk { padding-top: 2px; margin-top: 8px; }
        }
    </style>
</head>
<body>
    <div class="card-sukses">
        <div class="receipt-header">
            <h1>TOKO NIZI</h1>
            <p>Grosir &amp; Eceran</p>
            <p>jl.lintas sumatera kabupaten sarolangun<br>desa tanjung bathin VIII</p>
        </div>

        <div class="receipt-meta">
            <div class="struk-id">No. Struk: <?= htmlspecialchars($no_struk) ?></div>
            <div class="waktu-transaksi">Tanggal &amp; Jam: <?= htmlspecialchars($waktu_transaksi) ?></div>
        </div>
        <div class="receipt-line"></div>

        <div class="detail-bayar">
            <?php if (!empty($item_transaksi)): ?>
                <?php foreach ($item_transaksi as $item): ?>
                    <div class="item">
                        <div class="item-name"><?= htmlspecialchars($item['nama_produk'] ?? 'Produk tidak tersedia') ?></div>
                        <div class="item-detail">
                            <span><?= (int)$item['qty'] ?> x Rp <?= number_format($item['harga_saat_transaksi']) ?></span>
                            <span>Rp <?= number_format($item['subtotal']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pesan-kosong">Rincian barang tidak tersedia untuk transaksi ini.</p>
            <?php endif; ?>
            <div class="receipt-line"></div>
            <div class="detail-row">
                <span>Total</span>
                <span>Rp <?= number_format($total_belanja) ?></span>
            </div>
            <div class="detail-row">
                <span>Tunai</span>
                <span>Rp <?= number_format($uang_tunai) ?></span>
            </div>
            <div class="detail-row total">
                <span>Kembali</span>
                <span>Rp <?= number_format($kembalian) ?></span>
            </div>
        </div>

        <div class="footer-struk">
            <p>terimakasih telah berbelanja</p>
        </div>

        <div class="no-print" style="margin-top: 22px;">
            <button type="button" class="btn-cetak" onclick="window.print();"><?= $cetak_ulang ? 'Cetak Ulang Struk' : 'Cetak Struk' ?></button>
            <a href="kasir.php" class="btn-kembali">Kembali ke Kasir</a>
        </div>
    </div>
    <?php if ($cetak_ulang && $transaksi): ?>
        <script>
            window.addEventListener('load', function() {
                window.print();
            });
        </script>
    <?php endif; ?>
</body>
</html>

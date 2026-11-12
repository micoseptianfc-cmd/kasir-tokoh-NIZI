ALTER TABLE produk
ADD COLUMN harga_jual_grosir DECIMAL(12, 2) NOT NULL DEFAULT 0
AFTER harga_jual;

<?php

function ensureProductOptionsSupport(mysqli $conn): void
{
    ensureProductOptionsColumn($conn, "cart", "variant_id", "ALTER TABLE cart ADD COLUMN variant_id INT(11) NULL AFTER product_id");
    ensureProductOptionsColumn($conn, "cart", "variant_label", "ALTER TABLE cart ADD COLUMN variant_label VARCHAR(120) NULL AFTER variant_id");
    ensureProductOptionsColumn($conn, "orders", "variant_id", "ALTER TABLE orders ADD COLUMN variant_id INT(11) NULL AFTER product_id");
    ensureProductOptionsColumn($conn, "orders", "variant_label", "ALTER TABLE orders ADD COLUMN variant_label VARCHAR(120) NULL AFTER variant_id");

    $conn->query("
        CREATE TABLE IF NOT EXISTS product_images (
            id INT(11) NOT NULL AUTO_INCREMENT,
            product_id INT(11) NOT NULL,
            image VARCHAR(255) NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_images_product (product_id)
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS product_variants (
            id INT(11) NOT NULL AUTO_INCREMENT,
            product_id INT(11) NOT NULL,
            color VARCHAR(80) NULL,
            size VARCHAR(80) NULL,
            price DECIMAL(10,2) NULL,
            stock INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_variants_product (product_id)
        )
    ");
}

function ensureProductOptionsColumn(mysqli $conn, string $table, string $column, string $alterSql): void
{
    $check = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if(!$check){
        return;
    }

    $check->bind_param("ss", $table, $column);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if(!$exists){
        $conn->query($alterSql);
    }
}

function normalizeProductVariantInput(array $post): array
{
    $colors = $post['variant_color'] ?? [];
    $sizes = $post['variant_size'] ?? [];
    $prices = $post['variant_price'] ?? [];
    $stocks = $post['variant_stock'] ?? [];
    $variants = [];
    $count = max(count((array) $colors), count((array) $sizes), count((array) $prices), count((array) $stocks));

    for($i = 0; $i < $count; $i++){
        $color = trim((string) ($colors[$i] ?? ''));
        $size = trim((string) ($sizes[$i] ?? ''));
        $priceValue = trim((string) ($prices[$i] ?? ''));
        $stockValue = trim((string) ($stocks[$i] ?? ''));

        if($color === '' && $size === '' && $priceValue === '' && $stockValue === ''){
            continue;
        }

        $variants[] = [
            'color' => $color,
            'size' => $size,
            'price' => $priceValue === '' ? null : (float) $priceValue,
            'stock' => max(0, (int) $stockValue),
        ];
    }

    return $variants;
}

function productVariantLabel(array $variant): string
{
    $parts = [];

    if(!empty($variant['color'])){
        $parts[] = $variant['color'];
    }

    if(!empty($variant['size'])){
        $parts[] = $variant['size'];
    }

    return $parts ? implode(' / ', $parts) : 'Default';
}

function syncProductVariants(mysqli $conn, int $productId, array $variants): int
{
    $delete = $conn->prepare("DELETE FROM product_variants WHERE product_id=?");
    $delete->bind_param("i", $productId);
    $delete->execute();
    $delete->close();

    if(empty($variants)){
        return 0;
    }

    $totalStock = 0;
    $insert = $conn->prepare("
        INSERT INTO product_variants (product_id, color, size, price, stock)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach($variants as $variant){
        $color = $variant['color'] !== '' ? $variant['color'] : null;
        $size = $variant['size'] !== '' ? $variant['size'] : null;
        $price = $variant['price'];
        $stock = (int) $variant['stock'];
        $totalStock += $stock;
        $insert->bind_param("issdi", $productId, $color, $size, $price, $stock);
        $insert->execute();
    }

    $insert->close();

    return $totalStock;
}

function storeProductImages(mysqli $conn, int $productId, array $files, ?string $primaryImage = null): void
{
    $uploadDir = __DIR__ . "/../uploads/product/";

    if(!is_dir($uploadDir)){
        mkdir($uploadDir, 0777, true);
    }

    if($primaryImage){
        $sortOrder = 0;
        $insertPrimary = $conn->prepare("
            INSERT INTO product_images (product_id, image, sort_order)
            SELECT ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM product_images WHERE product_id=? AND image=? LIMIT 1
            )
        ");
        $insertPrimary->bind_param("isiis", $productId, $primaryImage, $sortOrder, $productId, $primaryImage);
        $insertPrimary->execute();
        $insertPrimary->close();
    }

    if(empty($files['name']) || !is_array($files['name'])){
        return;
    }

    $insert = $conn->prepare("
        INSERT INTO product_images (product_id, image, sort_order)
        VALUES (?, ?, ?)
    ");
    $total = count($files['name']);

    for($i = 0; $i < $total; $i++){
        if(($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($files['name'][$i])){
            continue;
        }

        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if(!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)){
            continue;
        }

        $imageName = time() . rand() . "." . $ext;
        if(move_uploaded_file($files['tmp_name'][$i], $uploadDir . $imageName)){
            $sortOrder = $primaryImage ? $i + 1 : $i;
            $insert->bind_param("isi", $productId, $imageName, $sortOrder);
            $insert->execute();
        }
    }

    $insert->close();
}


<?php

function ensureOrderPaymentSupport(mysqli $conn): void
{
    ensureOrderStatusValues($conn);
    ensureOrderBuyerAccountTypeColumn($conn);
    ensureOrderBuyerForeignKeyAllowsBusinessOwners($conn);
    ensureServiceBookingSupport($conn);
    ensureOrderTypeSupportsService($conn);
    ensureOrderPaymentColumns($conn);
    normalizeLegacyOrderStatuses($conn);
    backfillOrderBuyerAccountTypes($conn);
}

function ensureOrderTypeSupportsService(mysqli $conn): void
{
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'order_type'
        LIMIT 1
    ");

    if (!$stmt) {
        return;
    }

    $stmt->execute();
    $column = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$column || empty($column['COLUMN_TYPE'])) {
        return;
    }

    $columnType = (string) $column['COLUMN_TYPE'];

    if (stripos($columnType, 'enum(') === false || stripos($columnType, "'service'") !== false) {
        return;
    }

    $conn->query("
        ALTER TABLE orders
        MODIFY COLUMN order_type ENUM('product','service') NOT NULL DEFAULT 'product'
    ");
}

function ensureServiceBookingSupport(mysqli $conn): void
{
    ensureNullableItemColumn($conn, "cart", "product_id");
    ensureNullableItemColumn($conn, "orders", "product_id");

    ensureColumnExists($conn, "cart", "service_id", "ALTER TABLE cart ADD COLUMN service_id INT(11) NULL AFTER product_id");
    ensureColumnExists($conn, "cart", "booking_date", "ALTER TABLE cart ADD COLUMN booking_date DATE NULL AFTER price");
    ensureColumnExists($conn, "cart", "booking_time", "ALTER TABLE cart ADD COLUMN booking_time TIME NULL AFTER booking_date");
    ensureColumnExists($conn, "cart", "booking_note", "ALTER TABLE cart ADD COLUMN booking_note VARCHAR(255) NULL AFTER booking_time");
    ensureColumnExists($conn, "cart", "unit_label", "ALTER TABLE cart ADD COLUMN unit_label VARCHAR(40) NULL AFTER booking_note");

    ensureColumnExists($conn, "orders", "service_id", "ALTER TABLE orders ADD COLUMN service_id INT(11) NULL AFTER product_id");
    ensureColumnExists($conn, "orders", "booking_date", "ALTER TABLE orders ADD COLUMN booking_date DATE NULL AFTER order_type");
    ensureColumnExists($conn, "orders", "booking_time", "ALTER TABLE orders ADD COLUMN booking_time TIME NULL AFTER booking_date");
    ensureColumnExists($conn, "orders", "booking_note", "ALTER TABLE orders ADD COLUMN booking_note VARCHAR(255) NULL AFTER booking_time");
    ensureColumnExists($conn, "orders", "unit_label", "ALTER TABLE orders ADD COLUMN unit_label VARCHAR(40) NULL AFTER booking_note");
}

function ensureColumnExists(mysqli $conn, string $table, string $column, string $alterSql): void
{
    $check = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$check) {
        return;
    }

    $check->bind_param("ss", $table, $column);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        $conn->query($alterSql);
    }
}

function ensureNullableItemColumn(mysqli $conn, string $table, string $column): void
{
    $stmt = $conn->prepare("
        SELECT IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $columnInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$columnInfo || ($columnInfo['IS_NULLABLE'] ?? '') === 'YES') {
        return;
    }

    dropForeignKeysForColumn($conn, $table, $column);

    $columnType = (string) ($columnInfo['COLUMN_TYPE'] ?? 'INT(11)');
    $conn->query("ALTER TABLE `$table` MODIFY COLUMN `$column` $columnType NULL");
}

function dropForeignKeysForColumn(mysqli $conn, string $table, string $column): void
{
    $check = $conn->prepare("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");

    if (!$check) {
        return;
    }

    $check->bind_param("ss", $table, $column);
    $check->execute();
    $result = $check->get_result();

    while ($row = $result->fetch_assoc()) {
        $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
        if ($constraintName === '') {
            continue;
        }

        $escapedTable = str_replace('`', '``', $table);
        $escapedName = str_replace('`', '``', $constraintName);
        $conn->query("ALTER TABLE `$escapedTable` DROP FOREIGN KEY `$escapedName`");
    }

    $check->close();
}

function ensureOrderBuyerAccountTypeColumn(mysqli $conn): void
{
    $check = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'buyer_account_type'
        LIMIT 1
    ");

    if (!$check) {
        return;
    }

    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        $conn->query("
            ALTER TABLE orders
            ADD COLUMN buyer_account_type VARCHAR(20) NULL AFTER consumer_id
        ");
    }
}

function ensureOrderBuyerForeignKeyAllowsBusinessOwners(mysqli $conn): void
{
    $check = $conn->prepare("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'consumer_id'
          AND REFERENCED_TABLE_NAME = 'consumers'
          AND REFERENCED_COLUMN_NAME = 'c_id'
    ");

    if (!$check) {
        return;
    }

    $check->execute();
    $result = $check->get_result();

    while ($row = $result->fetch_assoc()) {
        $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');

        if ($constraintName === '') {
            continue;
        }

        $escapedName = str_replace('`', '``', $constraintName);
        $conn->query("ALTER TABLE orders DROP FOREIGN KEY `" . $escapedName . "`");
    }

    $check->close();
}

function ensureOrderStatusValues(mysqli $conn): void
{
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'status'
        LIMIT 1
    ");

    if (!$stmt) {
        return;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $column = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$column || empty($column['COLUMN_TYPE'])) {
        return;
    }

    $columnType = (string) $column['COLUMN_TYPE'];

    if (stripos($columnType, 'enum(') === false) {
        return;
    }

    $expectedStatuses = ["'Pending'", "'For Payment'", "'Completed'", "'Cancelled'", "'Refund'"];
    $hasAllStatuses = true;

    foreach ($expectedStatuses as $status) {
        if (stripos($columnType, $status) === false) {
            $hasAllStatuses = false;
            break;
        }
    }

    if ($hasAllStatuses && stripos($columnType, "'Confirmed'") === false) {
        return;
    }

    $conn->query("
        ALTER TABLE orders
        MODIFY COLUMN status ENUM('Pending','For Payment','Completed','Cancelled','Refund')
        NOT NULL DEFAULT 'Pending'
    ");
}

function ensureOrderPaymentColumns(mysqli $conn): void
{
    $requiredColumns = [
        "payment_method" => "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(20) NULL AFTER status",
        "amount_paid" => "ALTER TABLE orders ADD COLUMN amount_paid DECIMAL(10,2) NULL AFTER payment_method",
        "change_amount" => "ALTER TABLE orders ADD COLUMN change_amount DECIMAL(10,2) NULL AFTER amount_paid",
        "paid_at" => "ALTER TABLE orders ADD COLUMN paid_at DATETIME NULL AFTER change_amount"
    ];

    foreach ($requiredColumns as $column => $sql) {
        $check = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'orders'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$check) {
            continue;
        }

        $check->bind_param("s", $column);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            $conn->query($sql);
        }
    }
}

function normalizeLegacyOrderStatuses(mysqli $conn): void
{
    $conn->query("
        UPDATE orders
        SET status = 'Completed'
        WHERE status = 'Confirmed'
    ");
}

function backfillOrderBuyerAccountTypes(mysqli $conn): void
{
    $conn->query("
        UPDATE orders o
        LEFT JOIN consumers c
            ON c.c_id = o.consumer_id
        LEFT JOIN business_owner bo
            ON bo.b_id = o.consumer_id
        SET o.buyer_account_type = CASE
            WHEN c.c_id IS NOT NULL AND bo.b_id IS NULL THEN 'consumer'
            WHEN c.c_id IS NULL AND bo.b_id IS NOT NULL THEN 'business_owner'
            ELSE o.buyer_account_type
        END
        WHERE o.buyer_account_type IS NULL
    ");
}

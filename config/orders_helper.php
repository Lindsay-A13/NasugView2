<?php

function ensureOrderPaymentSupport(mysqli $conn): void
{
    ensureOrderStatusValues($conn);
    ensureOrderBuyerAccountTypeColumn($conn);
    ensureOrderPaymentColumns($conn);
    normalizeLegacyOrderStatuses($conn);
    backfillOrderBuyerAccountTypes($conn);
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

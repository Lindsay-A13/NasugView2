<?php

function ensureItemReviewAccountType(mysqli $conn, string $tableName, string $itemColumn, string $uniqueIndexName): void
{
    if (!reviewColumnExists($conn, $tableName, "reviewer_account_type")) {
        $conn->query("
            ALTER TABLE `$tableName`
            ADD COLUMN reviewer_account_type VARCHAR(20) NOT NULL DEFAULT 'consumer' AFTER user_id
        ");
    }

    $indexColumns = reviewIndexColumns($conn, $tableName, $uniqueIndexName);
    $expectedColumns = [$itemColumn, "user_id", "reviewer_account_type"];

    if ($indexColumns === $expectedColumns) {
        return;
    }

    if (!empty($indexColumns)) {
        $conn->query("ALTER TABLE `$tableName` DROP INDEX `$uniqueIndexName`");
    }

    $conn->query("
        ALTER TABLE `$tableName`
        ADD UNIQUE KEY `$uniqueIndexName` (`$itemColumn`, `user_id`, `reviewer_account_type`)
    ");
}

function reviewColumnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $tableName, $columnName);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function reviewIndexColumns(mysqli $conn, string $tableName, string $indexName): array
{
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
        ORDER BY SEQ_IN_INDEX
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ss", $tableName, $indexName);
    $stmt->execute();
    $result = $stmt->get_result();

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }

    $stmt->close();

    return $columns;
}

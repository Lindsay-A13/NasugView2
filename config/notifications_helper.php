<?php

function notificationExists(mysqli $conn, int $userId, string $accountType, string $title, string $message): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ? AND account_type = ? AND title = ? AND message = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("isss", $userId, $accountType, $title, $message);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function insertNotification(mysqli $conn, int $userId, string $accountType, string $title, string $message): void
{
    if ($userId <= 0 || $accountType === "" || $title === "" || $message === "") {
        return;
    }

    if (notificationExists($conn, $userId, $accountType, $title, $message)) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, account_type, title, message, is_read)
        VALUES (?, ?, ?, ?, 0)
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param("isss", $userId, $accountType, $title, $message);
    $stmt->execute();
    $stmt->close();
}

function notificationDisplayName(?string $fname, ?string $lname, string $fallback = "A customer"): string
{
    $name = trim((string) $fname . " " . (string) $lname);
    return $name !== "" ? $name : $fallback;
}

function notificationSnippet(string $text, int $limit = 70): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));

    if ($text === "") {
        return "";
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . "...";
}

function syncEventNotifications(mysqli $conn, int $userId, string $accountType): void
{
    $stmt = $conn->prepare("
        SELECT id, title, start_date_and_time
        FROM events
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 10
    ");

    if (!$stmt) {
        return;
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        insertNotification(
            $conn,
            $userId,
            $accountType,
            "New Event",
            "New event: " . $row['title'] . ". Schedule: " . $row['start_date_and_time'] . "."
        );
    }

    $stmt->close();
}

function syncBusinessOwnerNotifications(mysqli $conn, int $ownerId): void
{
    $orderStmt = $conn->prepare("
        SELECT
            o.order_code,
            COUNT(*) AS item_count,
            MIN(o.created_at) AS created_at,
            c.fname,
            c.lname
        FROM orders o
        LEFT JOIN consumers c
            ON o.consumer_id = c.c_id
        WHERE o.business_id = ?
        GROUP BY o.order_code, o.consumer_id, c.fname, c.lname
        ORDER BY created_at DESC
        LIMIT 20
    ");

    if ($orderStmt) {
        $orderStmt->bind_param("i", $ownerId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();

        while ($row = $orderResult->fetch_assoc()) {
            $customerName = notificationDisplayName($row['fname'] ?? '', $row['lname'] ?? '');
            insertNotification(
                $conn,
                $ownerId,
                "business_owner",
                "New Order",
                "Order " . $row['order_code'] . " was placed by " . $customerName . " (" . (int) $row['item_count'] . " item(s))."
            );
        }

        $orderStmt->close();
    }

    $reviewStmt = $conn->prepare("
        SELECT
            r.id,
            r.comment,
            r.is_anonymous,
            r.created_at,
            c.fname,
            c.lname
        FROM reviews r
        LEFT JOIN consumers c
            ON r.user_id = c.c_id
        WHERE r.business_id = ?
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 20
    ");

    if ($reviewStmt) {
        $reviewStmt->bind_param("i", $ownerId);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();

        while ($row = $reviewResult->fetch_assoc()) {
            $reviewer = ((int) $row['is_anonymous'] === 1)
                ? "An anonymous customer"
                : notificationDisplayName($row['fname'] ?? '', $row['lname'] ?? '');
            $snippet = notificationSnippet((string) ($row['comment'] ?? ''));
            insertNotification(
                $conn,
                $ownerId,
                "business_owner",
                "New Review",
                $reviewer . " left a new review" . ($snippet !== "" ? ': "' . $snippet . '"' : ".")
            );
        }

        $reviewStmt->close();
    }
}

function syncConsumerNotifications(mysqli $conn, int $consumerId): void
{
    $statusStmt = $conn->prepare("
        SELECT
            o.order_code,
            o.status,
            b.business_name,
            MAX(o.created_at) AS created_at
        FROM orders o
        INNER JOIN business_owner b
            ON o.business_id = b.b_id
        WHERE o.consumer_id = ?
          AND o.status <> 'Pending'
        GROUP BY o.order_code, o.status, b.business_name
        ORDER BY created_at DESC
        LIMIT 20
    ");

    if (!$statusStmt) {
        return;
    }

    $statusStmt->bind_param("i", $consumerId);
    $statusStmt->execute();
    $result = $statusStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        insertNotification(
            $conn,
            $consumerId,
            "consumer",
            "Order Status Updated",
            "Your order " . $row['order_code'] . " is now " . $row['status'] . "."
        );
    }

    $statusStmt->close();
}

function syncNotificationsForUser(mysqli $conn, int $userId, string $accountType): void
{
    syncEventNotifications($conn, $userId, $accountType);

    if ($accountType === "business_owner") {
        syncBusinessOwnerNotifications($conn, $userId);
        return;
    }

    if ($accountType === "consumer") {
        syncConsumerNotifications($conn, $userId);
    }
}

function allowedNotificationTitles(string $accountType): array
{
    if ($accountType === "business_owner") {
        return ["New Event", "New Review", "New Order"];
    }

    if ($accountType === "consumer") {
        return ["New Event", "Order Status Updated"];
    }

    return ["New Event"];
}

function unreadNotificationCount(mysqli $conn, int $userId, string $accountType): int
{
    $allowedTitles = allowedNotificationTitles($accountType);
    $placeholders = implode(",", array_fill(0, count($allowedTitles), "?"));
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = ? AND account_type = ? AND is_read = 0
          AND title IN ($placeholders)
    ");

    if (!$stmt) {
        return 0;
    }

    $types = "is" . str_repeat("s", count($allowedTitles));
    $params = array_merge([$userId, $accountType], $allowedTitles);
    $bindArgs = [$types];

    foreach ($params as $key => $value) {
        $bindArgs[] = &$params[$key];
    }

    call_user_func_array([$stmt, "bind_param"], $bindArgs);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function notificationLink(string $title, string $accountType): string
{
    if ($title === "New Event") {
        return "calendar.php";
    }

    if ($title === "New Review") {
        return "creviews.php";
    }

    if ($title === "New Order") {
        return $accountType === "business_owner" ? "order_list.php" : "orders.php";
    }

    if ($title === "Order Status Updated") {
        return "orders.php";
    }

    return "notifications.php";
}

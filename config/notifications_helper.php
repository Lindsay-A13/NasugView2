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

function notificationColumnExists(mysqli $conn, string $table, string $column): bool
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
        return false;
    }

    $check->bind_param("ss", $table, $column);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    return $exists;
}

function ensureNotificationStartColumns(mysqli $conn): void
{
    $requiredColumns = [
        "consumers" => "ALTER TABLE consumers ADD COLUMN notification_started_at DATETIME NULL AFTER age",
        "business_owner" => "ALTER TABLE business_owner ADD COLUMN notification_started_at DATETIME NULL AFTER age"
    ];

    foreach ($requiredColumns as $table => $sql) {
        if (!notificationColumnExists($conn, $table, "notification_started_at")) {
            $conn->query($sql);
        }
    }
}

function notificationStartForUser(mysqli $conn, int $userId, string $accountType): ?string
{
    ensureNotificationStartColumns($conn);

    $table = $accountType === "business_owner" ? "business_owner" : "consumers";
    $idColumn = $accountType === "business_owner" ? "b_id" : "c_id";

    $stmt = $conn->prepare("
        SELECT notification_started_at
        FROM {$table}
        WHERE {$idColumn} = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $startedAt = trim((string) ($row['notification_started_at'] ?? ''));
    return $startedAt !== '' ? $startedAt : null;
}

function eventCreatedAfterClause(?string $createdAfter): string
{
    return $createdAfter !== null && $createdAfter !== ''
        ? " AND created_at IS NOT NULL AND created_at >= ?"
        : "";
}

function newEventNotificationRows(mysqli $conn, ?string $createdAfter = null): array
{
    $createdAfter = $createdAfter !== null && trim($createdAfter) !== '' ? trim($createdAfter) : null;
    $createdAfterClause = eventCreatedAfterClause($createdAfter);

    $newEventStmt = $conn->prepare("
        SELECT
            id,
            event_code,
            title,
            start_date_and_time,
            created_at
        FROM events
        WHERE start_date_and_time >= NOW()
        {$createdAfterClause}
        ORDER BY COALESCE(created_at, start_date_and_time) DESC, id DESC
        LIMIT 20
    ");

    if (!$newEventStmt) {
        return [];
    }

    if ($createdAfter !== null) {
        $newEventStmt->bind_param("s", $createdAfter);
    }

    $newEventStmt->execute();
    $result = $newEventStmt->get_result();
    $events = [];

    while ($row = $result->fetch_assoc()) {
        $eventTitle = (string) ($row['title'] ?? 'New event');
        $eventCode = trim((string) ($row['event_code'] ?? ''));
        $schedule = date("M d, Y h:i A", strtotime((string) ($row['start_date_and_time'] ?? '')));
        $codeText = $eventCode !== "" ? " Code: " . $eventCode . "." : "";

        $events[] = [
            "title" => "New Event",
            "message" => "New event posted: " . $eventTitle . ". Schedule: " . $schedule . "." . $codeText
        ];
    }

    $newEventStmt->close();

    return $events;
}

function syncNewEventNotificationsForUser(mysqli $conn, int $userId, string $accountType): void
{
    $notificationStartedAt = notificationStartForUser($conn, $userId, $accountType);

    foreach (newEventNotificationRows($conn, $notificationStartedAt) as $eventNotification) {
        insertNotification(
            $conn,
            $userId,
            $accountType,
            $eventNotification['title'],
            $eventNotification['message']
        );
    }
}

function syncNewEventNotificationsForAllUsers(mysqli $conn): void
{
    ensureNotificationStartColumns($conn);

    $recipientQueries = [
        "consumer" => "SELECT c_id AS user_id, notification_started_at FROM consumers",
        "business_owner" => "SELECT b_id AS user_id, notification_started_at FROM business_owner"
    ];

    foreach ($recipientQueries as $accountType => $sql) {
        $result = $conn->query($sql);

        if (!$result) {
            continue;
        }

        while ($recipient = $result->fetch_assoc()) {
            $startedAt = trim((string) ($recipient['notification_started_at'] ?? ''));
            $eventNotifications = newEventNotificationRows($conn, $startedAt !== '' ? $startedAt : null);

            foreach ($eventNotifications as $eventNotification) {
                insertNotification(
                    $conn,
                    (int) $recipient['user_id'],
                    $accountType,
                    $eventNotification['title'],
                    $eventNotification['message']
                );
            }
        }
    }
}

function syncEventNotifications(mysqli $conn, int $userId, string $accountType): void
{
    syncNewEventNotificationsForUser($conn, $userId, $accountType);
    $notificationStartedAt = notificationStartForUser($conn, $userId, $accountType);
    $createdAfterClause = eventCreatedAfterClause($notificationStartedAt);

    $stmt = $conn->prepare("
        SELECT
            id,
            title,
            start_date_and_time,
            DATEDIFF(DATE(start_date_and_time), CURDATE()) AS days_until
        FROM events
        WHERE start_date_and_time >= NOW()
          AND start_date_and_time < DATE_ADD(NOW(), INTERVAL 4 DAY)
          {$createdAfterClause}
        ORDER BY start_date_and_time ASC
        LIMIT 10
    ");

    if (!$stmt) {
        return;
    }

    if ($notificationStartedAt !== null) {
        $stmt->bind_param("s", $notificationStartedAt);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $daysUntil = (int) ($row['days_until'] ?? 0);
        $eventTitle = (string) ($row['title'] ?? 'Upcoming event');
        $schedule = date("M d, Y h:i A", strtotime((string) ($row['start_date_and_time'] ?? '')));

        insertNotification(
            $conn,
            $userId,
            $accountType,
            "Upcoming Event",
            "Upcoming event within 3 days: " . $eventTitle . ". Schedule: " . $schedule . "."
        );

        if ($daysUntil === 1) {
            insertNotification(
                $conn,
                $userId,
                $accountType,
                "Event Tomorrow",
                "Reminder: " . $eventTitle . " is tomorrow. Schedule: " . $schedule . "."
            );
        }
    }

    $stmt->close();
}

function syncBusinessOwnerNotifications(mysqli $conn, int $ownerId): void
{
    $orderStmt = $conn->prepare("
        SELECT
            o.order_code,
            COALESCE(o.buyer_account_type, 'consumer') AS buyer_account_type,
            COUNT(*) AS item_count,
            MIN(o.created_at) AS created_at,
            c.fname,
            c.lname,
            bo.fname AS owner_fname,
            bo.lname AS owner_lname
        FROM orders o
        LEFT JOIN consumers c
            ON o.consumer_id = c.c_id
           AND (o.buyer_account_type = 'consumer' OR o.buyer_account_type IS NULL)
        LEFT JOIN business_owner bo
            ON o.consumer_id = bo.b_id
           AND o.buyer_account_type = 'business_owner'
        WHERE o.business_id = ?
        GROUP BY o.order_code, o.consumer_id, buyer_account_type, c.fname, c.lname, bo.fname, bo.lname
        ORDER BY created_at DESC
        LIMIT 20
    ");

    if ($orderStmt) {
        $orderStmt->bind_param("i", $ownerId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();

        while ($row = $orderResult->fetch_assoc()) {
            $customerName = notificationDisplayName(
                $row['fname'] ?? ($row['owner_fname'] ?? ''),
                $row['lname'] ?? ($row['owner_lname'] ?? '')
            );
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
          AND (o.buyer_account_type = 'consumer' OR o.buyer_account_type IS NULL)
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
    syncNewEventNotificationsForAllUsers($conn);
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
        return ["New Event", "Upcoming Event", "Event Tomorrow", "New Review", "New Order"];
    }

    if ($accountType === "consumer") {
        return ["New Event", "Upcoming Event", "Event Tomorrow", "Order Status Updated"];
    }

    return ["New Event", "Upcoming Event", "Event Tomorrow"];
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
    if ($title === "New Event" || $title === "Upcoming Event" || $title === "Event Tomorrow") {
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

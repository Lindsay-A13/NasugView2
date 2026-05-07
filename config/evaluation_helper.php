<?php

function ensureEventEvaluationSupport(mysqli $conn): void
{
    ensureEventCodeColumn($conn);
    ensureEventEvaluationsTable($conn);
    ensureEventEvaluationFormColumns($conn);
    backfillEventCodes($conn);
}

function ensureEventRegistrationCodeSupport(mysqli $conn): void
{
    ensureEventCodeColumn($conn);
    backfillEventCodes($conn);
    ensureEventRegistrationCodeColumn($conn);
    allowNullableEventRegistrationId($conn);
    backfillEventRegistrationCodes($conn);
}

function ensureEventCodeColumn(mysqli $conn): void
{
    $check = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND COLUMN_NAME = 'event_code'
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
            ALTER TABLE events
            ADD COLUMN event_code VARCHAR(30) NULL AFTER id
        ");
    }
}

function databaseColumnExists(mysqli $conn, string $table, string $column): bool
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

function ensureEventEvaluationsTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS event_evaluations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            user_id INT NOT NULL,
            account_type VARCHAR(30) NOT NULL,
            overall_rating TINYINT NOT NULL,
            content_rating TINYINT NOT NULL,
            speaker_rating TINYINT NOT NULL,
            comment TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_event_user (event_id, user_id, account_type),
            KEY event_id_idx (event_id),
            CONSTRAINT event_evaluations_event_fk
                FOREIGN KEY (event_id) REFERENCES events(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureEventEvaluationFormColumns(mysqli $conn): void
{
    $requiredColumns = [
        "event_code" => "ALTER TABLE event_evaluations ADD COLUMN event_code VARCHAR(30) NULL AFTER event_id",
        "full_name" => "ALTER TABLE event_evaluations ADD COLUMN full_name VARCHAR(255) NULL AFTER account_type",
        "email" => "ALTER TABLE event_evaluations ADD COLUMN email VARCHAR(150) NULL AFTER full_name",
        "contact_number" => "ALTER TABLE event_evaluations ADD COLUMN contact_number VARCHAR(30) NULL AFTER email",
        "client_type" => "ALTER TABLE event_evaluations ADD COLUMN client_type VARCHAR(40) NULL AFTER contact_number",
        "sex" => "ALTER TABLE event_evaluations ADD COLUMN sex VARCHAR(20) NULL AFTER client_type",
        "age_group" => "ALTER TABLE event_evaluations ADD COLUMN age_group VARCHAR(30) NULL AFTER sex",
        "cc1" => "ALTER TABLE event_evaluations ADD COLUMN cc1 VARCHAR(10) NULL AFTER age_group",
        "cc2" => "ALTER TABLE event_evaluations ADD COLUMN cc2 VARCHAR(10) NULL AFTER cc1",
        "cc3" => "ALTER TABLE event_evaluations ADD COLUMN cc3 VARCHAR(10) NULL AFTER cc2",
        "responsiveness_rating" => "ALTER TABLE event_evaluations ADD COLUMN responsiveness_rating TINYINT NULL AFTER speaker_rating",
        "reliability_rating" => "ALTER TABLE event_evaluations ADD COLUMN reliability_rating TINYINT NULL AFTER responsiveness_rating",
        "access_facilities_rating" => "ALTER TABLE event_evaluations ADD COLUMN access_facilities_rating TINYINT NULL AFTER reliability_rating",
        "communication_rating" => "ALTER TABLE event_evaluations ADD COLUMN communication_rating TINYINT NULL AFTER access_facilities_rating",
        "integrity_rating" => "ALTER TABLE event_evaluations ADD COLUMN integrity_rating TINYINT NULL AFTER communication_rating",
        "assurance_rating" => "ALTER TABLE event_evaluations ADD COLUMN assurance_rating TINYINT NULL AFTER integrity_rating",
        "outcome_rating" => "ALTER TABLE event_evaluations ADD COLUMN outcome_rating TINYINT NULL AFTER assurance_rating",
        "improvement_reason" => "ALTER TABLE event_evaluations ADD COLUMN improvement_reason TEXT NULL AFTER comment",
        "service_suggestions" => "ALTER TABLE event_evaluations ADD COLUMN service_suggestions TEXT NULL AFTER improvement_reason",
        "consent_given" => "ALTER TABLE event_evaluations ADD COLUMN consent_given TINYINT(1) NOT NULL DEFAULT 0 AFTER service_suggestions"
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!databaseColumnExists($conn, 'event_evaluations', $column)) {
            $conn->query($sql);
        }
    }
}

function ensureEventRegistrationCodeColumn(mysqli $conn): void
{
    if (!databaseColumnExists($conn, 'event_registrations', 'event_code')) {
        $afterClause = databaseColumnExists($conn, 'event_registrations', 'event_id') ? " AFTER event_id" : "";
        $conn->query("
            ALTER TABLE event_registrations
            ADD COLUMN event_code VARCHAR(30) NULL$afterClause
        ");
    }
}

function allowNullableEventRegistrationId(mysqli $conn): void
{
    if (!databaseColumnExists($conn, 'event_registrations', 'event_id')) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT IS_NULLABLE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'event_registrations'
          AND COLUMN_NAME = 'event_id'
        LIMIT 1
    ");

    if (!$stmt) {
        return;
    }

    $stmt->execute();
    $column = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($column && strtoupper((string) $column['IS_NULLABLE']) === 'NO') {
        $conn->query("
            ALTER TABLE event_registrations
            MODIFY COLUMN event_id INT NULL
        ");
    }
}

function backfillEventRegistrationCodes(mysqli $conn): void
{
    if (!databaseColumnExists($conn, 'event_registrations', 'event_id')) {
        return;
    }

    $conn->query("
        UPDATE event_registrations er
        INNER JOIN events e ON e.id = er.event_id
        SET er.event_code = e.event_code
        WHERE er.event_code IS NULL OR TRIM(er.event_code) = ''
    ");
}

function backfillEventCodes(mysqli $conn): void
{
    $conn->query("
        UPDATE events
        SET event_code = CONCAT('EVT', LPAD(id, 4, '0'))
        WHERE event_code IS NULL OR TRIM(event_code) = ''
    ");
}

function normalizeEventCode(string $eventCode): string
{
    return strtoupper(str_replace([' ', '-'], '', trim($eventCode)));
}

function findEventByEvaluationCode(mysqli $conn, string $eventCode): ?array
{
    $normalizedCode = normalizeEventCode($eventCode);
    $numericId = ctype_digit($normalizedCode) ? (int) $normalizedCode : 0;

    $stmt = $conn->prepare("
        SELECT id, event_code, title, start_date_and_time, end_date_and_time, speaker, mode_of_delivery
        FROM events
        WHERE REPLACE(UPPER(event_code), '-', '') = ?
           OR (? > 0 AND id = ?)
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("sii", $normalizedCode, $numericId, $numericId);
    $stmt->execute();
    $result = $stmt->get_result();
    $event = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $event ?: null;
}

function eventEvaluationWindow(array $event): array
{
    $startTimestamp = strtotime((string) ($event['start_date_and_time'] ?? ''));

    if (!$startTimestamp) {
        return [false, null, null];
    }

    $eventStart = new DateTime('@' . $startTimestamp);
    $eventStart->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $start = clone $eventStart;
    $start->setTime(0, 0, 0);
    $expires = clone $eventStart;
    $expires->modify('+24 hours');
    $now = new DateTime('now');

    return [$now >= $start && $now <= $expires, $start, $expires];
}

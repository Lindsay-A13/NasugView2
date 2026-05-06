<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/evaluation_helper.php";

date_default_timezone_set("Asia/Manila");
ensureEventEvaluationSupport($conn);

$eventCode = "";
$event = null;
$error = "";
$success = "";
$windowStart = null;
$windowExpires = null;
$canEvaluate = false;
$alreadyEvaluated = false;

$defaultFullName = trim(($fname ?? '') . ' ' . ($lname ?? ''));
$defaultEmail = $email ?? '';

$ratingFields = [
    "overall_rating" => [
        "number" => "0",
        "title" => "Overall Rating",
        "description" => "In general, I am satisfied and I would recommend this session to colleagues."
    ],
    "responsiveness_rating" => [
        "number" => "1",
        "title" => "Responsiveness",
        "description" => "The session was provided in a timely manner, aligned with my learning needs, and relevant to my role, making it useful for my work."
    ],
    "reliability_rating" => [
        "number" => "2",
        "title" => "Reliability",
        "description" => "The session was consistent with what was promised and effectively covered all key topics."
    ],
    "access_facilities_rating" => [
        "number" => "3",
        "title" => "Access and Facilities",
        "description" => "The venue/platform was conducive to learning, equipment was appropriate, with clear audio and effective presentation facilities."
    ],
    "communication_rating" => [
        "number" => "4",
        "title" => "Communication",
        "description" => "Information was clearly and effectively communicated, with well-structured instructions, and materials that were easy to understand."
    ],
    "integrity_rating" => [
        "number" => "6",
        "title" => "Integrity",
        "description" => "The organizers and the resource speaker provided clear and truthful information and demonstrated fairness, respect, and integrity throughout the session."
    ],
    "assurance_rating" => [
        "number" => "7",
        "title" => "Assurance",
        "description" => "The organizers and resource speaker demonstrated competence and courtesy, giving reliable and credible information."
    ],
    "outcome_rating" => [
        "number" => "8",
        "title" => "Outcome",
        "description" => "The session builds productivity and efficiency for the participants."
    ],
    "speaker_rating" => [
        "number" => "",
        "title" => "Supplemental Question - Resource Speaker",
        "description" => "The resource speaker demonstrated mastery of topic, encouraged interactive discussions, and responded to questions asked."
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventCode = normalizeEventCode((string) ($_GET['event_code'] ?? $_GET['code'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventCode = normalizeEventCode((string) ($_POST['event_code'] ?? ''));

    if (isset($_POST['submit_evaluation'])) {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $formEmail = trim((string) ($_POST['email'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $clientType = trim((string) ($_POST['client_type'] ?? ''));
        $sex = trim((string) ($_POST['sex'] ?? ''));
        $ageGroup = trim((string) ($_POST['age_group'] ?? ''));
        $cc1 = trim((string) ($_POST['cc1'] ?? ''));
        $cc2 = trim((string) ($_POST['cc2'] ?? ''));
        $cc3 = trim((string) ($_POST['cc3'] ?? ''));
        $improvementReason = trim((string) ($_POST['improvement_reason'] ?? ''));
        $serviceSuggestions = trim((string) ($_POST['service_suggestions'] ?? ''));
        $consentGiven = isset($_POST['consent_given']) ? 1 : 0;

        $ratings = [];
        foreach ($ratingFields as $field => $definition) {
            $ratings[$field] = (int) ($_POST[$field] ?? 0);
        }

        $stmt = $conn->prepare("
            SELECT id, event_code, title, start_date_and_time, end_date_and_time, speaker, mode_of_delivery
            FROM events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event) {
            $error = "Event not found.";
        } else {
            [$canEvaluate, $windowStart, $windowExpires] = eventEvaluationWindow($event);
            $invalidRating = false;

            foreach ($ratings as $rating) {
                if ($rating < 1 || $rating > 5) {
                    $invalidRating = true;
                    break;
                }
            }

            if (!$canEvaluate) {
                $error = "This event evaluation is not available or has already expired.";
            } elseif ($consentGiven !== 1) {
                $error = "Please confirm the consent statement before submitting.";
            } elseif ($fullName === "" || $formEmail === "" || $contactNumber === "" || $clientType === "" || $sex === "" || $ageGroup === "") {
                $error = "Please complete the client information section.";
            } elseif ($cc1 === "" || ($cc1 !== "4" && ($cc2 === "" || $cc3 === ""))) {
                $error = "Please answer the Citizen's Charter questions.";
            } elseif ($invalidRating) {
                $error = "Please select one rating for every satisfaction criterion.";
            } elseif (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } else {
                $check = $conn->prepare("
                    SELECT id
                    FROM event_evaluations
                    WHERE event_id = ?
                      AND user_id = ?
                      AND account_type = ?
                    LIMIT 1
                ");
                $check->bind_param("iis", $eventId, $user_id, $account_type);
                $check->execute();
                $alreadyEvaluated = $check->get_result()->num_rows > 0;
                $check->close();

                if ($alreadyEvaluated) {
                    $error = "You have already evaluated this event.";
                } else {
                    $comment = trim($improvementReason . "\n\n" . $serviceSuggestions);
                    $save = $conn->prepare("
                        INSERT INTO event_evaluations (
                            event_id,
                            user_id,
                            account_type,
                            full_name,
                            email,
                            contact_number,
                            client_type,
                            sex,
                            age_group,
                            cc1,
                            cc2,
                            cc3,
                            overall_rating,
                            content_rating,
                            speaker_rating,
                            responsiveness_rating,
                            reliability_rating,
                            access_facilities_rating,
                            communication_rating,
                            integrity_rating,
                            assurance_rating,
                            outcome_rating,
                            comment,
                            improvement_reason,
                            service_suggestions,
                            consent_given
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");

                    $contentRating = $ratings['reliability_rating'];

                    $save->bind_param(
                        "iissssssssssiiiiiiiiiisssi",
                        $eventId,
                        $user_id,
                        $account_type,
                        $fullName,
                        $formEmail,
                        $contactNumber,
                        $clientType,
                        $sex,
                        $ageGroup,
                        $cc1,
                        $cc2,
                        $cc3,
                        $ratings['overall_rating'],
                        $contentRating,
                        $ratings['speaker_rating'],
                        $ratings['responsiveness_rating'],
                        $ratings['reliability_rating'],
                        $ratings['access_facilities_rating'],
                        $ratings['communication_rating'],
                        $ratings['integrity_rating'],
                        $ratings['assurance_rating'],
                        $ratings['outcome_rating'],
                        $comment,
                        $improvementReason,
                        $serviceSuggestions,
                        $consentGiven
                    );

                    if ($save->execute()) {
                        $success = "Thank you. Your feedback was submitted successfully.";
                        $alreadyEvaluated = true;
                    } else {
                        $error = "Failed to submit evaluation.";
                    }

                    $save->close();
                }
            }
        }
    }
}

if ($eventCode !== "" && !$event) {
    $event = findEventByEvaluationCode($conn, $eventCode);

    if (!$event) {
        $error = "No event found for that code.";
    }
}

if ($event) {
    [$canEvaluate, $windowStart, $windowExpires] = eventEvaluationWindow($event);

    $eventId = (int) $event['id'];
    $check = $conn->prepare("
        SELECT id
        FROM event_evaluations
        WHERE event_id = ?
          AND user_id = ?
          AND account_type = ?
        LIMIT 1
    ");
    $check->bind_param("iis", $eventId, $user_id, $account_type);
    $check->execute();
    $alreadyEvaluated = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$canEvaluate && $error === "") {
        $error = "This event can only be evaluated on the event date until 24 hours after the event starts.";
    } elseif ($alreadyEvaluated && $success === "" && $error === "") {
        $error = "You have already evaluated this event.";
    }
}

function checkedValue(string $name, string $value): string
{
    return (string) ($_POST[$name] ?? '') === $value ? 'checked' : '';
}

function postedValue(string $name, string $fallback = ''): string
{
    return htmlspecialchars((string) ($_POST[$name] ?? $fallback));
}

function ratingCell(string $field, int $value): string
{
    $checked = (int) ($_POST[$field] ?? 0) === $value ? 'checked' : '';
    return '<label class="rating-cell"><input type="radio" name="' . $field . '" value="' . $value . '" required ' . $checked . '><span></span></label>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Evaluation</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/responsive.css">

<style>
*{box-sizing:border-box}
:root{
    --primary:#001a47;
    --primary-2:#0b3a75;
    --accent:#71bf44;
    --accent-dark:#3f8d21;
    --text:#111827;
    --muted:#667085;
    --line:#d9e1ec;
    --surface:#fff;
    --soft:#f6f8fc;
    --danger:#b42318;
    --success:#0a7d2c;
}
body{
    margin:0;
    font-family:Arial, Helvetica, sans-serif;
    color:var(--text);
    background:linear-gradient(135deg, rgba(0,26,71,.06), rgba(113,191,68,.1)), #f6f8fc;
}
.container{
    width:min(100%, 1040px);
    margin:0 auto;
    padding:24px 18px 110px;
}
.panel{
    background:var(--surface);
    border:1px solid rgba(0,26,71,.1);
    border-radius:12px;
    box-shadow:0 18px 45px rgba(15,23,42,.08);
    overflow:hidden;
}
.intro{
    padding:24px;
}
.kicker{
    margin:0 0 8px;
    color:var(--accent-dark);
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
}
h1{
    margin:0 0 10px;
    color:var(--primary);
    font-size:28px;
}
.subtitle{
    margin:0 0 24px;
    color:var(--muted);
    line-height:1.55;
}
.code-form{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
}
label{
    color:#26344d;
    font-size:13px;
    font-weight:700;
}
input,
textarea{
    width:100%;
    border:1px solid var(--line);
    border-radius:8px;
    padding:12px 13px;
    font-size:14px;
    outline:none;
}
input:focus,
textarea:focus{
    border-color:var(--primary-2);
    box-shadow:0 0 0 4px rgba(11,58,117,.12);
}
textarea{
    resize:vertical;
    min-height:118px;
}
button{
    border:none;
    border-radius:8px;
    background:linear-gradient(135deg, var(--primary), var(--primary-2));
    color:#fff;
    padding:12px 18px;
    font-weight:800;
    cursor:pointer;
}
.code-form button{
    align-self:end;
    min-width:132px;
}
.form-field label{
    display:block;
    margin-bottom:7px;
}
.alert{
    margin:18px 0 0;
    padding:12px 14px;
    border-radius:8px;
    font-size:14px;
    font-weight:700;
}
.alert-error{
    border:1px solid rgba(180,35,24,.22);
    background:#fff4f2;
    color:var(--danger);
}
.alert-success{
    border:1px solid rgba(10,125,44,.22);
    background:#f0fff4;
    color:var(--success);
}
.event-card{
    margin-top:22px;
    padding-top:22px;
    border-top:1px solid var(--line);
}
.event-title{
    margin:0 0 8px;
    color:var(--primary);
    font-size:20px;
}
.event-meta{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px;
    margin:16px 0 0;
}
.meta-item{
    padding:12px;
    border:1px solid #e5eaf2;
    border-radius:8px;
    background:#fbfcff;
}
.meta-label{
    display:block;
    color:var(--muted);
    font-size:12px;
    font-weight:700;
}
.meta-value{
    display:block;
    margin-top:4px;
    color:#26344d;
    font-size:14px;
}
.feedback-form{
    border-top:1px solid var(--line);
    background:#fff;
}
.form-head{
    padding:16px 24px;
    background:linear-gradient(135deg, #8ed65b, #68b73d);
    color:#071b02;
    text-align:center;
}
.form-head h2{
    margin:0;
    font-size:20px;
    line-height:1.2;
}
.form-head p{
    margin:5px 0 0;
    font-weight:800;
}
.form-section{
    padding:20px 24px;
    border-top:1px solid var(--line);
}
.section-title{
    margin:0 0 12px;
    color:var(--primary);
    font-size:16px;
    font-weight:800;
}
.consent-box{
    padding:14px;
    border:1px solid rgba(0,26,71,.14);
    border-radius:8px;
    background:#fbfcff;
    line-height:1.55;
    font-size:14px;
}
.check-line{
    display:flex;
    gap:10px;
    align-items:flex-start;
}
.check-line input{
    width:18px;
    height:18px;
    flex:0 0 18px;
    margin-top:2px;
    accent-color:var(--primary);
}
.form-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:14px;
}
.full-width{
    grid-column:1 / -1;
}
.option-row{
    display:flex;
    flex-wrap:wrap;
    gap:10px 16px;
    margin-top:8px;
}
.choice{
    display:inline-flex;
    align-items:center;
    gap:7px;
    min-height:34px;
    padding:7px 10px;
    border:1px solid var(--line);
    border-radius:8px;
    background:#fff;
    font-weight:600;
}
.choice input{
    width:16px;
    height:16px;
    padding:0;
    accent-color:var(--primary);
}
.cc-question{
    padding:14px 0;
    border-top:1px solid #edf1f6;
}
.cc-question:first-child{
    border-top:none;
    padding-top:0;
}
.cc-question p{
    margin:0;
    font-weight:700;
}
.rating-wrap{
    width:100%;
    overflow-x:auto;
    border:1px solid var(--line);
    border-radius:8px;
}
.rating-table{
    width:100%;
    min-width:860px;
    border-collapse:collapse;
    background:#fff;
}
.rating-table th,
.rating-table td{
    border-bottom:1px solid var(--line);
    padding:10px;
    vertical-align:middle;
}
.rating-table th{
    background:#f4f8f1;
    color:#1b2a17;
    font-size:12px;
    text-align:center;
}
.rating-table th:first-child{
    text-align:left;
    width:48%;
}
.criteria-title{
    display:block;
    color:#111827;
    font-size:14px;
    font-weight:800;
    text-transform:uppercase;
}
.criteria-copy{
    display:block;
    margin-top:4px;
    color:#344054;
    font-size:13px;
    line-height:1.4;
}
.rating-cell{
    display:flex;
    align-items:center;
    justify-content:center;
}
.rating-cell input{
    position:absolute;
    opacity:0;
    pointer-events:none;
}
.rating-cell span{
    width:22px;
    height:22px;
    border:2px solid #98a2b3;
    border-radius:6px;
    background:#fff;
}
.rating-cell input:checked + span{
    border-color:var(--primary);
    background:var(--primary);
    box-shadow:inset 0 0 0 4px #fff;
}
.rating-label{
    display:block;
    font-weight:800;
}
.rating-note{
    display:block;
    margin-top:2px;
    color:#667085;
    font-size:11px;
}
.na-row td{
    background:#fbfcff;
    font-weight:800;
}
.submit-area{
    padding:20px 24px 24px;
    border-top:1px solid var(--line);
}
.submit-btn{
    width:100%;
    padding:14px 18px;
    font-size:15px;
}
.thank-you{
    margin-top:14px;
    padding:10px;
    border-radius:8px;
    background:#8ed65b;
    color:#071b02;
    text-align:center;
    font-weight:900;
}
.theme-dark body{
    background:#0b0b0b;
    color:#ededed;
}
.theme-dark .panel,
.theme-dark .feedback-form,
.theme-dark .rating-table,
.theme-dark .choice{
    background:#111;
    border-color:#2d2d2d;
}
.theme-dark h1,
.theme-dark .event-title,
.theme-dark .section-title,
.theme-dark label,
.theme-dark .meta-value,
.theme-dark .criteria-title{
    color:#ededed;
}
.theme-dark .subtitle,
.theme-dark .meta-label,
.theme-dark .criteria-copy{
    color:#b8b8b8;
}
.theme-dark .event-card,
.theme-dark .form-section,
.theme-dark .submit-area{
    border-top-color:#2d2d2d;
}
.theme-dark .meta-item,
.theme-dark .consent-box,
.theme-dark .na-row td{
    background:#161616;
    border-color:#2d2d2d;
}
.theme-dark .rating-table th{
    background:#182513;
    color:#dff7d1;
}
@media (max-width:768px){
    body{background:#fff}
    .container{padding:18px 14px 100px}
    .panel{
        border:none;
        border-radius:0;
        box-shadow:none;
        overflow:visible;
    }
    .intro,
    .form-section,
    .submit-area{
        padding-left:0;
        padding-right:0;
    }
    h1{font-size:25px}
    .code-form,
    .event-meta,
    .form-grid{
        grid-template-columns:1fr;
    }
    .code-form button{width:100%}
    input,
    textarea{font-size:16px}
    .form-head{
        margin-left:-14px;
        margin-right:-14px;
        border-radius:0;
    }
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">
    <div class="panel">
        <div class="intro">
            <p class="kicker">Event Feedback</p>
            <h1>Event Evaluation</h1>
            <p class="subtitle">Enter the event code. If the event is scheduled today or is still within the 24-hour feedback period, the evaluation form will open.</p>

            <form method="POST" class="code-form">
                <div class="form-field">
                    <label for="event_code">Event Code</label>
                    <input
                        type="text"
                        id="event_code"
                        name="event_code"
                        value="<?php echo htmlspecialchars($eventCode); ?>"
                        placeholder="Example: EVT0010"
                        required
                    >
                </div>
                <button type="submit" name="find_event">Evaluate</button>
            </form>

            <?php if ($error !== ""): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ""): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($event): ?>
                <div class="event-card">
                    <h2 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h2>
                    <div class="event-meta">
                        <div class="meta-item">
                            <span class="meta-label">Event Code</span>
                            <span class="meta-value"><?php echo htmlspecialchars($event['event_code'] ?: ('EVT' . str_pad((string) $event['id'], 4, '0', STR_PAD_LEFT))); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Evaluation Window</span>
                            <span class="meta-value">
                                <?php if ($windowStart && $windowExpires): ?>
                                    <?php echo htmlspecialchars($windowStart->format("M j, Y g:i A")); ?> to <?php echo htmlspecialchars($windowExpires->format("M j, Y g:i A")); ?>
                                <?php else: ?>
                                    Not available
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($event['speaker'])): ?>
                            <div class="meta-item">
                                <span class="meta-label">Speaker</span>
                                <span class="meta-value"><?php echo htmlspecialchars($event['speaker']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($event['mode_of_delivery'])): ?>
                            <div class="meta-item">
                                <span class="meta-label">Mode</span>
                                <span class="meta-value"><?php echo htmlspecialchars($event['mode_of_delivery']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($event && $canEvaluate && !$alreadyEvaluated && $success === ""): ?>
            <form method="POST" class="feedback-form">
                <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                <input type="hidden" name="event_code" value="<?php echo htmlspecialchars($eventCode); ?>">

                <div class="form-head">
                    <h2>Client Satisfaction Feedback Form</h2>
                    <p>Consumer Advocacy | Training | Seminar | Conference</p>
                </div>

                <div class="form-section">
                    <div class="consent-box">
                        <label class="check-line">
                            <input type="checkbox" name="consent_given" value="1" required <?php echo checkedValue('consent_given', '1'); ?>>
                            <span><strong>Consent:</strong> I agree to let DTI collect and use my name, contact details, and feedback for monitoring, measuring, and analyzing responses to improve its services. This consent is valid until revoked or withdrawn in writing, following the Data Privacy Act of 2012 (RA 10173).</span>
                        </label>
                    </div>
                </div>

                <div class="form-section">
                    <p class="section-title">Client Information</p>
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="full_name">Client's Full Name</label>
                            <input id="full_name" name="full_name" type="text" value="<?php echo postedValue('full_name', $defaultFullName); ?>" required>
                        </div>
                        <div class="form-field">
                            <label for="email">Email Address</label>
                            <input id="email" name="email" type="email" value="<?php echo postedValue('email', $defaultEmail); ?>" required>
                        </div>
                        <div class="form-field">
                            <label for="contact_number">Contact Number</label>
                            <input id="contact_number" name="contact_number" type="text" value="<?php echo postedValue('contact_number'); ?>" required>
                        </div>
                        <div class="form-field full-width">
                            <label>Client Type</label>
                            <div class="option-row">
                                <label class="choice"><input type="radio" name="client_type" value="Citizen" required <?php echo checkedValue('client_type', 'Citizen'); ?>> Citizen</label>
                                <label class="choice"><input type="radio" name="client_type" value="Business" required <?php echo checkedValue('client_type', 'Business'); ?>> Business</label>
                                <label class="choice"><input type="radio" name="client_type" value="Government" required <?php echo checkedValue('client_type', 'Government'); ?>> Government</label>
                            </div>
                        </div>
                        <div class="form-field">
                            <label>Sex</label>
                            <div class="option-row">
                                <label class="choice"><input type="radio" name="sex" value="Male" required <?php echo checkedValue('sex', 'Male'); ?>> Male</label>
                                <label class="choice"><input type="radio" name="sex" value="Female" required <?php echo checkedValue('sex', 'Female'); ?>> Female</label>
                            </div>
                        </div>
                        <div class="form-field full-width">
                            <label>Age</label>
                            <div class="option-row">
                                <label class="choice"><input type="radio" name="age_group" value="19 or lower" required <?php echo checkedValue('age_group', '19 or lower'); ?>> 19 or lower</label>
                                <label class="choice"><input type="radio" name="age_group" value="20-34" required <?php echo checkedValue('age_group', '20-34'); ?>> 20-34</label>
                                <label class="choice"><input type="radio" name="age_group" value="35-49" required <?php echo checkedValue('age_group', '35-49'); ?>> 35-49</label>
                                <label class="choice"><input type="radio" name="age_group" value="50-64" required <?php echo checkedValue('age_group', '50-64'); ?>> 50-64</label>
                                <label class="choice"><input type="radio" name="age_group" value="65 or higher" required <?php echo checkedValue('age_group', '65 or higher'); ?>> 65 or higher</label>
                            </div>
                        </div>
                        <div class="form-field full-width">
                            <label>Title of Program / Activity</label>
                            <input type="text" value="<?php echo htmlspecialchars($event['title']); ?>" readonly>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <p class="section-title">Part I. Citizen's Charter</p>
                    <div class="cc-question">
                        <p>CC1. Which of the following best describes your awareness of a Citizen's Charter?</p>
                        <div class="option-row">
                            <label class="choice"><input type="radio" name="cc1" value="1" required <?php echo checkedValue('cc1', '1'); ?>> I know what a CC is and I saw this office's CC.</label>
                            <label class="choice"><input type="radio" name="cc1" value="2" required <?php echo checkedValue('cc1', '2'); ?>> I know what a CC is but I did not see this office's CC.</label>
                            <label class="choice"><input type="radio" name="cc1" value="3" required <?php echo checkedValue('cc1', '3'); ?>> I learned of the CC only when I saw this office's CC.</label>
                            <label class="choice"><input type="radio" name="cc1" value="4" required <?php echo checkedValue('cc1', '4'); ?>> I do not know what a CC is and I did not see one in this office.</label>
                        </div>
                    </div>
                    <div class="cc-question">
                        <p>CC2. If aware of the Citizen's Charter, would you say that the CC of this office was...?</p>
                        <div class="option-row">
                            <label class="choice"><input type="radio" name="cc2" value="1" <?php echo checkedValue('cc2', '1'); ?>> Easy to see</label>
                            <label class="choice"><input type="radio" name="cc2" value="2" <?php echo checkedValue('cc2', '2'); ?>> Somewhat easy to see</label>
                            <label class="choice"><input type="radio" name="cc2" value="3" <?php echo checkedValue('cc2', '3'); ?>> Difficult to see</label>
                            <label class="choice"><input type="radio" name="cc2" value="4" <?php echo checkedValue('cc2', '4'); ?>> Not visible at all</label>
                            <label class="choice"><input type="radio" name="cc2" value="5" <?php echo checkedValue('cc2', '5'); ?>> N/A</label>
                        </div>
                    </div>
                    <div class="cc-question">
                        <p>CC3. If aware of the Citizen's Charter, how much did the CC help you in your transaction?</p>
                        <div class="option-row">
                            <label class="choice"><input type="radio" name="cc3" value="1" <?php echo checkedValue('cc3', '1'); ?>> Helped very much</label>
                            <label class="choice"><input type="radio" name="cc3" value="2" <?php echo checkedValue('cc3', '2'); ?>> Somewhat helped</label>
                            <label class="choice"><input type="radio" name="cc3" value="3" <?php echo checkedValue('cc3', '3'); ?>> Did not help</label>
                            <label class="choice"><input type="radio" name="cc3" value="4" <?php echo checkedValue('cc3', '4'); ?>> N/A</label>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <p class="section-title">Part II. Service Improvement Feedback</p>
                    <p class="subtitle">For each criterion below, mark one rating. If you select Neither, Disagree, or Strongly Disagree, please explain in Part III.</p>

                    <div class="rating-wrap">
                        <table class="rating-table">
                            <thead>
                                <tr>
                                    <th>Criteria for Rating</th>
                                    <th><span class="rating-label">Strongly Agree</span><span class="rating-note">5</span></th>
                                    <th><span class="rating-label">Agree</span><span class="rating-note">4</span></th>
                                    <th><span class="rating-label">Neither</span><span class="rating-note">3</span></th>
                                    <th><span class="rating-label">Disagree</span><span class="rating-note">2</span></th>
                                    <th><span class="rating-label">Strongly Disagree</span><span class="rating-note">1</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ratingFields as $field => $definition): ?>
                                    <?php if ($definition['number'] === "6"): ?>
                                        <tr class="na-row">
                                            <td colspan="6">5. Costs: N/A</td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td>
                                            <span class="criteria-title"><?php echo htmlspecialchars(($definition['number'] !== "" ? $definition['number'] . ". " : "") . $definition['title']); ?></span>
                                            <span class="criteria-copy"><?php echo htmlspecialchars($definition['description']); ?></span>
                                        </td>
                                        <td><?php echo ratingCell($field, 5); ?></td>
                                        <td><?php echo ratingCell($field, 4); ?></td>
                                        <td><?php echo ratingCell($field, 3); ?></td>
                                        <td><?php echo ratingCell($field, 2); ?></td>
                                        <td><?php echo ratingCell($field, 1); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-section">
                    <p class="section-title">Part III. Comments and Suggestions</p>
                    <div class="form-grid">
                        <div class="form-field full-width">
                            <label for="improvement_reason">Please provide your reason/s for any Neither, Disagree, or Strongly Disagree answer.</label>
                            <textarea id="improvement_reason" name="improvement_reason"><?php echo postedValue('improvement_reason'); ?></textarea>
                        </div>
                        <div class="form-field full-width">
                            <label for="service_suggestions">Please give comments/suggestions to help us improve our service/s.</label>
                            <textarea id="service_suggestions" name="service_suggestions"><?php echo postedValue('service_suggestions'); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="submit-area">
                    <button type="submit" name="submit_evaluation" class="submit-btn">Submit Feedback</button>
                    <div class="thank-you">Thank you!</div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include 'bottom_nav.php'; ?>
</body>
</html>

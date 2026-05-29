<?php

require_once "config/session.php";
require_once "config/db.php";
require_once "config/evaluation_helper.php";

ensureEventRegistrationCodeSupport($conn);

$success = "";
$error   = "";

$event_id = intval($_GET['event_id'] ?? 0);
$event_code = normalizeEventCode((string) ($_GET['event_code'] ?? $_GET['code'] ?? ''));
$event_title = "";
$show_success_modal = false;
$isAlreadyRegistered = false;
$isPastEvent = false;

$existing_user = null;
$prefill = [
    'email' => $email ?? '',
    'first_name' => $fname ?? '',
    'last_name' => $lname ?? '',
    'contact_number' => '',
    'age' => '',
    'sex' => ''
];

function eventRegistrationValue(string $key, array $prefill): string {
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : (string) ($prefill[$key] ?? '');
}

function normalizePrefillContactNumber(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);

    if(strlen($digits) === 12 && substr($digits, 0, 2) === '63'){
        return '0' . substr($digits, 2);
    }

    return strlen($digits) > 11 ? substr($digits, -11) : $digits;
}

function accountPrefillColumn(mysqli $conn, string $table, array $candidates): ?string {
    foreach($candidates as $candidate){
        if(databaseColumnExists($conn, $table, $candidate)){
            return $candidate;
        }
    }

    return null;
}

if(isset($_SESSION['user_id'], $_SESSION['account_type'])){
    $accountTable = $_SESSION['account_type'] === 'business_owner' ? 'business_owner' : 'consumers';
    $accountIdColumn = $_SESSION['account_type'] === 'business_owner' ? 'b_id' : 'c_id';
    $selectColumns = ['fname', 'lname', 'email'];

    $genderColumn = accountPrefillColumn($conn, $accountTable, ['gender', 'sex']);
    $birthdayColumn = accountPrefillColumn($conn, $accountTable, ['birthday', 'birthdate', 'date_of_birth']);
    $phoneColumn = accountPrefillColumn($conn, $accountTable, ['phone', 'contact_number', 'mobile', 'contact']);

    if($genderColumn){
        $selectColumns[] = $genderColumn . " AS prefill_gender";
    }

    if($birthdayColumn){
        $selectColumns[] = $birthdayColumn . " AS prefill_birthday";
    }

    if($phoneColumn){
        $selectColumns[] = $phoneColumn . " AS prefill_phone";
    }

    $accountPrefillStmt = $conn->prepare("
        SELECT " . implode(', ', $selectColumns) . "
        FROM {$accountTable}
        WHERE {$accountIdColumn} = ?
        LIMIT 1
    ");

    if($accountPrefillStmt){
        $accountPrefillStmt->bind_param("i", $_SESSION['user_id']);
        $accountPrefillStmt->execute();
        $accountPrefillResult = $accountPrefillStmt->get_result();

        if($accountPrefillRow = $accountPrefillResult->fetch_assoc()){
            $prefill['email'] = (string) ($accountPrefillRow['email'] ?? $prefill['email']);
            $prefill['first_name'] = (string) ($accountPrefillRow['fname'] ?? $prefill['first_name']);
            $prefill['last_name'] = (string) ($accountPrefillRow['lname'] ?? $prefill['last_name']);
            $prefill['sex'] = (string) ($accountPrefillRow['prefill_gender'] ?? '');
            $prefill['contact_number'] = normalizePrefillContactNumber((string) ($accountPrefillRow['prefill_phone'] ?? ''));

            if(!empty($accountPrefillRow['prefill_birthday'])){
                try {
                    $birthDate = new DateTime((string) $accountPrefillRow['prefill_birthday']);
                    $prefill['age'] = (string) (new DateTime())->diff($birthDate)->y;
                } catch (Throwable $e) {
                    $prefill['age'] = '';
                }
            }
        }

        $accountPrefillStmt->close();
    }
}

$email_check = trim((string) ($_GET['email'] ?? $prefill['email']));

if($event_code === "" && $event_id > 0){
    $eventById = $conn->prepare("
        SELECT event_code
        FROM events
        WHERE id = ?
        LIMIT 1
    ");
    $eventById->bind_param("i", $event_id);
    $eventById->execute();
    $eventByIdResult = $eventById->get_result();

    if($row = $eventByIdResult->fetch_assoc()){
        $event_code = normalizeEventCode($row['event_code']);
    }

    $eventById->close();
}

if($event_code !== "" && $email_check != ""){
    $stmt = $conn->prepare("
        SELECT *
        FROM event_registrations
        WHERE REPLACE(UPPER(event_code), '-', '') = ? AND email = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $event_code, $email_check);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $existing_user = $res->fetch_assoc();
    }
}

$isDisabled = $existing_user ? 'disabled' : '';
$isAlreadyRegistered = $existing_user ? true : $isAlreadyRegistered;

if($isAlreadyRegistered && $error === ""){
    $error = "You are already registered for this event.";
}

/* LOAD EVENT TITLE */
if($event_code !== ""){
    $event = findEventByEvaluationCode($conn, $event_code);

    if($event){
        $event_id = (int) $event['id'];
        $event_code = normalizeEventCode($event['event_code']);
        $event_title = $event['title'];
        $eventDate = date("Y-m-d", strtotime((string) $event['start_date_and_time']));
        $isPastEvent = $eventDate < date("Y-m-d");

        if($isPastEvent && $error === ""){
            $error = "Registration is closed because this event date has already passed.";
        }
    }else{
        $error = "Event not found.";
    }
}

/* SAMPLE NEGOSYO CENTERS */
$negosyo_centers = [
    "Negosyo Center Batangas City",
    "Negosyo Center Lipa City",
    "Negosyo Center Tanauan City",
    "Negosyo Center Sto. Tomas",
    "Negosyo Center Nasugbu"
];

/* SAVE REGISTRATION */
if(isset($_POST['submit_registration'])){

    $event_code = normalizeEventCode((string) ($_POST['event_code'] ?? ''));
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $contact_number = trim($_POST['contact_number']);

    $negosyo_center = trim($_POST['negosyo_center']);

    $age = intval($_POST['age']);
    $sex = trim($_POST['sex']);

    $social_classification = trim($_POST['social_classification']);
    $ofw = isset($_POST['ofw']) ? "Yes" : "No";

    $province = trim($_POST['province']);
$city = trim($_POST['city']);
$barangay = trim($_POST['barangay']);

$residence = $barangay . ', ' . $city . ', ' . $province;

    $business_name =
        trim($_POST['business_name']) == "" ? "N/A" : trim($_POST['business_name']);

    $business_address =
        trim($_POST['business_address']) == "" ? "N/A" : trim($_POST['business_address']);

    $position = trim($_POST['position']);
    $question = trim($_POST['question']);
    $agreed_terms = isset($_POST['agreed_terms']) ? 1 : 0;
    $submitted_event = $event_code !== "" ? findEventByEvaluationCode($conn, $event_code) : null;

    if($submitted_event){
        $event_id = (int) $submitted_event['id'];
        $event_code = normalizeEventCode($submitted_event['event_code']);
        $event_title = $submitted_event['title'];
        $submittedEventDate = date("Y-m-d", strtotime((string) $submitted_event['start_date_and_time']));
        $isPastEvent = $submittedEventDate < date("Y-m-d");
    }

    /* VALIDATION */
    if(!$submitted_event){
        $error = "Event not found.";
    }
    else if($isPastEvent){
        $error = "Registration is closed because this event date has already passed.";
    }
    else if(
        $event_code=="" || $email=="" || $first_name=="" || $last_name=="" ||
        $contact_number=="" || $negosyo_center=="" ||
        $age<=0 || $sex=="" || $social_classification=="" ||
        $residence=="" || $position==""
    ){
        $error = "Please fill all required fields.";
    }
    else if($agreed_terms == 0){
        $error = "You must agree to the terms and agreement.";
    }
    else if(!preg_match('/^[0-9]{11}$/', $contact_number)){
        $error = "Contact number must be exactly 11 digits.";
    }
    else{

        /* PREVENT DUPLICATE */
        $check = $conn->prepare("
            SELECT id
            FROM event_registrations
            WHERE REPLACE(UPPER(event_code), '-', '') = ? AND email = ?
            LIMIT 1
        ");
        $check->bind_param("ss",$event_code,$email);
        $check->execute();
        $dup = $check->get_result();

        $isAlreadyRegistered = false;

if($dup->num_rows > 0){
    $error = "You are already registered for this event.";
    $isAlreadyRegistered = true;
}else{

            $stmt = $conn->prepare("
    INSERT INTO event_registrations(
        event_code,
        email,
        first_name,
        last_name,
        contact_number,
        negosyo_center,
        age,
        sex,
        social_classification,
        ofw,
        province,
        city,
        barangay,
        business_name,
        business_address,
        position,
        question,
        agreed_terms
    )
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

if(!$stmt){
    die("SQL ERROR: " . $conn->error);
}

$stmt->bind_param(
    "ssssssissssssssssi",
    $event_code,
    $email,
    $first_name,
    $last_name,
    $contact_number,
    $negosyo_center,
    $age,
    $sex,
    $social_classification,
    $ofw,
    $province,
    $city,
    $barangay,
    $business_name,
    $business_address,
    $position,
    $question,
    $agreed_terms
);

            if($stmt->execute()){
                $success = "Registration successful.";
                $show_success_modal = true;
            }else{
                $error = "Failed to register.";
            }
            }
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Registration</title>
<link rel="stylesheet" href="assets/css/responsive.css">

<style>

*,
*::before,
*::after{ box-sizing:border-box; }

:root{
    --primary:#001a47;
    --primary-2:#0b3a75;
    --accent:#0f766e;
    --text:#111827;
    --muted:#667085;
    --line:#d9e1ec;
    --surface:#ffffff;
    --soft:#f3f6fb;
    --danger:#b42318;
}

body{
    margin:0;
    font-family:Arial, Helvetica, sans-serif;
    color:var(--text);
    background:
        linear-gradient(135deg, rgba(0,26,71,.08), rgba(15,118,110,.06)),
        #f6f8fc;
}

/* CONTAINER */
.container{
    max-width:860px;
    margin:34px auto;
    background:var(--surface);
    padding:34px;
    padding-bottom:120px;
    border:1px solid rgba(0,26,71,.08);
    border-radius:16px;
    box-shadow:0 18px 45px rgba(15,23,42,0.08);
}

.page-kicker{
    margin:0 0 8px;
    color:var(--accent);
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
}

h2{
    margin:0;
    color:var(--primary);
    font-size:30px;
    line-height:1.2;
}

h3{
    margin:12px 0 0;
    font-size:18px;
    line-height:1.35;
    color:#243b63;
}

.event-subtitle{
    max-width:620px;
    margin:10px 0 28px;
    font-size:15px;
    line-height:1.6;
    color:var(--muted);
}

.form-section{
    padding-top:24px;
    margin-top:24px;
    border-top:1px solid var(--line);
}

.section-title{
    margin:0 0 16px;
    font-size:15px;
    color:var(--primary);
    font-weight:800;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px 20px;
}

.form-field{
    min-width:0;
}

.full-width{
    grid-column:1 / -1;
}

/* INPUTS */
label{
    display:block;
    margin-bottom:7px;
    color:#26344d;
    font-size:13px;
    font-weight:700;
}

input,
select,
textarea{
    width:100%;
    margin:0;
    padding:12px 13px;
    border:1px solid var(--line);
    border-radius:8px;
    background:#fff;
    color:var(--text);
    font-size:14px;
    outline:none;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

textarea{
    resize:vertical;
    min-height:118px;
}

input:focus,
select:focus,
textarea:focus{
    border-color:var(--primary-2);
    box-shadow:0 0 0 4px rgba(11,58,117,.12);
}

input::placeholder,
textarea::placeholder{
    color:#98a2b3;
}

input[type="checkbox"]{
    width:18px;
    height:18px;
    flex:0 0 18px;
    margin:0;
    accent-color:var(--primary);
}

.checkbox-label{
    display:flex;
    align-items:center;
    gap:10px;
    margin:4px 0 0;
    color:#26344d;
}

.required{
    color:var(--danger);
}

.form-alert{
    margin:0 0 20px;
    padding:12px 14px;
    border-radius:8px;
    border:1px solid rgba(180,35,24,.22);
    background:#fff4f2;
    color:var(--danger);
    font-size:14px;
    font-weight:700;
}

/* BUTTON */
button{
    width:100%;
    margin-top:26px;
    padding:14px 18px;
    border:none;
    background:linear-gradient(135deg, var(--primary), var(--primary-2));
    color:#fff;
    border-radius:8px;
    font-weight:800;
    font-size:15px;
    cursor:pointer;
    box-shadow:0 12px 22px rgba(0,26,71,.18);
    transition:transform .18s ease, box-shadow .18s ease, opacity .18s ease;
}

button:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 28px rgba(0,26,71,.24);
}

button:disabled{
    background:#98a2b3 !important;
    cursor:not-allowed !important;
    box-shadow:none;
    transform:none;
}

/* AGREEMENT */
.agreement-wrapper{
    margin-top:24px;
    padding-top:22px;
    border-top:1px solid var(--line);
}

.agreement-head{
    display:flex;
    gap:10px;
    align-items:flex-start;
}

.agreement-title{
    font-weight:800;
    font-size:15px;
    color:#26344d;
}

.agreement-text{
    margin-top:10px;
    margin-left:28px;
    font-size:13px;
    line-height:1.65;
    color:#5b6474;
    text-align:justify;
    word-break:break-word;
}

/* ===============================
   SUCCESS MODAL
================================ */
.success-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.35);
    display:flex;
    justify-content:center;
    align-items:center;
    z-index:9999;
    animation:fadeIn .2s ease;
}

.success-box{
    width:300px;
    background:#fff;
    border-radius:16px;
    padding:28px 22px;
    text-align:center;
    box-shadow:0 24px 50px rgba(15,23,42,.18);
    animation:pop .25s ease;
}

.check-circle{
    width:72px;
    height:72px;
    border-radius:50%;
    margin:0 auto 12px;
    background:#0a7d2c;
    display:flex;
    align-items:center;
    justify-content:center;
    animation:scaleIn .35s ease;
}

.check{
    color:#fff;
    font-size:38px;
    font-weight:bold;
    animation:checkPop .4s ease;
}

.success-text{
    font-size:18px;
    font-weight:700;
    color:#111;
}

@keyframes fadeIn{
    from{opacity:0;}
    to{opacity:1;}
}
@keyframes pop{
    from{transform:scale(.8);}
    to{transform:scale(1);}
}
@keyframes scaleIn{
    0%{transform:scale(0);}
    80%{transform:scale(1.1);}
    100%{transform:scale(1);}
}
@keyframes checkPop{
    from{transform:scale(0);}
    to{transform:scale(1);}
}

.theme-dark .container,
.theme-dark .success-box{
    background:#111111;
    border-color:#2d2d2d;
}

.theme-dark h2,
.theme-dark h3,
.theme-dark .section-title,
.theme-dark label,
.theme-dark .agreement-title,
.theme-dark .success-text{
    color:#ededed;
}

.theme-dark .event-subtitle,
.theme-dark .agreement-text{
    color:#b8b8b8;
}

.theme-dark .form-section,
.theme-dark .agreement-wrapper{
    border-top-color:#2d2d2d;
}

.theme-dark .form-alert{
    background:#2a1513;
    border-color:#65312b;
    color:#ffb4ab;
}

/* MOBILE */
@media (max-width:768px){
    body{ background:#fff; }
    .container{
        margin:0;
        max-width:100%;
        border-radius:0;
        border:none;
        box-shadow:none;
        padding:22px 16px 110px;
    }
    h2{
        font-size:25px;
    }
    .form-grid{
        grid-template-columns:1fr;
        gap:16px;
    }
    input,
    select,
    textarea{
        font-size:16px;
        padding:13px;
    }
    .agreement-text{
        margin-left:0;
        text-align:left;
    }
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<p class="page-kicker">Official Event Form</p>
<h2>Event Registration</h2>

<?php if($event_title != ""){ ?>
<h3><?php echo htmlspecialchars($event_title); ?></h3>
<?php } ?>

<p class="event-subtitle">
Please complete the form below to register for this event.
</p>

<?php if($error!=""){ ?>
<div class="form-alert"><?php echo $error; ?></div>
<?php } ?>

<form method="POST">

<input type="hidden" name="event_code" value="<?php echo htmlspecialchars($event_code); ?>">

<div class="form-section">
    <p class="section-title">Personal Information</p>

    <div class="form-grid">
        <div class="form-field full-width">
            <label>Email <span class="required">*</span></label>
            <input
                type="email"
                name="email"
                value="<?php echo htmlspecialchars(eventRegistrationValue('email', $prefill)); ?>"
                required
            >
        </div>

        <div class="form-field">
            <label>First Name <span class="required">*</span></label>
            <input type="text" name="first_name" value="<?php echo htmlspecialchars(eventRegistrationValue('first_name', $prefill)); ?>" required>
        </div>

        <div class="form-field">
            <label>Last Name <span class="required">*</span></label>
            <input type="text" name="last_name" value="<?php echo htmlspecialchars(eventRegistrationValue('last_name', $prefill)); ?>" required>
        </div>

        <div class="form-field">
            <label>Contact Number <span class="required">*</span></label>
            <input
                type="text"
                name="contact_number"
                maxlength="11"
                pattern="[0-9]{11}"
                inputmode="numeric"
                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
                value="<?php echo htmlspecialchars(eventRegistrationValue('contact_number', $prefill)); ?>"
                required
            >
        </div>

        <div class="form-field">
            <label>Age <span class="required">*</span></label>
            <input type="number" name="age" value="<?php echo htmlspecialchars(eventRegistrationValue('age', $prefill)); ?>" required>
        </div>

        <div class="form-field">
            <label>Sex <span class="required">*</span></label>
            <?php $selectedSex = eventRegistrationValue('sex', $prefill); ?>
            <select name="sex" required>
                <option value="">Select</option>
                <option value="Male" <?php echo strcasecmp($selectedSex, 'Male') === 0 ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo strcasecmp($selectedSex, 'Female') === 0 ? 'selected' : ''; ?>>Female</option>
                <option value="Other" <?php echo strcasecmp($selectedSex, 'Other') === 0 || strcasecmp($selectedSex, 'Prefer not to Say') === 0 ? 'selected' : ''; ?>>Other</option>
            </select>
        </div>

        <div class="form-field">
            <label>Social Classification <span class="required">*</span></label>
            <select name="social_classification" required>
                <option value="">Select</option>
                <option>Abled</option>
                <option>Differently Abled</option>
                <option>Indigenous Person</option>
            </select>
        </div>

        <div class="form-field full-width">
            <label class="checkbox-label"><input type="checkbox" name="ofw"> OFW</label>
        </div>
    </div>
</div>

<div class="form-section">
    <p class="section-title">Location and Center</p>

    <div class="form-grid">
        <div class="form-field full-width">
            <label>Negosyo Center <span class="required">*</span></label>
            <select name="negosyo_center" required>
                <option value="">Select Negosyo Center</option>
                <?php foreach($negosyo_centers as $nc){ ?>
                <option value="<?php echo $nc; ?>"><?php echo $nc; ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-field">
            <label>Province <span class="required">*</span></label>
            <select id="province" name="province" required>
                <option value="">Select Province</option>
            </select>
        </div>

        <div class="form-field">
            <label>City / Municipality <span class="required">*</span></label>
            <select id="city" name="city" required>
                <option value="">Select City</option>
            </select>
        </div>

        <div class="form-field full-width">
            <label>Barangay <span class="required">*</span></label>
            <select id="barangay" name="barangay" required>
                <option value="">Select Barangay</option>
            </select>
        </div>
    </div>
</div>

<div class="form-section">
    <p class="section-title">Business Details</p>

    <div class="form-grid">
        <div class="form-field">
            <label>Business Name</label>
            <input type="text" name="business_name" placeholder="N/A if not applicable">
        </div>

        <div class="form-field">
            <label>Business Address</label>
            <input type="text" name="business_address" placeholder="N/A if not applicable">
        </div>

        <div class="form-field full-width">
            <label>Position <span class="required">*</span></label>
            <select name="position" required>
                <option value="">Select</option>
                <option>Owner</option>
                <option>Manager/Employee</option>
                <option>Consumer</option>
                <option>N/A</option>
            </select>
        </div>

        <div class="form-field full-width">
            <label>Question</label>
            <textarea name="question" rows="4" placeholder="Write your question for the event organizers"></textarea>
        </div>
    </div>
</div>

<div class="agreement-wrapper">

    <div class="agreement-head">
        <input type="checkbox" name="agreed_terms" required>
        <div class="agreement-title">I agree to the terms and agreement</div>
    </div>

    <div class="agreement-text">
        The DTI-Batangas is committed to respect your privacy and recognizes the importance of protecting the information collected about you. Personal information that you provided during the registration shall only be processed in relation to your attendance to this event. By signing this form, you agree that all personal information you submit in relation to this event shall be protected with reasonable and appropriate measures, and shall only be retained as long as necessary. The webinar session will also be recorded. By continuing your participation, you are consenting to the recording of the session. If you wish to be opted out from the processing of your information and our database, please do not hesitate to let us know by sending an email to <strong>r04a.batangas@dti.gov.ph</strong>.
    </div>

</div>

<button type="submit" name="submit_registration"
<?php echo ($isAlreadyRegistered || $isPastEvent) ? 'disabled style="background:gray;cursor:not-allowed;"' : ''; ?>>
<?php echo $isAlreadyRegistered ? 'Already Registered' : ($isPastEvent ? 'Registration Closed' : 'Submit Registration'); ?>
</button>

</form>

</div>

<?php if($show_success_modal){ ?>
<div class="success-overlay" id="successModal">
    <div class="success-box">
        <div class="check-circle">
            <div class="check">&#10003;</div>
        </div>
        <div class="success-text">Registration Successful</div>
    </div>
</div>

<script>
setTimeout(function(){
    document.getElementById("successModal").style.display = "none";
},1000);
</script>
<?php } ?>

<script>
const provinceSelect = document.getElementById("province");
const citySelect = document.getElementById("city");
const barangaySelect = document.getElementById("barangay");

/* LOAD PROVINCES */
fetch("get_provinces.php")
.then(res => res.json())
.then(data => {
    data.forEach(p => {
        let opt = document.createElement("option");
        opt.value = p.name;       // ✅ SAVE NAME
        opt.textContent = p.name;
        opt.dataset.id = p.id;    // ✅ USE ID FOR FETCH
        provinceSelect.appendChild(opt);
    });
});

/* LOAD CITIES */
provinceSelect.addEventListener("change", function(){

    citySelect.innerHTML = '<option value="">Select City</option>';
    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';

let provinceId = this.options[this.selectedIndex].dataset.id;
    if(!provinceId) return;

    fetch("get_cities.php?province_id=" + provinceId)
    .then(res => res.json())
    .then(data => {
        data.forEach(c => {
    let opt = document.createElement("option");
    opt.value = c.name;        // ✅ SAVE NAME
    opt.textContent = c.name;
    opt.dataset.id = c.id;     // ✅ USE ID FOR FETCH
    citySelect.appendChild(opt);
});
    });

});

/* LOAD BARANGAYS */
citySelect.addEventListener("change", function(){

    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';

let cityId = this.options[this.selectedIndex].dataset.id;    if(!cityId) return;

    fetch("get_barangays.php?city_id=" + cityId)
    .then(res => res.json())
    .then(data => {
        data.forEach(b => {
            let opt = document.createElement("option");
            opt.value = b.name;   // (you store name ✔)
            opt.textContent = b.name;
            barangaySelect.appendChild(opt);
        });
    });

});
</script>

<?php include 'bottom_nav.php'; ?>
</body>
</html>

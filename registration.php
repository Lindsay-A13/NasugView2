<?php

require_once "config/session.php";
require_once "config/db.php";

$success = "";
$error   = "";

$event_id = intval($_GET['event_id'] ?? 0);
$event_title = "";
$show_success_modal = false;
$isAlreadyRegistered = false;

$existing_user = null;

$email_check = $_GET['email'] ?? '';

if($event_id > 0 && $email_check != ""){
    $stmt = $conn->prepare("
        SELECT *
        FROM event_registrations
        WHERE event_id = ? AND email = ?
        LIMIT 1
    ");
    $stmt->bind_param("is", $event_id, $email_check);
    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows > 0){
        $existing_user = $res->fetch_assoc();
    }
}

$isDisabled = $existing_user ? 'disabled' : '';

/* LOAD EVENT TITLE */
if($event_id > 0){

    $ev = $conn->prepare("
        SELECT title
        FROM events
        WHERE id = ?
        LIMIT 1
    ");

    $ev->bind_param("i",$event_id);
    $ev->execute();
    $evResult = $ev->get_result();

    if($row = $evResult->fetch_assoc()){
        $event_title = $row['title'];
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

    /* VALIDATION */
    if(
        $email=="" || $first_name=="" || $last_name=="" ||
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
            WHERE event_id = ? AND email = ?
            LIMIT 1
        ");
        $check->bind_param("is",$event_id,$email);
        $check->execute();
        $dup = $check->get_result();

        $isAlreadyRegistered = false;

if($dup->num_rows > 0){
    $error = "You are already registered for this event.";
    $isAlreadyRegistered = true;
}

            $stmt = $conn->prepare("
    INSERT INTO event_registrations(
        event_id,
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
    "isssssissssssssssi",
    $event_id,
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
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Registration</title>

<style>

*{ box-sizing:border-box; }

body{
    margin:0;
    font-family:Arial;
    background:#f6f7fb;
}

/* HEADER */
.header{
    width:100%;
    background:#fff;
    padding:14px 0;
    display:flex;
    justify-content:center;
    border-bottom:1px solid #eee;
}

.header img{ height:42px; }

/* CONTAINER */
.container{
    max-width:720px;
    margin:30px auto;
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 4px 14px rgba(0,0,0,0.06);
}

/* INPUTS */
input,select,textarea{
    width:100%;
    padding:10px;
    margin-top:6px;
    margin-bottom:15px;
    border:1px solid #ddd;
    border-radius:8px;
    font-size:14px;
}

input[type="checkbox"]{
    width:16px;
    height:16px;
    accent-color:#001a47;
}

label{
    font-weight:600;
    display:block;
}

/* BUTTON */
button{
    width:100%;
    padding:12px;
    border:none;
    background:#001a47;
    color:#fff;
    border-radius:8px;
    font-weight:600;
    font-size:15px;
    cursor:pointer;
}

.event-subtitle{
    font-size:14px;
    color:#555;
    margin-bottom:20px;
}

/* AGREEMENT */
.agreement-head{
    display:flex;
    gap:8px;
    align-items:flex-start;
}

.agreement-title{
    font-weight:700;
    font-size:16px;
}

.agreement-text{
    margin-top:8px;
    margin-left:30px;
    font-size:13px;
    line-height:1.6;
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
    width:260px;
    background:#fff;
    border-radius:14px;
    padding:25px 20px;
    text-align:center;
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

/* MOBILE */
@media (max-width:768px){
    body{ background:#fff; }
    .container{
        margin:0;
        max-width:100%;
        border-radius:0;
        box-shadow:none;
        padding:16px;
    }
    input,select,textarea{
        font-size:16px;
        padding:13px;
    }
    .agreement-text{
        margin-left:0;
    }
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
    <img src="assets/images/logo.png" alt="Logo">
</div>

<div class="container">

<h2>Event Registration</h2>

<?php if($event_title != ""){ ?>
<h3><?php echo htmlspecialchars($event_title); ?></h3>
<?php } ?>

<p class="event-subtitle">
Please complete the form below to register for this event.
</p>

<?php if($error!=""){ ?>
<div style="color:#d60000;margin-bottom:10px;"><?php echo $error; ?></div>
<?php } ?>

<form method="POST">

<input type="hidden" name="event_id" value="<?php echo $event_id; ?>">

<label>Email *</label>
<input type="email" name="email" required>

<label>First Name *</label>
<input type="text" name="first_name" required>

<label>Last Name *</label>
<input type="text" name="last_name" required>

<label>Contact Number *</label>
<input
    type="text"
    name="contact_number"
    maxlength="11"
    pattern="[0-9]{11}"
    inputmode="numeric"
    oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
    required
>

<label>Negosyo Center *</label>
<select name="negosyo_center" required>
<option value="">Select Negosyo Center</option>
<?php foreach($negosyo_centers as $nc){ ?>
<option value="<?php echo $nc; ?>"><?php echo $nc; ?></option>
<?php } ?>
</select>

<label>Age *</label>
<input type="number" name="age" required>

<label>Sex *</label>
<select name="sex" required>
<option value="">Select</option>
<option>Male</option>
<option>Female</option>
<option>Other</option>
</select>

<label>Social Classification *</label>
<select name="social_classification" required>
<option value="">Select</option>
<option>Abled</option>
<option>Differently Abled</option>
<option>Indigenous Person</option>
</select>

<label><input type="checkbox" name="ofw"> OFW</label>

<label>Province *</label>
<select id="province" name="province" required>
    <option value="">Select Province</option>
</select>

<label>City / Municipality *</label>
<select id="city" name="city" required>
    <option value="">Select City</option>
</select>

<label>Barangay *</label>
<select id="barangay" name="barangay" required>
    <option value="">Select Barangay</option>
</select>

<label>Business Name</label>
<input type="text" name="business_name" placeholder="N/A if not applicable">

<label>Business Address</label>
<input type="text" name="business_address" placeholder="N/A if not applicable">

<label>Position *</label>
<select name="position" required>
<option value="">Select</option>
<option>Owner</option>
<option>Manager/Employee</option>
<option>Consumer</option>
<option>N/A</option>
</select>

<label>Question</label>
<textarea name="question" rows="4"></textarea>

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
<?php echo $isAlreadyRegistered ? 'disabled style="background:gray;cursor:not-allowed;"' : ''; ?>>
<?php echo $isAlreadyRegistered ? 'Already Registered' : 'Submit Registration'; ?>
</button>

</form>

</div>

<?php if($show_success_modal){ ?>
<div class="success-overlay" id="successModal">
    <div class="success-box">
        <div class="check-circle">
            <div class="check">✓</div>
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
</body>
</html>

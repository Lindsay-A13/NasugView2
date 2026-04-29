<?php
session_start();
require_once "config/db.php";

/* LOAD BUSINESS LINES (FIX) */
$lines = $conn->query("
    SELECT line_id, line_name
    FROM business_lines
    ORDER BY line_name ASC
");

/* VARIABLES */
$login_error = '';
$register_error = '';
$register_success = '';

$email_value = '';
$password_value = '';
$remember = false;

/* REMEMBER ME */
if(isset($_COOKIE['remember_email']) && isset($_COOKIE['remember_password'])){
    $email_value = $_COOKIE['remember_email'];
    $password_value = $_COOKIE['remember_password'];
    $remember = true;
}

/* ================= LOGIN ================= */
if(isset($_POST['login'])){

    $login_input = trim($_POST['email']);
    $password_input = trim($_POST['password']);
    $remember_me = isset($_POST['remember']);

    if(empty($login_input) || empty($password_input)){
        $login_error = "Please provide email and password.";
    }
    else{

        $user = null;
        $account_type = "";

        $stmt = $conn->prepare("
            SELECT c_id AS id, username, password, email
            FROM consumers
            WHERE email=? OR username=?
        ");
        $stmt->bind_param("ss", $login_input, $login_input);
        $stmt->execute();
        $res = $stmt->get_result();

        if($res->num_rows === 1){
            $user = $res->fetch_assoc();
            $account_type = "consumer";
        }
        $stmt->close();

        if(!$user){
            $stmt = $conn->prepare("
                SELECT b_id AS id, username, password, email
                FROM business_owner
                WHERE email=? OR username=?
            ");
            $stmt->bind_param("ss", $login_input, $login_input);
            $stmt->execute();
            $res = $stmt->get_result();

            if($res->num_rows === 1){
                $user = $res->fetch_assoc();
                $account_type = "business_owner";
            }
            $stmt->close();
        }

        if($user && password_verify($password_input, $user['password'])){

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['account_type'] = $account_type;

            if($remember_me){
                setcookie("remember_email", $login_input, time()+60*60*24*30, "/");
                setcookie("remember_password", $password_input, time()+60*60*24*30, "/");
            }else{
                setcookie("remember_email", "", time()-3600, "/");
                setcookie("remember_password", "", time()-3600, "/");
            }

            header("Location: home.php");
            exit();
        }
        else{
            $login_error = "Invalid email/username or password.";
        }
    }
}

/* ================= REGISTER ================= */
if(isset($_POST['register'])){

    $account_type  = trim($_POST['account_type']);
    $username      = trim($_POST['username']);
    $email         = trim($_POST['email']);
    $password      = trim($_POST['password']);
    $first_name    = trim($_POST['first_name']);
    $last_name     = trim($_POST['last_name']);
    $gender        = trim($_POST['gender']);
    $birthday      = trim($_POST['birthday']);
    $address       = "";
    $business_name = isset($_POST['business_name']) ? trim($_POST['business_name']) : "";
    $business_line = isset($_POST['business_line']) ? trim($_POST['business_line']) : "";

    if($account_type === "consumer"){
        $business_name = null;
        $business_line = null;
    }

    $age = null;

    if(!empty($birthday)){
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
    }

    if(
        empty($account_type) ||
        empty($username) ||
        empty($email) ||
        empty($password) ||
        empty($first_name) ||
        empty($last_name) ||
        empty($gender) ||
        empty($birthday)
    ){
        $register_error = "All fields required.";
    }
    else{

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $insert = null;

        if($account_type === "consumer"){

            $insert = $conn->prepare("
                INSERT INTO consumers
                (username,password,email,fname,lname,gender,birthday,age,address)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");

            $age = (int)$age;

            $insert->bind_param(
                "sssssssis",
                $username,
                $hashed_password,
                $email,
                $first_name,
                $last_name,
                $gender,
                $birthday,
                $age,
                $address
            );

        }
        elseif($account_type === "business_owner"){

            $insert = $conn->prepare("
                INSERT INTO business_owner
                (username,password,email,fname,lname,gender,birthday,age,address,business_name,line_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");

            $insert->bind_param(
                "ssssssssssi",
                $username,
                $hashed_password,
                $email,
                $first_name,
                $last_name,
                $gender,
                $birthday,
                $age,
                $address,
                $business_name,
                $business_line
            );

        }

        if($insert){

            if(!$insert->execute()){
                die("INSERT ERROR: " . $insert->error);
            }

            $_SESSION['user_id'] = $conn->insert_id;
            $_SESSION['username'] = $username;
            $_SESSION['account_type'] = $account_type;

            $insert->close();

            header("Location: home.php");
            exit();
        }
    }
}


$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/login.css">

<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container" id="container">

<!-- Sign Up Form -->
<div class="form-container sign-up">
  <?php if($register_error) echo "<p style='color:red;'>$register_error</p>"; ?>
  <?php if($register_success) echo "<p style='color:green;'>$register_success</p>"; ?>

  <form method="POST" class="signup-form" enctype="multipart/form-data">

    <img src="assets/images/logo.png" alt="NasugView Logo">

<select name="account_type" id="account_type" class="textbox" required>
    <option value="" disabled selected hidden>Select Account Type</option>
    <option value="consumer">Consumer</option>
    <option value="business_owner">Business Owner</option>
</select>

    <input type="text" name="username" placeholder="Username" required />
    <!-- BUSINESS NAME (HIDDEN BY DEFAULT) -->
<select name="business_line" id="business_line" class="textbox business-hidden" style="display:none;"><option value="" disabled selected>Business Line</option>

<?php while($line = $lines->fetch_assoc()): ?>

<option value="<?= $line['line_id']; ?>">
<?= htmlspecialchars($line['line_name']); ?>

</option>

<?php endwhile; ?>

</select>

<input type="text"
       name="business_name"
       id="business_name"
       placeholder="Business Name"
       class="textbox business-hidden"
       style="display:none;">


    <input type="email" name="email" placeholder="Email" required />
<div style="position:relative; width:100%;">



    <input type="password"
           name="password"
           placeholder="Password"
           required
           id="password"
           class="textbox"
           style="padding-right:45px;">

    <i class="fa fa-eye-slash"
       id="togglePassword"
       style="
            position:absolute;
            right:15px;
            top:50%;
            transform:translateY(-50%);
            cursor:pointer;
            color:#001a47;
            font-size:16px;
       "></i>

</div>


    <input type="text" name="first_name" placeholder="First Name" required>

    <input type="text" name="last_name" placeholder="Last Name" required>

    <select name="gender" required class="textbox">
        <option value="">Gender</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
        <option value="Other">Prefer not to Say</option>
    </select>

<input type="text"
       name="birthday"
       id="birthday"
       class="textbox"
       placeholder="Birthday"
       onfocus="this.type='date'"
       onblur="if(!this.value)this.type='text'"
       required>

    <input type="hidden" name="age" id="age">


<!-- BUSINESS PERMIT FIELD (HIDDEN BY DEFAULT) -->
<div id="permit_container" class="permit-wrapper">    <!-- HIDDEN REAL INPUT -->
    <input type="file"
           name="business_permit"
           id="business_permit"
           accept=".pdf,image/jpeg,image/jpg,image/png,image/*"
           style="display:none;">

    <!-- CUSTOM BUTTON -->
    <div class="permit-button"
         onclick="document.getElementById('business_permit').click()">

        <span id="permit_text">Upload Business Permit</span>

        <i class="fa fa-upload"></i>

    </div>

    <!-- HELPER TEXT -->
    <small class="permit-help">
        PDF and image files only.
    </small>

</div>



    <div class="social-icons">
      <a href="#"><i class="fab fa-google"></i></a>
      <a href="#"><i class="fab fa-facebook-f"></i></a>
    </div>

    <button type="submit" name="register">Sign Up</button>

</form>

</div>


 <div class="form-container sign-in">

<?php if($login_error) echo "<p style='color:red;'>$login_error</p>"; ?>

<form method="POST">

    <img src="assets/images/logo.png" alt="NasugView Logo">

    <div class="input-group">
        <i class="fa-solid fa-envelope"></i>
       <input type="text" name="email" placeholder="Email or Username"
value="<?php echo htmlspecialchars($email_value); ?>" required>

    </div>

    <div class="input-group password">
        <i class="fa-solid fa-lock"></i>
        <input type="password" name="password"
        id="loginPassword"
        placeholder="Password"
        value="<?php echo htmlspecialchars($password_value); ?>" required>
        <i class="fa-solid fa-eye-slash" id="toggleLoginPassword"></i>
    </div>

    <div class="options-row">
        <label>
            <input type="checkbox" name="remember"
            <?php echo $remember ? 'checked' : ''; ?>>
            Remember Me
        </label>
        <a href="#">Forgot password?</a>
    </div>

    <button type="submit" name="login">Login</button>

</form>
</div>



  <!-- Toggle Panels -->
  <div class="toggle-container">
    <div class="toggle">
      <div class="toggle-panel toggle-left">

    <span class="phrase1">
        Discover <span class="star">★</span> Connect <span class="star">★</span> Support
    </span>

    <span class="phrase2">
        Thrive with NasugView
    </span>

    <button class="hidden" id="loginBtn">Login</button>

    <span class="signup-text">
        Already have account?
    </span>

</div>
      <div class="toggle-panel toggle-right">
        <span class="phrase1">
    Discover <span class="star">★</span> Connect <span class="star">★</span> Support
</span>

<span class="phrase2">Thrive with NasugView</span>

<button class="hidden" id="registerBtn">Sign Up</button>

<span class="signup-text">
    Don't have account?
</span>

      </div>
    </div>
  </div>
</div>

<script>
const container = document.getElementById('container');
const accountType = document.getElementById("account_type");
const permitContainer = document.getElementById("permit_container");
const permitInput = document.getElementById("business_permit");

const businessNameInput = document.getElementById("business_name");
const businessLineInput = document.getElementById("business_line");

const togglePassword = document.getElementById("togglePassword");
const passwordInput = document.getElementById("password");
const toggleLoginPassword = document.getElementById("toggleLoginPassword");
const loginPassword = document.getElementById("loginPassword");
/* PASSWORD TOGGLE (SIGN UP) */
if (togglePassword && passwordInput) {
    togglePassword.addEventListener("click", function () {
        if (passwordInput.type === "password") {
            passwordInput.type = "text";
            this.classList.remove("fa-eye-slash");
            this.classList.add("fa-eye");
        } else {
            passwordInput.type = "password";
            this.classList.remove("fa-eye");
            this.classList.add("fa-eye-slash");
        }
    });
}

/* PASSWORD TOGGLE (LOGIN) */
if (toggleLoginPassword && loginPassword) {
    toggleLoginPassword.addEventListener("click", function(){
        if(loginPassword.type === "password"){
            loginPassword.type = "text";
            this.classList.replace("fa-eye-slash","fa-eye");
        } else {
            loginPassword.type = "password";
            this.classList.replace("fa-eye","fa-eye-slash");
        }
    });
}

/* TOGGLE PANELS */
document.getElementById('registerBtn').addEventListener('click', ()=>{
    container.classList.add("active");
});

document.getElementById('loginBtn').addEventListener('click', ()=>{
    container.classList.remove("active");
});

/* BIRTHDAY LOGIC */
const birthdayInput = document.getElementById("birthday");

birthdayInput.addEventListener("keydown", function(e){
    e.preventDefault();
});

birthdayInput.addEventListener("paste", function(e){
    e.preventDefault();
});

const wrapper = document.createElement("div");
wrapper.style.position = "relative";
wrapper.style.width = birthdayInput.offsetWidth + "px";

birthdayInput.parentNode.insertBefore(wrapper, birthdayInput);
wrapper.appendChild(birthdayInput);

const overlay = document.createElement("div");
overlay.style.position = "absolute";
overlay.style.left = "0";
overlay.style.top = "0";
overlay.style.width = "100%";
overlay.style.height = "100%";
overlay.style.display = "flex";
overlay.style.alignItems = "center";
overlay.style.padding = "12px 15px";
overlay.style.color = "#000000";
overlay.style.fontSize = "14px";
overlay.style.pointerEvents = "none";
overlay.style.zIndex = "3";

wrapper.appendChild(overlay);

birthdayInput.style.color = "#6c6c6c";
birthdayInput.style.caretColor = "#6c6c6c";
birthdayInput.style.position = "relative";
birthdayInput.style.zIndex = "2";

birthdayInput.addEventListener("change", function(){

    if(!this.value){
        overlay.innerHTML = "";
        return;
    }

    const birth = new Date(this.value);
    const today = new Date();

    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();

    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--;
    }

    const month = String(birth.getMonth()+1).padStart(2,'0');
    const day   = String(birth.getDate()).padStart(2,'0');
    const year  = birth.getFullYear();

    overlay.innerHTML = `${month}/${day}/${year} (${age} years old)`;
});

/* INITIAL STATE */
permitContainer.style.display = "none";

businessNameInput.style.display = "none";
businessLineInput.style.display = "none";

/* ACCOUNT TYPE CHANGE */
accountType.addEventListener("change", function(){
if(this.value === "business_owner"){

    businessNameInput.style.display = "block";
    businessLineInput.style.display = "block";
    permitContainer.style.display = "block";

    businessNameInput.disabled = false;
    businessLineInput.disabled = false;
    permitInput.disabled = false;

}
else{

    businessNameInput.style.display = "none";
    businessLineInput.style.display = "none";
    permitContainer.style.display = "none";

    businessNameInput.disabled = true;
    businessLineInput.disabled = true;
    permitInput.disabled = true;

}

});

/* PERMIT FILE NAME DISPLAY */
permitInput.addEventListener("change", function(){
    if(this.files.length > 0){
        document.getElementById("permit_text").textContent = this.files[0].name;
    }
});
</script>
</body>
</html>
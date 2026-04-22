<?php
session_start();
require_once "db.php";

/* ================= VARIABLES ================= */
$login_error = '';
$register_error = '';

$email_value = '';
$password_value = '';
$remember = false;

/* REMEMBER ME */
if(isset($_COOKIE['remember_email'])){
    $email_value = $_COOKIE['remember_email'];
    $remember = true;
}

/* ================= LOGIN ================= */
if(isset($_POST['login'])){
    $login_input = trim($_POST['email']);
    $password_input = trim($_POST['password']);
    $remember_me = isset($_POST['remember']);

    if(empty($login_input) || empty($password_input)){
        $login_error = "Please provide email and password.";
    } else {
        // Case-insensitive login
        $stmt = $conn->prepare("SELECT dti_id AS id, username, password, email FROM dti_user WHERE LOWER(email)=LOWER(?) OR LOWER(username)=LOWER(?)");
        $login_lower = strtolower($login_input);
        $stmt->bind_param("ss", $login_lower, $login_lower);
        $stmt->execute();
        $res = $stmt->get_result();

        if($res->num_rows === 1){
            $user = $res->fetch_assoc();

            // Verify password (support old plain-text passwords temporarily)
            if($password_input === $user['password'] || password_verify($password_input, $user['password'])){
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                // Only store email in cookie for Remember Me
                if($remember_me){
                    setcookie("remember_email", $login_input, time()+60*60*24*30, "/");
                } else {
                    setcookie("remember_email", "", time()-3600, "/");
                }

                header("Location: dashboard.php");
                exit();
            } else {
                $login_error = "Invalid email/username or password.";
            }
        } else {
            $login_error = "Invalid email/username or password.";
        }
        $stmt->close();
    }
}

/* ================= REGISTER ================= */
if(isset($_POST['register'])){
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $password   = trim($_POST['password']);
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $gender     = trim($_POST['gender']);
    $birthday   = trim($_POST['birthday']);
    $address    = trim($_POST['address']);

    if(empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($gender) || empty($birthday) || empty($address)){
        $register_error = "All fields required.";
    } else {
        $check = $conn->prepare("SELECT dti_id FROM dti_user WHERE username=? OR email=?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if($check->num_rows > 0){
            $register_error = "Username or email already exists.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $birthDate = new DateTime($birthday);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;

            $insert = $conn->prepare("INSERT INTO dti_user (username,password,email,fname,lname,gender,birthday,age,address) VALUES (?,?,?,?,?,?,?,?,?)");
            $insert->bind_param("sssssssis",$username,$hashed_password,$email,$first_name,$last_name,$gender,$birthday,$age,$address);

            if($insert->execute()){
                $_SESSION['user_id'] = $insert->insert_id;
                $_SESSION['username'] = $username;

                header("Location: dashboard.php");
                exit();
            } else {
                $register_error = "Registration failed.";
            }
            $insert->close();
        }
        $check->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NasugView DTI</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<style>
/* ======= FONTS ======= */
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap');

@font-face { font-family:'ITC Benguiat'; src:url('fonts/ITCBenguiat-Regular.woff2') format('woff2'); }
@font-face { font-family:'ITC Benguiat Bold Condensed'; src:url('fonts/ITCBenguiat-BoldCondensed.woff2') format('woff2'); }

/* ======= VARIABLES ======= */
:root{--primary-color:#000f2e; --secondary-color:#f9fff9; --star-color:#FFD700;}

/* ======= BASE ======= */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Montserrat',sans-serif;}
body{background:linear-gradient(to right,#FFFFF0,#f0e6d2);display:flex;justify-content:center;align-items:center;height:100vh;}
.container{width:720px;max-width:95%;min-height:520px;background:#fff;border-radius:30px;overflow:hidden;position:relative;box-shadow:0 20px 50px rgba(0,15,46,0.7),0 10px 30px rgba(0,15,46,0.5);}

/* ======= FORM AREAS ======= */
.form-container{position:absolute;top:0;height:100%;width:50%;display:flex;flex-direction:column;background:var(--secondary-color);transition:all .6s ease-in-out;}
.sign-in{left:0;z-index:2;}
.container.active .sign-in{transform:translateX(100%);}
.sign-up{left:0;opacity:0;z-index:1;}
.container.active .sign-up{transform:translateX(100%);opacity:1;z-index:5;}

/* ======= SCROLLABLE FORM ======= */
.form-scroll{flex:1;overflow-y:auto;padding:20px 40px;display:flex;flex-direction:column;gap:10px;}
.sign-up .form-scroll{height: calc(100% - 120px);overflow-y: auto;}
.form-scroll img, input, select, .password-wrapper {width:100%;max-width:320px;}
.form-actions{padding:10px 40px 20px;display:flex;flex-direction:column;align-items:center;gap:10px;background:var(--secondary-color);}

form img{width:250px;margin-bottom:15px;filter: drop-shadow(0 6px 6px rgba(0,26,71,0.5));transition: filter 0.3s ease;}
form img:hover{filter: drop-shadow(0 12px 12px rgba(0,26,71,0.7));}

input,select{background:#e8f5e9;border:none;padding:12px;font-size:14px;border-radius:8px;width:100%;max-width:320px;outline:none;}

.password-wrapper{position:relative;width:100%;max-width:320px;}
.password-wrapper input{padding-right:40px;}
.password-wrapper i{position:absolute;top:50%;right:12px;transform:translateY(-50%);cursor:pointer;color:#666;}

.options-row{display:flex;justify-content:space-between;width:100%;max-width:320px;font-size:11px;margin-top:5px;}

button{background:var(--primary-color);color:#fff;font-size:14px;padding:12px 50px;border:none;border-radius:6px;font-weight:600;cursor:pointer;width:100%;max-width:320px;}
button:hover{background:#001a47;}

.switch-text{font-size:14px;text-align:center;color:#0d6efd;cursor:pointer;}

.toggle-container{position:absolute;top:0;left:50%;width:50%;height:100%;overflow:hidden;border-radius:150px 0 0 100px;transition:all .6s ease-in-out;}
.container.active .toggle-container{transform:translateX(-100%);border-radius:0 150px 100px 0;}

.toggle{background:linear-gradient(135deg,#001a47,#2555b5);height:100%;display:flex;align-items:center;justify-content:center;}
.toggle-panel{font-family:'ITC Benguiat',serif;color:#fff;text-align:center;}
.phrase1{font-family:'ITC Benguiat Bold Condensed',serif;font-size:1.7rem;display:flex;gap:0.4rem;justify-content:center;}
.star{color:var(--star-color); font-size:0.6rem;}
.phrase2{font-size:1rem;margin-top:4px;opacity:0.9;}
</style>
</head>

<body>
<div class="container" id="container">

<!-- LOGIN -->
<div class="form-container sign-in">
<?php if($login_error) echo "<p style='color:red;margin-bottom:10px;'>$login_error</p>"; ?>
<form method="POST">
<div class="form-scroll">
<img src="assets/nasugviewlogoblue.png" alt="logo">
<input type="text" name="email" placeholder="Email or Username" value="<?= htmlspecialchars($email_value) ?>" required>
<div class="password-wrapper">
<input type="password" name="password" id="loginPassword" placeholder="Password" required>
<i class="fa-solid fa-eye-slash" id="toggleLoginPassword"></i>
</div>
<div class="options-row">
<label><input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>> Remember Me</label>
<a href="#">Forgot your password?</a>
</div>
</div>
<div class="form-actions">
<button type="submit" name="login">Sign In</button>
<p class="switch-text" id="registerBtn">Don't have an account? Sign up</p>
</div>
</form>
</div>

<!-- SIGNUP -->
<div class="form-container sign-up">
<?php if($register_error) echo "<p style='color:red;margin-bottom:10px;'>$register_error</p>"; ?>
<form method="POST">
<div class="form-scroll">
<img src="assets/nasugviewlogoblue.png" alt="logo">
<input type="text" name="username" placeholder="Username" required>
<input type="email" name="email" placeholder="Email" required>
<div class="password-wrapper">
<input type="password" name="password" id="registerPassword" placeholder="Password" required>
<i class="fa-solid fa-eye-slash" id="toggleRegisterPassword"></i>
</div>
<input type="text" name="first_name" placeholder="First Name" required>
<input type="text" name="last_name" placeholder="Last Name" required>
<select name="gender" required>
<option value="">Gender</option>
<option value="Male">Male</option>
<option value="Female">Female</option>
<option value="Other">Prefer not to Say</option>
</select>
<input type="date" name="birthday" required>
<input type="text" name="address" placeholder="Address" required>
</div>
<div class="form-actions">
<button type="submit" name="register">Sign Up</button>
<p class="switch-text" id="loginBtn">Already have an account? Sign in</p>
</div>
</form>
</div>

<!-- PANEL -->
<div class="toggle-container">
<div class="toggle">
<div class="toggle-panel">
<span class="phrase1">
Discover<span class="star">★</span>
Connect<span class="star">★</span>
Support
</span>
<span class="phrase2">Thrive with NasugView</span>
</div>
</div>
</div>
</div>

<script>
const container = document.getElementById("container");
document.getElementById("registerBtn").onclick = ()=> container.classList.add("active");
document.getElementById("loginBtn").onclick = ()=> container.classList.remove("active");

/* LOGIN PASSWORD TOGGLE */
const loginPass = document.getElementById("loginPassword");
const toggleLogin = document.getElementById("toggleLoginPassword");
toggleLogin.onclick = ()=>{
    if(loginPass.type==="password"){
        loginPass.type="text";
        toggleLogin.classList.replace("fa-eye-slash","fa-eye");
    }else{
        loginPass.type="password";
        toggleLogin.classList.replace("fa-eye","fa-eye-slash");
    }
};

/* REGISTER PASSWORD TOGGLE */
const regPass = document.getElementById("registerPassword");
const toggleReg = document.getElementById("toggleRegisterPassword");
toggleReg.onclick = ()=>{
    if(regPass.type==="password"){
        regPass.type="text";
        toggleReg.classList.replace("fa-eye-slash","fa-eye");
    }else{
        regPass.type="password";
        toggleReg.classList.replace("fa-eye","fa-eye-slash");
    }
};
</script>
</body>
</html>
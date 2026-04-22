<?php
session_start();

// Database config
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "nasugview2";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$login_error = '';
$email_value = '';
$password_value = '';
$remember = false;

// Check if cookies exist for "Remember Me"
if(isset($_COOKIE['remember_email']) && isset($_COOKIE['remember_password'])){
    $email_value = $_COOKIE['remember_email'];
    $password_value = $_COOKIE['remember_password'];
    $remember = true;
}

// LOGIN ONLY
if (isset($_POST['login'])) {
    $login_input = trim($_POST['email']);
    $password_input = trim($_POST['password']);
    $remember_me = isset($_POST['remember']);

    if (empty($login_input) || empty($password_input)) {
        $login_error = "Please provide both email/username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password FROM negosyo_center_users WHERE email=? OR username=?");
        $stmt->bind_param("ss", $login_input, $login_input);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();

            if ($password_input === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                // Set cookies if "Remember Me" is checked
                if($remember_me){
                    setcookie('remember_email', $login_input, time()+30*24*60*60, "/"); // 30 days
                    setcookie('remember_password', $password_input, time()+30*24*60*60, "/");
                } else {
                    // Clear cookies if unchecked
                    setcookie('remember_email', '', time()-3600, "/");
                    setcookie('remember_password', '', time()-3600, "/");
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

<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap');

@font-face {
    font-family: 'ITC Benguiat';
    src: url('fonts/ITCBenguiat-Regular.woff2') format('woff2');
}
@font-face {
    font-family: 'ITC Benguiat Bold Condensed';
    src: url('fonts/ITCBenguiat-BoldCondensed.woff2') format('woff2');
}

:root {
    --primary-color:#000f2e;
    --secondary-color:#f9fff9;
    --star-color:#FFD700;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Montserrat', sans-serif;
}

body{
    background: linear-gradient(to right, #FFFFF0, #f0e6d2);
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.container{
    width:720px;
    max-width:95%;
    min-height:500px;
    background:#fff;
    border-radius:30px;
    overflow:hidden;
    position:relative;
    box-shadow: 0 20px 50px rgba(0, 15, 46, 0.7), 0 10px 30px rgba(0, 15, 46, 0.5);
}

.form-container{
    position:absolute;
    top:0;
    left:0;
    width:50%;
    height:100%;
    display:flex;
    justify-content:center;
    align-items:center;
    background-color: var(--secondary-color);
}

form{
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:0 50px;
    width:100%;
}

form img {
    width: 250px;
    margin-bottom: 15px;
    filter: drop-shadow(0 4px 2px rgba(0, 26, 71, 0.5));
    transition: filter 0.3s ease;
}

form img:hover {
    filter: drop-shadow(0 12px 20px rgba(0, 26, 71, 0.7));
}

.input-group {
    position: relative;
    width: 100%;
    max-width: 320px;
    margin: 10px 0;
}

.input-group i {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 14px;
}

.input-group i.fa-envelope,
.input-group i.fa-lock {
    left: 12px;
}

.input-group i#togglePassword {
    right: 12px;
    cursor: pointer;
    transition: color 0.3s ease;
}

.input-group i#togglePassword:hover {
    color: #001a47;
}

.input-group input {
    background:#e8f5e9;
    border:none;
    padding:12px 12px 12px 35px;
    font-size:14px;
    border-radius:8px;
    width:100%;
    outline:none;
    color:#1b3c1b;
    box-shadow: 0 3px 3px rgba(0, 26, 71, 0.3);
    transition: box-shadow 0.3s ease;
}

.input-group.password input {
    padding-right: 40px;
}

.input-group input:focus {
    box-shadow: 0 4px 12px rgba(0, 26, 71, 0.5);
}

/* Remember + Forgot row */
.options-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    max-width: 320px;
    margin-top: 5px;
    font-size: 11px;
}

button{
    background: var(--primary-color);
    color:#fff;
    font-size:14px;
    padding:12px 50px;
    border:none;
    border-radius:6px;
    font-weight:600;
    text-transform:uppercase;
    cursor:pointer;
    margin-top:35px;
}

button:hover{
    background:#001a47;
}

a{
    color:#001a47;
    text-decoration:none;
}

a:hover{
    text-decoration:underline;
}

.toggle-container{
    position:absolute;
    top:0;
    left:50%;
    width:50%;
    height:100%;
    overflow:hidden;
    border-radius:150px 0 0 100px;
}

.toggle{
    background: linear-gradient(135deg, #001a47, #2555b5);
    height:100%;
    display:flex;
    justify-content:center;
    align-items:center;
    text-align:center;
}

.toggle-panel{
    font-family:'ITC Benguiat', serif;
    color:#fff;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    height:100%;
}

.toggle-panel .phrase1{
    font-family:'ITC Benguiat Bold Condensed', serif;
    font-weight:bold;
    font-size:1.7rem;
    display:flex;
    gap:0.4rem;
    justify-content:center;
    align-items:center;
}

.toggle-panel .phrase1 span.star{
    color: var(--star-color);
    font-size:0.5rem;
}

.toggle-panel .phrase2{
    font-size:1rem;
    margin-top:3px;
    opacity:0.9;
    text-align:center;
}
</style>
</head>

<body>

<div class="container">

    <!-- SIGN IN -->
    <div class="form-container">

        <?php if($login_error) echo "<p style='color:red; margin-bottom:10px;'>$login_error</p>"; ?>

        <form method="POST">
            <img src="assets/nasugviewlogoblue.png" alt="logo">

            <div class="input-group">
                <i class="fa-solid fa-envelope"></i>
                <input type="text" name="email" placeholder="Email or Username" value="<?php echo htmlspecialchars($email_value); ?>" required>
            </div>

            <div class="input-group password">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" placeholder="Password" id="password" value="<?php echo htmlspecialchars($password_value); ?>" required>
                <i class="fa-solid fa-eye-slash" id="togglePassword"></i>
            </div>

            <!-- Remember + Forgot row -->
            <div class="options-row">
                <label>
                    <input type="checkbox" name="remember" <?php echo $remember ? 'checked' : ''; ?>> Remember Me
                </label>
                <a href="#">Forgot your password?</a>
            </div>

            <button type="submit" name="login">Sign In</button>
        </form>
    </div>

    <!-- BLUE SIDE DESIGN -->
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
// Password toggle
const togglePassword = document.querySelector('#togglePassword');
const password = document.querySelector('#password');

togglePassword.addEventListener('click', () => {
    if(password.type === 'password'){
        password.type = 'text';
        togglePassword.classList.remove('fa-eye-slash');
        togglePassword.classList.add('fa-eye');
    } else {
        password.type = 'password';
        togglePassword.classList.remove('fa-eye');
        togglePassword.classList.add('fa-eye-slash');
    }
});
</script>

</body>
</html>

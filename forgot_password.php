<?php
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

require_once "config/db.php";

$autoload = __DIR__ . "/vendor/autoload.php";
$phpMailerBase = __DIR__ . "/PHPMailer/src";

if(file_exists($autoload)){
    require_once $autoload;
}elseif(file_exists($phpMailerBase . "/PHPMailer.php")){
    require_once $phpMailerBase . "/Exception.php";
    require_once $phpMailerBase . "/PHPMailer.php";
    require_once $phpMailerBase . "/SMTP.php";
}

$message = "";
$messageType = "info";
$step = $_SESSION["password_reset_step"] ?? "request";

function ensurePasswordResetTable(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS password_reset_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_type VARCHAR(30) NOT NULL,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_lookup (email, account_type, user_id),
            INDEX idx_password_reset_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function findAccountByEmailAndType(mysqli $conn, string $email, string $accountType): ?array {
    if($accountType === "consumer"){
        $stmt = $conn->prepare("
            SELECT c_id AS user_id, email, fname, lname
            FROM consumers
            WHERE email = ?
            LIMIT 1
        ");
    }elseif($accountType === "business_owner"){
        $stmt = $conn->prepare("
            SELECT b_id AS user_id, email, business_name
            FROM business_owner
            WHERE email = ?
            LIMIT 1
        ");
    }else{
        return null;
    }

    if(!$stmt){
        return null;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$account){
        return null;
    }

    if($accountType === "consumer"){
        $name = trim((string) ($account["fname"] ?? "") . " " . (string) ($account["lname"] ?? ""));
    }else{
        $name = trim((string) ($account["business_name"] ?? ""));
    }

    return [
        "account_type" => $accountType,
        "user_id" => (int) $account["user_id"],
        "email" => (string) $account["email"],
        "name" => $name !== "" ? $name : "NasugView user"
    ];
}

function sendPasswordResetCode(string $email, string $name, string $code): bool {
    if(!class_exists(PHPMailer::class)){
        return false;
    }

    $mailConfigPath = __DIR__ . "/config/mail.php";
    $mailConfig = file_exists($mailConfigPath) ? require $mailConfigPath : [];
    $mail = new PHPMailer(true);

    try{
        $mail->isSMTP();
        $mail->Host = getenv("SMTP_HOST") ?: ($mailConfig["host"] ?? "smtp.gmail.com");
        $mail->SMTPAuth = true;
        $mail->Username = getenv("SMTP_USERNAME") ?: ($mailConfig["username"] ?? "");
        $mail->Password = getenv("SMTP_PASSWORD") ?: ($mailConfig["password"] ?? "");
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv("SMTP_PORT") ?: ($mailConfig["port"] ?? 587));

        $fromEmail = getenv("SMTP_FROM_EMAIL") ?: ($mailConfig["from_email"] ?? $mail->Username);
        $fromName = getenv("SMTP_FROM_NAME") ?: ($mailConfig["from_name"] ?? "NasugView");

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = "NasugView password reset code";
        $mail->Body = "
            <p>Hello " . htmlspecialchars($name, ENT_QUOTES, "UTF-8") . ",</p>
            <p>Your NasugView password reset code is:</p>
            <h2 style='letter-spacing:4px;'>" . htmlspecialchars($code, ENT_QUOTES, "UTF-8") . "</h2>
            <p>This code will expire in 15 minutes.</p>
            <p>If you did not request this, you can ignore this email.</p>
        ";
        $mail->AltBody = "Your NasugView password reset code is {$code}. This code expires in 15 minutes.";

        return $mail->send();
    }catch(Exception $e){
        return false;
    }
}

function showMessage(string $text, string $type = "info"): void {
    global $message, $messageType;
    $message = $text;
    $messageType = $type;
}

function isStrongPassword(string $password): bool {
    return strlen($password) >= 8
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

ensurePasswordResetTable($conn);

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $action = $_POST["action"] ?? "";

    if($action === "send_code"){
        $email = trim((string) ($_POST["email"] ?? ""));
        $accountType = trim((string) ($_POST["account_type"] ?? ""));

        if(!in_array($accountType, ["consumer", "business_owner"], true)){
            showMessage("Choose the account type for this password reset.", "error");
            $step = "request";
        }elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            showMessage("Enter a valid email address.", "error");
            $step = "request";
        }else{
            $account = findAccountByEmailAndType($conn, $email, $accountType);
            $_SESSION["password_reset_id"] = 0;
            $_SESSION["password_reset_email"] = $email;
            $_SESSION["password_reset_account_type"] = $accountType;
            $_SESSION["password_reset_step"] = "verify";
            $_SESSION["password_reset_attempts"] = 0;
            unset($_SESSION["password_reset_verified"]);

            if($account){
                $code = (string) random_int(100000, 999999);
                $codeHash = password_hash($code, PASSWORD_DEFAULT);
                $expiresAt = date("Y-m-d H:i:s", time() + (15 * 60));

                $clearStmt = $conn->prepare("
                    UPDATE password_reset_codes
                    SET used_at = NOW()
                    WHERE email = ? AND account_type = ? AND user_id = ? AND used_at IS NULL
                ");
                $clearStmt->bind_param("ssi", $account["email"], $account["account_type"], $account["user_id"]);
                $clearStmt->execute();
                $clearStmt->close();

                $insertStmt = $conn->prepare("
                    INSERT INTO password_reset_codes (account_type, user_id, email, code_hash, expires_at)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertStmt->bind_param("sisss", $account["account_type"], $account["user_id"], $account["email"], $codeHash, $expiresAt);
                $insertStmt->execute();
                $resetId = $insertStmt->insert_id;
                $insertStmt->close();

                $_SESSION["password_reset_id"] = $resetId;
                $_SESSION["password_reset_email"] = $account["email"];
                $_SESSION["password_reset_account_type"] = $account["account_type"];
                $_SESSION["password_reset_step"] = "verify";

                sendPasswordResetCode($account["email"], $account["name"], $code);
            }

            $step = "verify";
            showMessage("If the email matches a registered " . ($accountType === "business_owner" ? "business owner" : "consumer") . " account, a 6-digit code will be sent. It expires in 15 minutes.", "success");
        }
    }

    if($action === "verify_code"){
        $resetId = (int) ($_SESSION["password_reset_id"] ?? 0);
        $code = trim((string) ($_POST["code"] ?? ""));
        $attempts = (int) ($_SESSION["password_reset_attempts"] ?? 0);

        $stmt = $conn->prepare("
            SELECT id, code_hash, expires_at, used_at
            FROM password_reset_codes
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $resetId);
        $stmt->execute();
        $reset = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$reset || $reset["used_at"] !== null || strtotime((string) $reset["expires_at"]) < time()){
            showMessage("That reset code has expired. Request a new code.", "error");
            $_SESSION["password_reset_step"] = "request";
            $step = "request";
        }elseif(!preg_match("/^\d{6}$/", $code) || !password_verify($code, (string) $reset["code_hash"])){
            $attempts++;
            $_SESSION["password_reset_attempts"] = $attempts;

            if($attempts >= 5){
                $usedStmt = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE id = ? LIMIT 1");
                $usedStmt->bind_param("i", $resetId);
                $usedStmt->execute();
                $usedStmt->close();

                $_SESSION["password_reset_step"] = "request";
                showMessage("Too many incorrect code attempts. Request a new code.", "error");
                $step = "request";
            }else{
                showMessage("The code you entered is incorrect.", "error");
                $step = "verify";
            }
        }else{
            $_SESSION["password_reset_verified"] = true;
            $_SESSION["password_reset_step"] = "reset";
            $_SESSION["password_reset_attempts"] = 0;
            $step = "reset";
            showMessage("Code verified. Create your new password.", "success");
        }
    }

    if($action === "reset_password"){
        $resetId = (int) ($_SESSION["password_reset_id"] ?? 0);
        $isVerified = !empty($_SESSION["password_reset_verified"]);
        $password = (string) ($_POST["password"] ?? "");
        $confirmPassword = (string) ($_POST["confirm_password"] ?? "");

        if(!$isVerified){
            showMessage("Verify your reset code first.", "error");
            $step = "verify";
        }elseif(!isStrongPassword($password)){
            showMessage("Password must be at least 8 characters and include uppercase, lowercase, number, and special character.", "error");
            $step = "reset";
        }elseif($password !== $confirmPassword){
            showMessage("Passwords do not match.", "error");
            $step = "reset";
        }else{
            $stmt = $conn->prepare("
                SELECT id, account_type, user_id, expires_at, used_at
                FROM password_reset_codes
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $resetId);
            $stmt->execute();
            $reset = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if(!$reset || $reset["used_at"] !== null || strtotime((string) $reset["expires_at"]) < time()){
                showMessage("That reset code has expired. Request a new code.", "error");
                $_SESSION["password_reset_step"] = "request";
                $step = "request";
            }else{
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $accountType = (string) $reset["account_type"];
                $userId = (int) $reset["user_id"];

                if($accountType === "consumer"){
                    $updateStmt = $conn->prepare("UPDATE consumers SET password = ? WHERE c_id = ? LIMIT 1");
                }else{
                    $updateStmt = $conn->prepare("UPDATE business_owner SET password = ? WHERE b_id = ? LIMIT 1");
                }

                $updateStmt->bind_param("si", $passwordHash, $userId);
                $updateStmt->execute();
                $updateStmt->close();

                $usedStmt = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE id = ? LIMIT 1");
                $usedStmt->bind_param("i", $resetId);
                $usedStmt->execute();
                $usedStmt->close();

                unset(
                    $_SESSION["password_reset_id"],
                    $_SESSION["password_reset_email"],
                    $_SESSION["password_reset_account_type"],
                    $_SESSION["password_reset_attempts"],
                    $_SESSION["password_reset_verified"],
                    $_SESSION["password_reset_step"]
                );

                showMessage("Your password has been updated. You can now log in.", "success");
                $step = "done";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box}
body{
margin:0;
min-height:100vh;
font-family:Arial,sans-serif;
background:#f4f7fb;
display:flex;
align-items:center;
justify-content:center;
padding:24px;
color:#0f172a;
}
.reset-card{
width:100%;
max-width:430px;
background:#fff;
border-radius:8px;
box-shadow:0 20px 50px rgba(15,23,42,.12);
padding:28px;
}
.reset-icon{
width:52px;
height:52px;
border-radius:50%;
background:#001a47;
color:#fff;
display:flex;
align-items:center;
justify-content:center;
font-size:22px;
margin-bottom:18px;
}
h1{
font-size:26px;
line-height:1.2;
margin:0 0 8px;
}
p{
margin:0 0 20px;
color:#64748b;
line-height:1.5;
}
.field{
margin-bottom:16px;
}
label{
display:block;
font-size:13px;
font-weight:700;
margin-bottom:7px;
}
input{
width:100%;
height:46px;
border:1px solid #cbd5e1;
border-radius:7px;
padding:0 13px;
font-size:15px;
outline:none;
}
select{
width:100%;
height:46px;
border:1px solid #cbd5e1;
border-radius:7px;
padding:0 13px;
font-size:15px;
outline:none;
background:#fff;
color:#0f172a;
}
input:focus{
border-color:#001a47;
box-shadow:0 0 0 3px rgba(0,26,71,.12);
}
select:focus{
border-color:#001a47;
box-shadow:0 0 0 3px rgba(0,26,71,.12);
}
.password-input-wrap{
position:relative;
}
.password-input-wrap input{
padding-right:46px;
}
.toggle-password{
position:absolute;
right:12px;
top:50%;
transform:translateY(-50%);
width:32px;
height:32px;
border:0;
background:transparent;
color:#001a47;
cursor:pointer;
display:flex;
align-items:center;
justify-content:center;
font-size:15px;
}
.password-help{
display:block;
margin-top:7px;
font-size:12px;
line-height:1.4;
color:#64748b;
}
.btn{
width:100%;
height:46px;
border:0;
border-radius:7px;
background:#001a47;
color:#fff;
font-size:15px;
font-weight:700;
cursor:pointer;
display:flex;
align-items:center;
justify-content:center;
gap:8px;
}
.message{
padding:12px 14px;
border-radius:7px;
font-size:14px;
line-height:1.4;
margin-bottom:16px;
}
.message.success{background:#ecfdf5;color:#047857}
.message.error{background:#fef2f2;color:#b91c1c}
.message.info{background:#eff6ff;color:#1d4ed8}
.back-link{
display:flex;
align-items:center;
justify-content:center;
gap:7px;
margin-top:18px;
font-size:14px;
font-weight:700;
color:#001a47;
text-decoration:none;
}
.code-input{
text-align:center;
font-size:22px;
font-weight:800;
letter-spacing:8px;
}
</style>
</head>
<body>
<main class="reset-card">
<div class="reset-icon"><i class="fa fa-lock"></i></div>
<h1>Forgot password</h1>

<?php if($step === "request"): ?>
<p>Choose your account type and enter the registered email. We will send a 6-digit reset code only to that email.</p>
<?php elseif($step === "verify"): ?>
<p>Enter the 6-digit code sent to <?php echo htmlspecialchars((string) ($_SESSION["password_reset_email"] ?? "your email")); ?>. The code expires in 15 minutes.</p>
<?php elseif($step === "reset"): ?>
<p>Create a new password for your account.</p>
<?php else: ?>
<p>Your password reset is complete.</p>
<?php endif; ?>

<?php if($message !== ""): ?>
<div class="message <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if($step === "request"): ?>
<form method="POST">
<input type="hidden" name="action" value="send_code">
<div class="field">
<label for="account_type">Account type</label>
<select id="account_type" name="account_type" required>
<option value="">Choose account type</option>
<option value="consumer">Consumer</option>
<option value="business_owner">Business Owner</option>
</select>
</div>
<div class="field">
<label for="email">Email address</label>
<input type="email" id="email" name="email" required autocomplete="email">
</div>
<button type="submit" class="btn"><i class="fa fa-paper-plane"></i> Send Code</button>
</form>
<?php elseif($step === "verify"): ?>
<form method="POST">
<input type="hidden" name="action" value="verify_code">
<div class="field">
<label for="code">Reset code</label>
<input type="text" id="code" name="code" class="code-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
</div>
<button type="submit" class="btn"><i class="fa fa-check"></i> Verify Code</button>
</form>
<form method="POST" style="margin-top:12px;">
<input type="hidden" name="action" value="send_code">
<input type="hidden" name="email" value="<?php echo htmlspecialchars((string) ($_SESSION["password_reset_email"] ?? "")); ?>">
<input type="hidden" name="account_type" value="<?php echo htmlspecialchars((string) ($_SESSION["password_reset_account_type"] ?? "")); ?>">
<button type="submit" class="btn" style="background:#475569;"><i class="fa fa-rotate-right"></i> Resend Code</button>
</form>
<?php elseif($step === "reset"): ?>
<form method="POST">
<input type="hidden" name="action" value="reset_password">
<div class="field">
<label for="password">New password</label>
<div class="password-input-wrap">
<input
type="password"
id="password"
name="password"
minlength="8"
pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
title="Use at least 8 characters with uppercase, lowercase, number, and special character."
required
autocomplete="new-password"
>
<button type="button" class="toggle-password" data-target="password" aria-label="Show new password">
<i class="fa fa-eye-slash"></i>
</button>
</div>
<small class="password-help">At least 8 characters with uppercase, lowercase, number, and special character.</small>
</div>
<div class="field">
<label for="confirm_password">Confirm password</label>
<div class="password-input-wrap">
<input
type="password"
id="confirm_password"
name="confirm_password"
minlength="8"
pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
title="Use at least 8 characters with uppercase, lowercase, number, and special character."
required
autocomplete="new-password"
>
<button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show confirm password">
<i class="fa fa-eye-slash"></i>
</button>
</div>
</div>
<button type="submit" class="btn"><i class="fa fa-key"></i> Update Password</button>
</form>
<?php else: ?>
<a href="login.php" class="btn" style="text-decoration:none;"><i class="fa fa-right-to-bracket"></i> Go to Login</a>
<?php endif; ?>

<a href="login.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to login</a>
</main>
<script>
document.querySelectorAll(".toggle-password").forEach(function(button){
    button.addEventListener("click", function(){
        const target = document.getElementById(this.dataset.target);
        const icon = this.querySelector("i");

        if(!target || !icon){
            return;
        }

        if(target.type === "password"){
            target.type = "text";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
            this.setAttribute("aria-label", "Hide password");
        }else{
            target.type = "password";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
            this.setAttribute("aria-label", "Show password");
        }
    });
});
</script>
</body>
</html>

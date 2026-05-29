<?php
session_start();
require_once "config/db.php";
require_once "config/notifications_helper.php";

function ensureBusinessPermitQrDataTable(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS business_permit_qr_data (
            qr_data_id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            permit_year VARCHAR(4) NOT NULL,
            permit_number VARCHAR(120) NOT NULL,
            permit_issued_on DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_owner_qr_data (owner_id),
            UNIQUE KEY uniq_permit_year_number (permit_year, permit_number),
            CONSTRAINT fk_business_permit_qr_data_owner
                FOREIGN KEY (owner_id) REFERENCES business_owner(b_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $index = $conn->query("
        SHOW INDEX FROM business_permit_qr_data
        WHERE Key_name = 'uniq_permit_year_number'
    ");

    if($index && $index->num_rows === 0){
        $conn->query("
            ALTER TABLE business_permit_qr_data
            ADD UNIQUE KEY uniq_permit_year_number (permit_year, permit_number)
        ");
    }

    $issuedOnColumn = $conn->query("
        SHOW COLUMNS FROM business_permit_qr_data
        LIKE 'permit_issued_on'
    ");

    if($issuedOnColumn && $issuedOnColumn->num_rows === 0){
        $conn->query("
            ALTER TABLE business_permit_qr_data
            ADD COLUMN permit_issued_on DATE NOT NULL AFTER permit_number
        ");
    }
}

function ensureBusinessPermitsTable(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS business_permits (
            permit_id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            permit_number VARCHAR(120) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_business_permits_owner (owner_id),
            CONSTRAINT fk_business_permits_owner
                FOREIGN KEY (owner_id) REFERENCES business_owner(b_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function oldInput(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function validatePermitQrData(string $permitYear, string $permitNumber, string $permitIssuedOn): array {
    if(!preg_match('/^20\d{2}$/', $permitYear)){
        return [false, "Business permit QR code must include a valid permit year."];
    }

    if(!preg_match('/^[A-Za-z0-9-]+$/', $permitNumber)){
        return [false, "Business permit QR code must include a valid permit number."];
    }

    $issuedOn = DateTime::createFromFormat('Y-m-d', $permitIssuedOn);
    $dateErrors = DateTime::getLastErrors();

    if(
        !$issuedOn ||
        ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    ){
        return [false, "Business permit QR code must include a valid issued-on date."];
    }

    $currentYear = (new DateTime())->format('Y');

    if($permitYear !== $currentYear || $issuedOn->format('Y') !== $currentYear){
        return [false, "This business permit is not valid for {$currentYear}. Please upload the current year's permit."];
    }

    return [true, null];
}

function validateBusinessPermitUpload(array $file): array {
    if(
        !isset($file['error'], $file['tmp_name'], $file['name']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ){
        return [false, "Business permit upload failed."];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    if(!in_array($extension, $allowedExtensions, true)){
        return [false, "Business permit must be a JPG, PNG, or WEBP image."];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if($finfo){
        finfo_close($finfo);
    }

    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if(!in_array($mimeType, $allowedMimeTypes, true)){
        return [false, "Only JPG, PNG, or WEBP images are allowed for the business permit."];
    }

    return [true, [
        'extension' => $extension,
        'mime_type' => $mimeType,
        'file_type' => 'image'
    ]];
}

function negosyoCenterPermitUploadDir(): string {
    $currentDir = __DIR__;
    $publicHtmlPos = stripos($currentDir, "public_html");

    if($publicHtmlPos !== false){
        $publicHtmlDir = substr($currentDir, 0, $publicHtmlPos + strlen("public_html"));
        return $publicHtmlDir . DIRECTORY_SEPARATOR . "negosyocenter" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "business_permits";
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . "negosyocenter" . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "business_permits";
}

ensureBusinessPermitQrDataTable($conn);
ensureBusinessPermitsTable($conn);
ensureNotificationStartColumns($conn);

$lines = $conn->query("
    SELECT line_id, line_name
    FROM business_lines
    ORDER BY line_name ASC
");

$cities = $conn->query("
    SELECT id, name
    FROM cities
    ORDER BY name ASC
");

$login_error = '';
$register_error = '';
$register_success = '';

$email_value = '';
$password_value = '';
$remember = false;

if(isset($_COOKIE['remember_email']) && isset($_COOKIE['remember_password'])){
    $email_value = $_COOKIE['remember_email'];
    $password_value = $_COOKIE['remember_password'];
    $remember = true;
}

if(isset($_POST['login'])){
    $login_input = trim($_POST['email']);
    $password_input = trim($_POST['password']);
    $remember_me = isset($_POST['remember']);

    if(empty($login_input) || empty($password_input)){
        $login_error = "Please provide email and password.";
    } else {
        $user = null;
        $account_type = "";

        $stmt = $conn->prepare("
            SELECT c_id AS id, username, password, email
            FROM consumers
            WHERE email = ? OR username = ?
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
                WHERE email = ? OR username = ?
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
                setcookie("remember_email", $login_input, time() + 60 * 60 * 24 * 30, "/");
                setcookie("remember_password", $password_input, time() + 60 * 60 * 24 * 30, "/");
            } else {
                setcookie("remember_email", "", time() - 3600, "/");
                setcookie("remember_password", "", time() - 3600, "/");
            }

            header("Location: home.php");
            exit();
        }

        $login_error = "Invalid email/username or password.";
    }
}

if(isset($_POST['register'])){
    $account_type  = trim($_POST['account_type']);
    $username      = trim($_POST['username']);
    $email         = trim($_POST['email']);
    $password      = trim($_POST['password']);
    $first_name    = trim($_POST['first_name']);
    $last_name     = trim($_POST['last_name']);
    $sex           = trim($_POST['gender']);
    $birthday      = trim($_POST['birthday']);
    $municipality  = isset($_POST['municipality']) ? trim($_POST['municipality']) : "";
    $address       = $municipality;
    $business_name = isset($_POST['business_name']) ? trim($_POST['business_name']) : "";
    $business_line = isset($_POST['business_line']) ? trim($_POST['business_line']) : "";
    $permit_year = isset($_POST['permit_year']) ? trim($_POST['permit_year']) : "";
    $permit_number = isset($_POST['permit_number']) ? trim($_POST['permit_number']) : "";
    $permit_issued_on = isset($_POST['permit_issued_on']) ? trim($_POST['permit_issued_on']) : "";
    $permitUploadMeta = null;

    if($account_type === "consumer"){
        $business_name = null;
        $business_line = null;
        $permit_year = null;
        $permit_number = null;
        $permit_issued_on = null;
    }

    $age = null;

    if(!empty($birthday)){
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
    }

    if($username !== '' || $email !== ''){
        $duplicateAccount = $conn->prepare("
            SELECT username, email
            FROM consumers
            WHERE username = ? OR email = ?
            UNION ALL
            SELECT username, email
            FROM business_owner
            WHERE username = ? OR email = ?
            LIMIT 1
        ");

        $duplicateAccount->bind_param("ssss", $username, $email, $username, $email);
        $duplicateAccount->execute();
        $duplicateAccountResult = $duplicateAccount->get_result();

        if($duplicateAccountResult->num_rows > 0){
            $existingAccount = $duplicateAccountResult->fetch_assoc();

            if(strcasecmp($existingAccount['username'], $username) === 0){
                $register_error = "Username is already taken.";
            } elseif(strcasecmp($existingAccount['email'], $email) === 0){
                $register_error = "Email is already registered.";
            }
        }

        $duplicateAccount->close();
    }

    if(
        $register_error === '' &&
        (
        empty($account_type) ||
        empty($username) ||
        empty($email) ||
        empty($password) ||
        empty($first_name) ||
        empty($last_name) ||
        empty($sex) ||
        empty($birthday) ||
        empty($municipality)
        )
    ){
        $register_error = "All fields required.";
    } elseif($account_type === "business_owner" && empty($business_name)){
        $register_error = "Store name is required for business owners.";
    } elseif($account_type === "business_owner" && empty($business_line)){
        $register_error = "Business line is required for business owners.";
    } elseif($account_type === "business_owner" && empty($_FILES['business_permit']['name'])){
        $register_error = "Business permit file is required for business owners.";
    } elseif(
        $account_type === "business_owner" &&
        (empty($permit_year) || empty($permit_number) || empty($permit_issued_on))
    ){
        $register_error = "Please upload a clear business permit image with a readable QR code.";
    } elseif($account_type === "business_owner"){
        [$isQrDataValid, $qrDataError] = validatePermitQrData($permit_year, $permit_number, $permit_issued_on);

        if(!$isQrDataValid){
            $register_error = $qrDataError;
        } else {
            $duplicatePermit = $conn->prepare("
                SELECT owner_id
                FROM business_permit_qr_data
                WHERE permit_year = ? AND permit_number = ?
                LIMIT 1
            ");
            $duplicatePermit->bind_param("ss", $permit_year, $permit_number);
            $duplicatePermit->execute();
            $duplicatePermitResult = $duplicatePermit->get_result();

            if($duplicatePermitResult->num_rows > 0){
                $register_error = "This business permit has already been used to register an account.";
            }

            $duplicatePermit->close();
        }

        if($register_error === ''){
            [$isPermitValid, $permitResult] = validateBusinessPermitUpload($_FILES['business_permit']);

            if(!$isPermitValid){
                $register_error = $permitResult;
            } else {
                $permitUploadMeta = $permitResult;
            }
        }
    }

    if($register_error === ''){
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $age = (int) $age;
        $storedPermitPath = null;
        $mirrorPermitPath = null;

        try {
            $conn->begin_transaction();

            if($account_type === "consumer"){
                $insert = $conn->prepare("
                    INSERT INTO consumers
                    (username, password, email, fname, lname, gender, birthday, age, notification_started_at, address)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");

                $insert->bind_param(
                    "sssssssis",
                    $username,
                    $hashed_password,
                    $email,
                    $first_name,
                    $last_name,
                    $sex,
                    $birthday,
                    $age,
                    $address
                );
            } else {
                $businessLineId = (int) $business_line;

                $insert = $conn->prepare("
                    INSERT INTO business_owner
                    (username, password, email, fname, lname, gender, birthday, age, notification_started_at, address, business_name, line_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
                ");

                $insert->bind_param(
                    "ssssssssssi",
                    $username,
                    $hashed_password,
                    $email,
                    $first_name,
                    $last_name,
                    $sex,
                    $birthday,
                    $age,
                    $address,
                    $business_name,
                    $businessLineId
                );
            }

            if(!$insert || !$insert->execute()){
                throw new RuntimeException("Unable to save the account.");
            }

            $newUserId = (int) $conn->insert_id;
            $insert->close();

            if($account_type === "business_owner" && $permitUploadMeta !== null){
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "business_permits";

                if(!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)){
                    throw new RuntimeException("Failed to create the business permit upload directory.");
                }

                $storedPermitName = "permit_" . $newUserId . "_" . time() . "." . $permitUploadMeta['extension'];
                $storedPermitPath = $uploadDir . DIRECTORY_SEPARATOR . $storedPermitName;

                if(!move_uploaded_file($_FILES['business_permit']['tmp_name'], $storedPermitPath)){
                    throw new RuntimeException("Failed to save the uploaded business permit.");
                }

                $mirrorPermitDir = negosyoCenterPermitUploadDir();

                if(!is_dir($mirrorPermitDir) && !mkdir($mirrorPermitDir, 0777, true) && !is_dir($mirrorPermitDir)){
                    throw new RuntimeException("Failed to create the Negosyo Center permit upload directory.");
                }

                $mirrorPermitPath = $mirrorPermitDir . DIRECTORY_SEPARATOR . $storedPermitName;

                if(!copy($storedPermitPath, $mirrorPermitPath)){
                    throw new RuntimeException("Failed to copy the uploaded business permit to the Negosyo Center folder.");
                }

                $storedPermitRelativePath = "uploads/business_permits/" . $storedPermitName;
                $permitFileInsert = $conn->prepare("
                    INSERT INTO business_permits
                    (owner_id, permit_number, file_name, file_path, original_file_name, mime_type)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $originalPermitName = basename((string) $_FILES['business_permit']['name']);
                $permitFileInsert->bind_param(
                    "isssss",
                    $newUserId,
                    $permit_number,
                    $storedPermitName,
                    $storedPermitRelativePath,
                    $originalPermitName,
                    $permitUploadMeta['mime_type']
                );

                if(!$permitFileInsert->execute()){
                    throw new RuntimeException("Failed to log the uploaded business permit.");
                }

                $permitFileInsert->close();

                $qrDataInsert = $conn->prepare("
                    INSERT INTO business_permit_qr_data
                    (owner_id, permit_year, permit_number, permit_issued_on)
                    VALUES (?, ?, ?, ?)
                ");

                $qrDataInsert->bind_param(
                    "isss",
                    $newUserId,
                    $permit_year,
                    $permit_number,
                    $permit_issued_on
                );

                if(!$qrDataInsert->execute()){
                    throw new RuntimeException("Failed to save the permit QR data.");
                }

                $qrDataInsert->close();
            }

            $conn->commit();

            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['account_type'] = $account_type;

            header("Location: home.php");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();

            if($storedPermitPath && file_exists($storedPermitPath)){
                unlink($storedPermitPath);
            }

            if($mirrorPermitPath && file_exists($mirrorPermitPath)){
                unlink($mirrorPermitPath);
            }

            $register_error = $e->getMessage();
        }
    }
}

$selectedAccountType = oldInput('account_type');
$selectedBusinessLine = oldInput('business_line');
$selectedGender = oldInput('gender');
$selectedMunicipality = oldInput('municipality');

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NasugView</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<?php require_once "config/theme.php"; render_theme_head(); ?>
<link rel="stylesheet" href="assets/css/login.css?v=15">
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="auth-shell<?php echo isset($_POST['register']) ? ' active' : ''; ?>" id="container">
    <aside class="auth-intro">
        <div class="intro-brand">
            <p class="intro-kicker">Discover local businesses</p>
            <h1>Welcome</h1>
            <p class="intro-copy">Sign in or create an account to explore, connect, and support businesses in Nasugbu.</p>
        </div>
    </aside>

    <main class="auth-panel">
        <div class="auth-tabs" role="tablist" aria-label="Account access">
            <button
                class="auth-tab login-tab"
                id="loginBtn"
                type="button"
                role="tab"
                aria-controls="loginFormPanel"
            >
                Login
            </button>
            <button
                class="auth-tab register-tab"
                id="registerBtn"
                type="button"
                role="tab"
                aria-controls="registerFormPanel"
            >
                Sign Up
            </button>
        </div>

        <div class="form-container sign-up" id="registerFormPanel" role="tabpanel">
        <?php if($register_error) echo "<p class='form-alert error'>" . htmlspecialchars($register_error) . "</p>"; ?>
        <?php if($register_success) echo "<p class='form-alert success'>" . htmlspecialchars($register_success) . "</p>"; ?>

        <form method="POST" class="signup-form" enctype="multipart/form-data">
            <div class="auth-avatar">
                <i class="fa-regular fa-user"></i>
            </div>
            <h2 class="form-title">Create Account</h2>

            <select name="account_type" id="account_type" class="textbox" required>
                <option value="" disabled <?php echo $selectedAccountType === '' ? 'selected' : ''; ?>>Select Account Type</option>
                <option value="consumer" <?php echo $selectedAccountType === 'consumer' ? 'selected' : ''; ?>>Consumer</option>
                <option value="business_owner" <?php echo $selectedAccountType === 'business_owner' ? 'selected' : ''; ?>>Business Owner</option>
            </select>

            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars(oldInput('username')); ?>" required>

            <div class="business-group business-hidden" id="business_line_group">
                <select name="business_line" id="business_line" class="textbox" disabled>
                    <option value="" disabled <?php echo $selectedBusinessLine === '' ? 'selected' : ''; ?>>Business Line</option>
                    <?php while($line = $lines->fetch_assoc()): ?>
                        <option value="<?php echo (int) $line['line_id']; ?>" <?php echo $selectedBusinessLine === (string) $line['line_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($line['line_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div id="permit_container" class="permit-wrapper business-hidden">
                <input type="hidden" name="permit_year" id="permit_year" value="<?php echo htmlspecialchars(oldInput('permit_year')); ?>">
                <input type="hidden" name="permit_number" id="permit_number" value="<?php echo htmlspecialchars(oldInput('permit_number')); ?>">
                <input type="hidden" name="permit_issued_on" id="permit_issued_on" value="<?php echo htmlspecialchars(oldInput('permit_issued_on')); ?>">

                <input
                    type="file"
                    name="business_permit"
                    id="business_permit"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    style="display:none;"
                    disabled
                >

                <div class="permit-actions">
                    <button type="button" class="permit-button" id="choose_permit_button">
                        <span id="permit_text">Upload Business Permit</span>
                        <i class="fa fa-upload"></i>
                    </button>
                    <button type="button" class="permit-button permit-camera-button" id="capture_permit_button">
                        <span>Take Photo</span>
                        <i class="fa fa-camera"></i>
                    </button>
                </div>

                <small class="permit-help">Upload an image or take a clear photo of the permit. The QR code and store name will be scanned automatically.</small>
                <small class="permit-status" id="permit_status"></small>
            </div>

            <div class="business-group business-hidden" id="business_name_group">
                <input type="text" name="business_name" id="business_name" placeholder="Store Name" class="textbox" value="<?php echo htmlspecialchars(oldInput('business_name')); ?>" disabled>
            </div>

            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars(oldInput('email')); ?>" required>

            <div class="password-field">
                <input type="password" name="password" placeholder="Password" required id="password" class="textbox password-input">
                <i class="fa fa-eye-slash" id="togglePassword"></i>
            </div>

            <input type="text" name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars(oldInput('first_name')); ?>" required>
            <input type="text" name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars(oldInput('last_name')); ?>" required>

            <select name="gender" required class="textbox">
                <option value="" <?php echo $selectedGender === '' ? 'selected' : ''; ?>>Sex</option>
                <option value="Male" <?php echo $selectedGender === 'Male' ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo $selectedGender === 'Female' ? 'selected' : ''; ?>>Female</option>
                <option value="Other" <?php echo $selectedGender === 'Other' ? 'selected' : ''; ?>>Prefer not to Say</option>
            </select>

            <select name="municipality" id="municipality" required class="textbox">
                <option value="" disabled <?php echo $selectedMunicipality === '' ? 'selected' : ''; ?>>Municipality</option>
                <?php if($cities): ?>
                    <?php while($city = $cities->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($city['name']); ?>" <?php echo $selectedMunicipality === (string) $city['name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city['name']); ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <input
                type="<?php echo oldInput('birthday') !== '' ? 'date' : 'text'; ?>"
                name="birthday"
                id="birthday"
                class="textbox"
                placeholder="Birthday"
                onfocus="this.type='date'"
                onblur="if(!this.value)this.type='text'"
                value="<?php echo htmlspecialchars(oldInput('birthday')); ?>"
                required
            >

            <input type="hidden" name="age" id="age">

            <button type="submit" name="register">Sign Up</button>
        </form>
    </div>

        <div class="form-container sign-in" id="loginFormPanel" role="tabpanel">
        <?php if($login_error) echo "<p class='form-alert error'>" . htmlspecialchars($login_error) . "</p>"; ?>

        <form method="POST">
            <div class="auth-avatar">
                <i class="fa-regular fa-user"></i>
            </div>
            <h2 class="form-title">Login</h2>

            <div class="input-group">
                <i class="fa-solid fa-envelope"></i>
                <input type="text" name="email" placeholder="Email or Username" value="<?php echo htmlspecialchars($email_value); ?>" required>
            </div>

            <div class="input-group password">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="loginPassword" placeholder="Password" value="<?php echo htmlspecialchars($password_value); ?>" required>
                <i class="fa-solid fa-eye-slash" id="toggleLoginPassword"></i>
            </div>

            <div class="options-row">
                <label>
                    <input type="checkbox" name="remember" <?php echo $remember ? 'checked' : ''; ?>>
                    Remember Me
                </label>
                <a href="#">Forgot password?</a>
            </div>

            <button type="submit" name="login">Login</button>
        </form>
    </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
const container = document.getElementById('container');
const accountType = document.getElementById('account_type');
const permitInput = document.getElementById('business_permit');
const choosePermitButton = document.getElementById('choose_permit_button');
const capturePermitButton = document.getElementById('capture_permit_button');
const businessNameInput = document.getElementById('business_name');
const businessLineInput = document.getElementById('business_line');
const permitYearInput = document.getElementById('permit_year');
const permitNumberInput = document.getElementById('permit_number');
const permitIssuedOnInput = document.getElementById('permit_issued_on');
const businessGroups = document.querySelectorAll('.business-group, #permit_container');
const permitText = document.getElementById('permit_text');
const permitStatus = document.getElementById('permit_status');
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');
const toggleLoginPassword = document.getElementById('toggleLoginPassword');
const loginPassword = document.getElementById('loginPassword');
const birthdayInput = document.getElementById('birthday');

if(togglePassword && passwordInput){
    togglePassword.addEventListener('click', function () {
        if(passwordInput.type === 'password'){
            passwordInput.type = 'text';
            this.classList.remove('fa-eye-slash');
            this.classList.add('fa-eye');
        } else {
            passwordInput.type = 'password';
            this.classList.remove('fa-eye');
            this.classList.add('fa-eye-slash');
        }
    });
}

if(toggleLoginPassword && loginPassword){
    toggleLoginPassword.addEventListener('click', function(){
        if(loginPassword.type === 'password'){
            loginPassword.type = 'text';
            this.classList.replace('fa-eye-slash', 'fa-eye');
        } else {
            loginPassword.type = 'password';
            this.classList.replace('fa-eye', 'fa-eye-slash');
        }
    });
}

const registerBtn = document.getElementById('registerBtn');
const loginBtn = document.getElementById('loginBtn');
const registerPanel = document.getElementById('registerFormPanel');
const loginPanel = document.getElementById('loginFormPanel');

function setAuthMode(mode){
    const isRegister = mode === 'register';

    container.classList.toggle('active', isRegister);
    registerBtn.setAttribute('aria-selected', String(isRegister));
    loginBtn.setAttribute('aria-selected', String(!isRegister));
    registerPanel.hidden = !isRegister;
    loginPanel.hidden = isRegister;
}

registerBtn.addEventListener('click', () => setAuthMode('register'));
loginBtn.addEventListener('click', () => setAuthMode('login'));
setAuthMode(container.classList.contains('active') ? 'register' : 'login');

birthdayInput.addEventListener('keydown', function(e){
    e.preventDefault();
});

birthdayInput.addEventListener('paste', function(e){
    e.preventDefault();
});

const birthdayWrapper = document.createElement('div');
birthdayWrapper.className = 'birthday-wrapper';
birthdayInput.parentNode.insertBefore(birthdayWrapper, birthdayInput);
birthdayWrapper.appendChild(birthdayInput);

const birthdayOverlay = document.createElement('div');
birthdayOverlay.className = 'birthday-overlay';
birthdayWrapper.appendChild(birthdayOverlay);

function updateBirthdayOverlay(){
    if(!birthdayInput.value){
        birthdayOverlay.textContent = '';
        return;
    }

    const birth = new Date(birthdayInput.value);
    const today = new Date();

    let age = today.getFullYear() - birth.getFullYear();
    const monthDiff = today.getMonth() - birth.getMonth();

    if(monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())){
        age--;
    }

    const month = String(birth.getMonth() + 1).padStart(2, '0');
    const day = String(birth.getDate()).padStart(2, '0');
    const year = birth.getFullYear();

    birthdayOverlay.textContent = `${month}/${day}/${year} (${age} years old)`;
}

birthdayInput.addEventListener('change', updateBirthdayOverlay);

function toggleBusinessFields(isBusinessOwner){
    businessGroups.forEach(group => {
        group.classList.toggle('business-visible', isBusinessOwner);
        group.classList.toggle('business-hidden', !isBusinessOwner);
    });

    businessNameInput.disabled = !isBusinessOwner;
    businessLineInput.disabled = !isBusinessOwner;
    permitInput.disabled = !isBusinessOwner;

    if(!isBusinessOwner){
        businessNameInput.value = '';
        businessLineInput.selectedIndex = 0;
        permitInput.value = '';
        permitInput.removeAttribute('capture');
        clearPermitQrData();
        permitText.textContent = 'Upload Business Permit';
    }

}

accountType.addEventListener('change', function(){
    toggleBusinessFields(this.value === 'business_owner');
});

function clearPermitQrData(){
    permitYearInput.value = '';
    permitNumberInput.value = '';
    permitIssuedOnInput.value = '';

    if(permitStatus){
        permitStatus.textContent = '';
        permitStatus.classList.remove('error', 'success');
    }
}

function setPermitStatus(message, type){
    if(!permitStatus){
        return;
    }

    permitStatus.textContent = message;
    permitStatus.classList.toggle('error', type === 'error');
    permitStatus.classList.toggle('success', type === 'success');
}

function splitQrFields(text){
    return text
        .split(/[\n\r|;\t]+/)
        .map(field => field.trim())
        .filter(Boolean);
}

function parseIssuedDate(value){
    const cleaned = value.trim();
    let match = cleaned.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);

    if(match){
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    }

    match = cleaned.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);

    if(match){
        return new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
    }

    const parsed = new Date(cleaned);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function toIsoDate(date){
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function normalizePermitText(text){
    return text
        .replace(/[“”]/g, '"')
        .replace(/[’]/g, "'")
        .replace(/\s+/g, ' ')
        .trim();
}

function cleanExtractedStoreName(storeName){
    return storeName
        .replace(/\b(?:OF|TO\s+ENGAGE|ENGAGE)\b.*$/i, '')
        .replace(/^[\s:;,.|-]+|[\s:;,.|-]+$/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function extractStoreNameFromPermitText(text){
    const lines = text
        .split(/[\n\r]+/)
        .map(line => normalizePermitText(line))
        .filter(Boolean);

    const labeledNamePatterns = [
        /\b(?:STORE|BUSINESS|TRADE)\s+NAME\b\s*[:\-]?\s*(.+)$/i,
        /\bNAME\s+OF\s+(?:STORE|BUSINESS|ESTABLISHMENT)\b\s*[:\-]?\s*(.+)$/i
    ];

    for(const line of lines){
        for(const pattern of labeledNamePatterns){
            const match = line.match(pattern);

            if(match && cleanExtractedStoreName(match[1])){
                return cleanExtractedStoreName(match[1]);
            }
        }
    }

    const normalized = normalizePermitText(text);
    const grantPatterns = [
        /\bPERMIT\s+(?:IS\s+)?HERE(?:BY)?\s+GRANTED\s+TO\s+(.+?)(?:\s+OF\b|\s+TO\s+ENGAGE\b|[.])/i,
        /\b(?:IS\s+)?HERE(?:BY)?\s+GRANTED\s+TO\s+(.+?)(?:\s+OF\b|\s+TO\s+ENGAGE\b|[.])/i,
        /\bGRANTED\s+TO\s+(.+?)(?:\s+OF\b|\s+TO\s+ENGAGE\b|[.])/i
    ];

    for(const pattern of grantPatterns){
        const match = normalized.match(pattern);

        if(match && cleanExtractedStoreName(match[1])){
            return cleanExtractedStoreName(match[1]);
        }
    }

    return '';
}

function createPermitOcrImage(file){
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = () => {
            const image = new Image();

            image.onload = () => {
                const maxWidth = 1800;
                const scale = Math.min(1, maxWidth / image.naturalWidth);
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d', { willReadFrequently: true });

                canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                context.drawImage(image, 0, 0, canvas.width, canvas.height);

                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const data = imageData.data;

                for(let i = 0; i < data.length; i += 4){
                    const gray = (data[i] * 0.299) + (data[i + 1] * 0.587) + (data[i + 2] * 0.114);
                    const contrasted = Math.max(0, Math.min(255, (gray - 128) * 1.35 + 128));

                    data[i] = contrasted;
                    data[i + 1] = contrasted;
                    data[i + 2] = contrasted;
                }

                context.putImageData(imageData, 0, 0);
                resolve(canvas.toDataURL('image/jpeg', 0.92));
            };

            image.onerror = () => reject(new Error('Unable to read this permit image.'));
            image.src = reader.result;
        };

        reader.onerror = () => reject(new Error('Unable to read this permit image.'));
        reader.readAsDataURL(file);
    });
}

async function extractStoreNameFromPermitImage(file){
    if(!window.Tesseract || typeof window.Tesseract.recognize !== 'function'){
        throw new Error('Store name scanner failed to load. You can still type the store name manually.');
    }

    const ocrImage = await createPermitOcrImage(file);
    const result = await window.Tesseract.recognize(ocrImage, 'eng');
    const storeName = extractStoreNameFromPermitText(result?.data?.text || '');

    if(!storeName){
        throw new Error('Store name was not detected. You can type it manually.');
    }

    return storeName;
}

function extractPermitQrData(qrText){
    const fields = splitQrFields(qrText);

    if(fields.length < 4){
        throw new Error('QR code format is incomplete.');
    }

    const yearMatch = fields[0].match(/\b(20\d{2})\b/);
    const permitYear = yearMatch ? yearMatch[1] : '';
    const permitNumber = (fields[2] || '').replace(/[^A-Za-z0-9-]/g, '');
    const issuedDate = parseIssuedDate(fields[fields.length - 1]);
    const currentYear = String(new Date().getFullYear());

    if(!permitYear){
        throw new Error('QR code year was not found.');
    }

    if(!permitNumber){
        throw new Error('QR code permit number was not found.');
    }

    if(!issuedDate){
        throw new Error('QR code issued-on date was not found.');
    }

    if(permitYear !== currentYear || String(issuedDate.getFullYear()) !== currentYear){
        throw new Error(`This business permit is not valid for ${currentYear}. Please upload the current year's permit.`);
    }

    return {
        permitYear,
        permitNumber,
        permitIssuedOn: toIsoDate(issuedDate)
    };
}

function scanQrFromImage(file){
    return new Promise((resolve, reject) => {
        if(typeof jsQR !== 'function'){
            reject(new Error('QR scanner failed to load. Please check your internet connection and try again.'));
            return;
        }

        const reader = new FileReader();

        reader.onload = () => {
            const image = new Image();

            image.onload = () => {
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d', { willReadFrequently: true });

                canvas.width = image.naturalWidth;
                canvas.height = image.naturalHeight;
                context.drawImage(image, 0, 0);

                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const qr = jsQR(imageData.data, imageData.width, imageData.height);

                if(!qr || !qr.data){
                    reject(new Error('No readable QR code found. Please upload a clearer permit image.'));
                    return;
                }

                resolve(qr.data);
            };

            image.onerror = () => reject(new Error('Unable to read this permit image.'));
            image.src = reader.result;
        };

        reader.onerror = () => reject(new Error('Unable to read this permit image.'));
        reader.readAsDataURL(file);
    });
}

function openPermitPicker(useCamera){
    if(useCamera){
        permitInput.setAttribute('capture', 'environment');
    } else {
        permitInput.removeAttribute('capture');
    }

    permitInput.click();
}

choosePermitButton.addEventListener('click', () => openPermitPicker(false));
capturePermitButton.addEventListener('click', () => openPermitPicker(true));

permitInput.addEventListener('change', async function(){
    clearPermitQrData();
    permitText.textContent = this.files.length > 0 ? this.files[0].name : 'Upload Business Permit';

    if(this.files.length === 0){
        return;
    }

    const file = this.files[0];

    if(!file.type.startsWith('image/')){
        this.value = '';
        permitText.textContent = 'Upload Business Permit';
        setPermitStatus('Please upload an image file so the QR code can be scanned.', 'error');
        return;
    }

    setPermitStatus('Scanning permit...', '');

    try {
        const [qrText, detectedStoreName] = await Promise.all([
            scanQrFromImage(file),
            extractStoreNameFromPermitImage(file).catch(() => '')
        ]);
        const qrData = extractPermitQrData(qrText);

        permitYearInput.value = qrData.permitYear;
        permitNumberInput.value = qrData.permitNumber;
        permitIssuedOnInput.value = qrData.permitIssuedOn;

        if(detectedStoreName){
            businessNameInput.value = detectedStoreName;
        }

        setPermitStatus(
            `Permit ${qrData.permitNumber} (${qrData.permitYear}) issued on ${qrData.permitIssuedOn}.`,
            'success'
        );
    } catch (error) {
        this.value = '';
        permitText.textContent = 'Upload Business Permit';
        clearPermitQrData();
        setPermitStatus(error.message, 'error');
    }
});

toggleBusinessFields(accountType.value === 'business_owner');
updateBirthdayOverlay();
</script>
</body>
</html>

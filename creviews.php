<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/cart_count.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$b_id = $_SESSION['user_id'];

/* ===== FILTER ===== */
$star_filter = isset($_GET['stars']) ? intval($_GET['stars']) : 0;

/* ===== GET BUSINESS NAME ===== */
$biz_stmt = $conn->prepare("
    SELECT business_name
    FROM business_owner
    WHERE b_id = ?
");

if(!$biz_stmt){
    die("Business query error: " . $conn->error);
}

$biz_stmt->bind_param("i", $b_id);
$biz_stmt->execute();
$business = $biz_stmt->get_result()->fetch_assoc();

/* ===== TOGGLE HIDE ===== */
if(isset($_POST['toggle_hidden'])){
    $review_id = intval($_POST['review_id']);

    $toggle = $conn->prepare("
        UPDATE reviews 
        SET is_hidden = IF(is_hidden=1,0,1)
        WHERE id = ? AND business_id = ?
    ");

    if(!$toggle){
        die("Prepare failed: " . $conn->error);
    }

    $toggle->bind_param("ii", $review_id, $b_id);

    if(!$toggle->execute()){
        die("Execute failed: " . $toggle->error);
    }

    if($toggle->affected_rows === 0){
        die("No rows updated. Check business_id match.");
    }

    header("Location: creviews.php");
    exit;
}

/* ===== LOAD REVIEWS ===== */
if($star_filter > 0){

    $sql = "
        SELECT r.id, r.experience_rating, r.comment, r.images,
               r.is_anonymous, r.created_at, r.is_hidden,
               c.fname, c.lname
        FROM reviews r
        LEFT JOIN consumers c ON r.user_id = c.c_id
        WHERE r.business_id = ? AND r.experience_rating = ?
        ORDER BY r.created_at DESC
    ";

    $reviews_stmt = $conn->prepare($sql);

    if(!$reviews_stmt){
        die("Review query error: " . $conn->error);
    }

    $reviews_stmt->bind_param("ii", $b_id, $star_filter);

} else {

    $sql = "
        SELECT r.id, r.experience_rating, r.comment, r.images,
               r.is_anonymous, r.created_at, r.is_hidden,
               c.fname, c.lname
        FROM reviews r
        LEFT JOIN consumers c ON r.user_id = c.c_id
        WHERE r.business_id = ?
        ORDER BY r.created_at DESC
    ";

    $reviews_stmt = $conn->prepare($sql);

    if(!$reviews_stmt){
        die("Review query error: " . $conn->error);
    }

    $reviews_stmt->bind_param("i", $b_id);
}

$reviews_stmt->execute();
$reviews = $reviews_stmt->get_result();

function maskName($name){
    $length = strlen($name);

    if($length <= 2){
        return $name;
    }

    return substr($name, 0, 1) .
           str_repeat('*', $length - 2) .
           substr($name, -1);
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Consumer Reviews</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
body{
    font-family: Arial, sans-serif;
    margin:0;
    background:#f4f6fb;
}

.header{
    background:#ffff;
    padding:15px 25px;
    display:flex;
    align-items:center;
}
.header img{
    height:40px;
}

.container{
    max-width:1100px;
    margin:auto;
    padding:25px 20px 120px;
}

.filter-bar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}
.filter-bar h2{
    margin:0;
    color:#001a47;
}
.filter-select{
    padding:8px 12px;
    border-radius:8px;
    border:1px solid #001a47;
    font-weight:600;
    color:#001a47;
}

.review-card{
    background:#fff;
    border-radius:14px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:0 4px 12px rgba(0,0,0,0.06);
    position:relative;
}

.review-top{
    display:flex;
    justify-content:space-between;
    margin-bottom:10px;
}

.review-user{
    font-weight:600;
    color:#001a47;
}

.review-date{
    font-size:13px;
    color:#777;
}

.stars{
    color:#f5b301;
    margin:6px 0;
}

.review-comment{
    margin-top:8px;
    line-height:1.5;
    color:#333;
}

.review-img{
    width:100%;
    max-width:280px;
    margin-top:12px;
    border-radius:10px;
}

.actions{
    margin-top:15px;
}

.hide-btn{
    background:#001a47;
    color:#fff;
    border:none;
    padding:8px 16px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.hidden-badge{
    background:#e74c3c;
    color:#fff;
    padding:4px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
}

.review-meta{
    display:flex;
    align-items:center;
    gap:10px;
}

.no-review{
    text-align:center;
    padding:40px;
    color:#777;
    font-weight:500;
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="header">
    <img src="assets/images/logo.png">
</div>

<div class="container">

<div class="filter-bar">
    <form method="GET">
        <select name="stars" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Stars</option>
            <option value="5" <?= $star_filter==5?'selected':'' ?>>5 Stars</option>
            <option value="4" <?= $star_filter==4?'selected':'' ?>>4 Stars</option>
            <option value="3" <?= $star_filter==3?'selected':'' ?>>3 Stars</option>
            <option value="2" <?= $star_filter==2?'selected':'' ?>>2 Stars</option>
            <option value="1" <?= $star_filter==1?'selected':'' ?>>1 Star</option>
        </select>
    </form>
</div>

<?php if($reviews->num_rows === 0): ?>
    <div class="no-review">No reviews found.</div>
<?php endif; ?>

<?php while($row = $reviews->fetch_assoc()): ?>
<div class="review-card">

    <div class="review-top">
    <div class="review-user">
<?php
$fname = $row['fname'] ?? '';
$lname = $row['lname'] ?? '';

if($row['is_anonymous'] == 1){

    $maskedFname = maskName($fname);
    $maskedLname = maskName($lname);

    echo htmlspecialchars(trim($maskedFname . ' ' . $maskedLname));

} else {

    echo htmlspecialchars(trim($fname . ' ' . $lname));

}
?>
</div>

    <div class="review-meta">
        <div class="review-date">
            <?= date("F d, Y", strtotime($row['created_at'])); ?>
        </div>

        <?php if($row['is_hidden'] == 1): ?>
            <div class="hidden-badge">Hidden</div>
        <?php endif; ?>
    </div>
</div>

    <div class="stars">
        <?php
        $rating = intval($row['experience_rating']);
        for($i=1;$i<=5;$i++){
            echo $i <= $rating 
                ? "<i class='fa fa-star'></i>" 
                : "<i class='fa-regular fa-star'></i>";
        }
        ?>
    </div>

    <div class="review-comment">
        <?= nl2br(htmlspecialchars($row['comment'])); ?>
    </div>

    <?php if(!empty($row['images'])): ?>
        <img src="<?= htmlspecialchars($row['images']); ?>" class="review-img">
    <?php endif; ?>

    <div class="actions">
        <form method="POST">
            <input type="hidden" name="review_id" value="<?= $row['id']; ?>">
            <button type="submit" name="toggle_hidden" class="hide-btn">
                <?= $row['is_hidden'] ? "Unhide" : "Hide"; ?>
            </button>
        </form>
    </div>

</div>
<?php endwhile; ?>

</div>

<?php include 'bottom_nav.php'; ?>

</body>
</html>

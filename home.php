<?php
require_once "config/session.php";
require_once "config/db.php";

function ensureBusinessLocationColumns(mysqli $conn): void {
    $requiredColumns = [
        "latitude" => "ALTER TABLE business_owner ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address",
        "longitude" => "ALTER TABLE business_owner ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude"
    ];

    foreach($requiredColumns as $column => $sql){
        $check = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'business_owner'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if(!$check){
            continue;
        }

        $check->bind_param("s", $column);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if(!$exists){
            $conn->query($sql);
        }
    }
}

ensureBusinessLocationColumns($conn);

function renderReviewComments(mysqli $conn, int $reviewId): string {
ob_start();

$stmt = $conn->prepare("
SELECT 
rr.comment,
rr.account_type,
c.fname,
c.lname,
bo.business_name
FROM review_reacts rr
LEFT JOIN consumers c
ON rr.user_id = c.c_id AND rr.account_type='consumer'
LEFT JOIN business_owner bo
ON rr.user_id = bo.b_id AND rr.account_type='business_owner'
WHERE rr.review_id=? AND rr.type='comment'
ORDER BY rr.created_at ASC
");

$stmt->bind_param("i",$reviewId);
$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows === 0){
?>
<div class="comment comment-empty">No comments yet.<br>Be the first to share your thoughts!</div>
<?php
}

while($c = $res->fetch_assoc()){
?>
<div class="comment">
<strong>
<?php
if($c['account_type'] == "consumer"){
echo htmlspecialchars(trim(($c['fname'] ?? "")." ".($c['lname'] ?? "")));
}else{
echo htmlspecialchars($c['business_name'] ?? "");
?>
 <span style="color:#ff9800;font-size:12px;">&#10004; Business</span>
<?php
}
?>
</strong>
<?php echo htmlspecialchars($c['comment']); ?>
</div>
<?php
}

$stmt->close();

return ob_get_clean();
}

$cartCount = 0;

if(isset($_SESSION['user_id'])){

    $user_id = $_SESSION['user_id'];
    $account_type = $_SESSION['account_type'];

    $countStmt = $conn->prepare("
        SELECT COUNT(*) as total_products
        FROM cart
        WHERE consumer_id=? 
        AND account_type=?
    ");

    $countStmt->bind_param("is",$user_id,$account_type);
    $countStmt->execute();
    $result = $countStmt->get_result()->fetch_assoc();

    if($result){
        $cartCount = $result['total_products'];
    }

    $countStmt->close();
}

/* ================= HEART REACTION ================= */
if(isset($_POST['ajax_heart'])){

if (ob_get_length()) ob_clean();
header("Content-Type: application/json");

/* DETECT USER */

$user_id = intval($_SESSION['user_id']);
$account_type = $_SESSION['account_type'];

$review_id = intval($_POST['review_id']);

/* CHECK IF HEART EXISTS */

$check = $conn->prepare("
SELECT id
FROM review_reacts
WHERE review_id=? 
AND user_id=? 
AND account_type=? 
AND type='heart'
LIMIT 1
");

$check->bind_param("iis",$review_id,$user_id,$account_type);
$check->execute();
$res = $check->get_result();

if($res->num_rows > 0){

/* REMOVE HEART */

$stmt = $conn->prepare("
DELETE FROM review_reacts
WHERE review_id=? 
AND user_id=? 
AND account_type=? 
AND type='heart'
");
$stmt->bind_param("iis",$review_id,$user_id,$account_type);
$stmt->execute();

$liked = false;

}else{

/* ADD HEART */

$stmt = $conn->prepare("
INSERT INTO review_reacts (review_id,user_id,account_type,type)
VALUES (?,?,?,'heart')
");

$stmt->bind_param("iis",$review_id,$user_id,$account_type);

if(!$stmt->execute()){
    echo json_encode([
        "error"=>$stmt->error
    ]);
    exit;
}

$liked = true;

}

/* COUNT HEARTS */

$countStmt = $conn->prepare("
SELECT COUNT(*) as total
FROM review_reacts
WHERE review_id=? AND type='heart'
");

$countStmt->bind_param("i",$review_id);
$countStmt->execute();
$count = $countStmt->get_result()->fetch_assoc();

echo json_encode([
"total"=>intval($count['total']),
"liked"=>$liked
]);

exit;
}


/* ================= COMMENT ================= */

if(isset($_POST['submit_comment'])){

$review_id = intval($_POST['review_id']);
$comment = trim($_POST['comment']);

if(isset($_SESSION['user_id']) && isset($_SESSION['account_type'])){

$user_id = $_SESSION['user_id'];
$account_type = $_SESSION['account_type'];

}else{

header("Location: login.php");
exit;

}

if($comment != ""){

$stmt = $conn->prepare("
INSERT INTO review_reacts(review_id,user_id,account_type,type,comment)
VALUES(?,?,?,'comment',?)
");

$stmt->bind_param("iiss",$review_id,$user_id,$account_type,$comment);
$stmt->execute();

}

header("Location: ".$_SERVER['REQUEST_URI']);
exit;
}

if(isset($_POST['ajax_load_comments'])){
if (ob_get_length()) ob_clean();

$review_id = intval($_POST['review_id'] ?? 0);
echo renderReviewComments($conn, $review_id);
exit;
}

if(isset($_POST['ajax_submit_comment'])){
if (ob_get_length()) ob_clean();
header("Content-Type: application/json");

if(!isset($_SESSION['user_id']) || !isset($_SESSION['account_type'])){
    http_response_code(401);
    echo json_encode([
        "success" => false
    ]);
    exit;
}

$review_id = intval($_POST['review_id'] ?? 0);
$comment = trim($_POST['comment'] ?? "");

if($review_id <= 0 || $comment === ""){
    http_response_code(422);
    echo json_encode([
        "success" => false
    ]);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$account_type = $_SESSION['account_type'];

$stmt = $conn->prepare("
INSERT INTO review_reacts(review_id,user_id,account_type,type,comment)
VALUES(?,?,?,'comment',?)
");
$stmt->bind_param("iiss",$review_id,$user_id,$account_type,$comment);
$stmt->execute();
$stmt->close();

$countStmt = $conn->prepare("
SELECT COUNT(*) as total
FROM review_reacts
WHERE review_id=? AND type='comment'
");
$countStmt->bind_param("i",$review_id);
$countStmt->execute();
$count = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

echo json_encode([
    "success" => true,
    "html" => renderReviewComments($conn, $review_id),
    "total" => intval($count['total'] ?? 0)
]);
exit;
}

/* ================= TOP RATED BUSINESSES ================= */

$topRatedStmt = $conn->prepare("
    SELECT
        b.b_id,
        b.business_name,
        b.address,
        b.business_photo,
        ROUND(AVG(r.experience_rating),1) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM business_owner b
    LEFT JOIN reviews r
        ON r.business_id = b.b_id
    GROUP BY b.b_id, b.business_name, b.address, b.business_photo
    HAVING COUNT(r.id) > 0
        AND AVG(r.experience_rating) >= 3
    ORDER BY avg_rating DESC, b.b_id DESC
    LIMIT 8
");
$topRatedStmt->execute();
$topRated = $topRatedStmt->get_result();

/* ================= FEATURED BUSINESSES ================= */

$featuredStmt = $conn->prepare("
    SELECT
        b.b_id,
        b.business_name,
        b.address,
        b.business_photo,
        ROUND(AVG(r.experience_rating),1) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM business_owner b
    LEFT JOIN reviews r
        ON r.business_id = b.b_id
    GROUP BY b.b_id, b.business_name, b.address, b.business_photo
    HAVING COUNT(r.id) = 0
        OR AVG(r.experience_rating) <= 2
    ORDER BY
        CASE WHEN COUNT(r.id) = 0 THEN 0 ELSE 1 END ASC,
        avg_rating ASC,
        b.b_id DESC
    LIMIT 8
");
$featuredStmt->execute();
$featured = $featuredStmt->get_result();

$promoCodes = [
    [
        "code" => "NASUG10",
        "title" => "10% off local finds",
        "description" => "Use on eligible shops and services.",
        "meta" => "Min. spend PHP 299",
        "icon" => "fa-ticket"
    ],
    [
        "code" => "VIEW50",
        "title" => "PHP 50 welcome deal",
        "description" => "Save on your next booking or order.",
        "meta" => "New users",
        "icon" => "fa-gift"
    ],
    [
        "code" => "TOPRATED",
        "title" => "Rated picks reward",
        "description" => "Try trusted businesses with a bonus.",
        "meta" => "Top rated partners",
        "icon" => "fa-star"
    ]
];

/* ================= NEAR EVENTS ================= */

$nearEvents = [];
$nearEventsStmt = $conn->prepare("
    SELECT
        id,
        event_code,
        title,
        start_date_and_time
    FROM events
    WHERE start_date_and_time >= NOW()
    ORDER BY start_date_and_time ASC
    LIMIT 2
");

if($nearEventsStmt){
    $nearEventsStmt->execute();
    $nearEventsResult = $nearEventsStmt->get_result();

    while($event = $nearEventsResult->fetch_assoc()){
        $eventTime = strtotime((string) ($event['start_date_and_time'] ?? ''));
        $nearEvents[] = [
            "id" => (int) $event['id'],
            "code" => (string) ($event['event_code'] ?? ''),
            "title" => (string) ($event['title'] ?? 'Upcoming event'),
            "date" => $eventTime ? date("M j", $eventTime) : "Soon"
        ];
    }

    $nearEventsStmt->close();
}

/* ================= CURRENT USER ================= */

$current_user_id = $_SESSION['user_id'] ?? 0;
$current_account_type = $_SESSION['account_type'] ?? "";
$showBusinessOnboarding = false;
$needsBusinessPin = false;
$needsBusinessProfile = false;

if($current_account_type === "business_owner" && $current_user_id > 0){
    $businessSetupStmt = $conn->prepare("
        SELECT business_name, phone, description, address, business_photo, latitude, longitude
        FROM business_owner
        WHERE b_id = ?
        LIMIT 1
    ");

    if($businessSetupStmt){
        $businessSetupStmt->bind_param("i", $current_user_id);
        $businessSetupStmt->execute();
        $businessSetup = $businessSetupStmt->get_result()->fetch_assoc();
        $businessSetupStmt->close();

        if($businessSetup){
            $needsBusinessPin = empty(trim((string) ($businessSetup['address'] ?? '')))
                || $businessSetup['latitude'] === null
                || $businessSetup['longitude'] === null;

            $needsBusinessProfile = empty(trim((string) ($businessSetup['phone'] ?? '')))
                || empty(trim((string) ($businessSetup['description'] ?? '')))
                || empty(trim((string) ($businessSetup['business_photo'] ?? '')))
                || empty(trim((string) ($businessSetup['business_name'] ?? '')));

            $showBusinessOnboarding = $needsBusinessPin || $needsBusinessProfile;
        }
    }
}

/* ================= LATEST REVIEWS ================= */

$sql = "
SELECT 
    r.id,
    r.comment,
    r.images,
    r.created_at,
    r.is_anonymous,

    c.fname,
    c.lname,
    c.profile_picture,

    b.business_name,

    (SELECT COUNT(*) 
     FROM review_reacts 
     WHERE review_id = r.id 
     AND type = 'heart') AS total_hearts,

    (SELECT COUNT(*) 
     FROM review_reacts 
     WHERE review_id = r.id 
     AND type = 'comment') AS total_comments,

    EXISTS(
        SELECT 1 
        FROM review_reacts
        WHERE review_id = r.id
        AND user_id = ?
        AND account_type = ?
        AND type = 'heart'
    ) AS user_hearted

FROM reviews r

LEFT JOIN consumers c 
ON r.user_id = c.c_id

JOIN business_owner b 
ON r.business_id = b.b_id

WHERE r.is_hidden = 0

ORDER BY r.created_at DESC
LIMIT 6
";

$reviewStmt = $conn->prepare($sql);

if(!$reviewStmt){
    die("SQL Error: ".$conn->error);
}

$reviewStmt->bind_param("is",$current_user_id,$current_account_type);

$reviewStmt->execute();
$reviews = $reviewStmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NasugView – Home</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/responsive.css">


<?php require_once "config/theme.php"; render_theme_head(); ?>
<link rel="stylesheet" href="assets/css/home.css?v=20260512-near-events">
</head>

<body>

<div class="container">

<div class="topbar">
<a href="marketplace.php" class="search-bar">
<span>Search for ...</span>
<i class="fa fa-search"></i>
</a>

<a href="cart.php" class="cart-btn">
<i class="fa fa-cart-shopping"></i>
<?php if($cartCount>0): ?>
<span class="cart-badge"><?php echo $cartCount; ?></span>
<?php endif; ?>
</a>

</div>

<section class="home-hero">
<div class="hero-copy">
<span class="hero-eyebrow">Nasugbu local guide</span>
<h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
<p>Find trusted businesses, collect deals, and explore top-rated local favorites in one place.</p>
<div class="hero-actions">
<a href="marketplace.php" class="hero-primary"><i class="fa fa-store"></i> Explore Shops</a>
<a href="cart.php" class="hero-secondary"><i class="fa fa-cart-shopping"></i> View Cart</a>
</div>
</div>
<div class="hero-deals" aria-label="Featured benefits">
<div class="hero-deal-card">
<i class="fa fa-ticket"></i>
<strong>3</strong>
<span>Active codes</span>
</div>
<div class="hero-near-events-card">
<div class="hero-near-events-title">
<i class="fa fa-calendar-day"></i>
<strong>Near Events:</strong>
</div>
<div class="hero-near-events-list">
<?php if(count($nearEvents) > 0): ?>
<?php foreach($nearEvents as $event): ?>
<?php
$eventUrl = trim($event['code']) !== ""
    ? "registration.php?event_code=".urlencode($event['code'])
    : "calendar.php";
?>
<a href="<?php echo htmlspecialchars($eventUrl); ?>" class="hero-near-event-item">
<span class="hero-near-event-date"><?php echo htmlspecialchars($event['date']); ?></span>
<span class="hero-near-event-name"><?php echo htmlspecialchars($event['title']); ?></span>
</a>
<?php endforeach; ?>
<?php else: ?>
<a href="calendar.php" class="hero-near-event-item">
<span class="hero-near-event-date">Soon</span>
<span class="hero-near-event-name">View calendar</span>
</a>
<?php endif; ?>
</div>
</div>
</div>
</section>

<section class="promo-section" aria-labelledby="promo-title">
<div class="section-heading">
<div>
<div class="section-kicker">Limited deals</div>
<h2 id="promo-title">Promo Codes</h2>
</div>
<a href="marketplace.php" class="section-link">Browse deals</a>
</div>

<div class="promo-spotlight">
<div class="promo-spotlight-copy">
<span>NasugView Deals</span>
<strong>Save more on local favorites</strong>
<small>Collect codes, discover trusted places, and enjoy better value around Nasugbu.</small>
</div>
<div class="promo-spotlight-art" aria-hidden="true">
<span class="deal-bubble deal-bubble-main"><i class="fa fa-percent"></i></span>
<span class="deal-bubble deal-bubble-ticket"><i class="fa fa-ticket"></i></span>
<span class="deal-bubble deal-bubble-star"><i class="fa fa-star"></i></span>
</div>
</div>

<div class="promo-rail">
<?php foreach($promoCodes as $promo): ?>
<article class="promo-card">
<div class="promo-icon">
<i class="fa <?php echo htmlspecialchars($promo['icon']); ?>"></i>
</div>
<div class="promo-copy">
<strong><?php echo htmlspecialchars($promo['title']); ?></strong>
<span><?php echo htmlspecialchars($promo['description']); ?></span>
<small><?php echo htmlspecialchars($promo['meta']); ?></small>
</div>
<div class="promo-code" aria-label="Promo code <?php echo htmlspecialchars($promo['code']); ?>">
<?php echo htmlspecialchars($promo['code']); ?>
</div>
</article>
<?php endforeach; ?>
</div>
</section>

<div class="section-heading section-heading-spaced">
<div>
<div class="section-kicker">Trusted by reviewers</div>
<h2>Top Rated</h2>
</div>
<a href="marketplace.php" class="section-link">See all</a>
</div>

<div class="horizontal business-strip">

<?php if($topRated->num_rows === 0): ?>
<div class="empty-card">
<i class="fa fa-star"></i>
<span>No top rated businesses yet.</span>
</div>
<?php endif; ?>
<?php while($row = $topRated->fetch_assoc()): ?>

<?php
$photo = "assets/images/default-cover.png";
$roundedRating = (int) round((float) ($row['avg_rating'] ?? 0));

if(!empty($row['business_photo'])){
    $path = "uploads/business_cover/".$row['business_photo'];

    if(file_exists($path)){
        $photo = $path;
    }
}
?>

<a href="businessdetails.php?id=<?php echo (int) $row['b_id']; ?>" class="featured-card compact-card">
<div class="card-image-wrap">
<img src="<?php echo $photo; ?>" alt="<?php echo htmlspecialchars($row['business_name']); ?>">
<span class="card-badge">Top Rated</span>
</div>

<div class="card-info">

<div class="card-name">
<?php echo htmlspecialchars($row['business_name']); ?>
</div>

<div class="location">
<i class="fa fa-location-dot"></i>
<span><?php echo htmlspecialchars($row['address']); ?></span>
</div>

<div class="card-row">
<div class="rating">
<div class="rating-stars" aria-label="<?php echo number_format((float) ($row['avg_rating'] ?? 0), 1); ?> out of 5 stars">
<?php for($i = 1; $i <= 5; $i++): ?>
<i class="fa <?php echo $i <= $roundedRating ? 'fa-star' : 'fa-regular fa-star'; ?>"></i>
<?php endfor; ?>
</div>
<span><?php echo number_format((float) ($row['avg_rating'] ?? 0), 1); ?></span>
</div>
<span class="review-count"><?php echo (int) $row['total_reviews']; ?> reviews</span>
</div>

</div>
</a>

<?php endwhile; ?>

</div>

<div class="section-heading section-heading-spaced">
<div>
<div class="section-kicker">Fresh places to explore</div>
<h2>Recommended for You</h2>
</div>
<a href="marketplace.php" class="section-link">Explore</a>
</div>

<div class="horizontal business-strip">

<?php if($featured->num_rows === 0): ?>
<div class="empty-card">
<i class="fa fa-store"></i>
<span>No recommendations available yet.</span>
</div>
<?php endif; ?>
<?php while($row = $featured->fetch_assoc()): ?>

<?php
$photo = "assets/images/default-cover.png";
$roundedRating = (int) round((float) ($row['avg_rating'] ?? 0));

if(!empty($row['business_photo'])){
    $path = "uploads/business_cover/".$row['business_photo'];

    if(file_exists($path)){
        $photo = $path;
    }
}
?>

<a href="businessdetails.php?id=<?php echo (int) $row['b_id']; ?>" class="featured-card compact-card">
<div class="card-image-wrap">
<img src="<?php echo $photo; ?>" alt="<?php echo htmlspecialchars($row['business_name']); ?>">
<span class="card-badge card-badge-soft">Discover</span>
</div>

<div class="card-info">

<div class="card-name">
<?php echo htmlspecialchars($row['business_name']); ?>
</div>

<div class="location">
<i class="fa fa-location-dot"></i>
<span><?php echo htmlspecialchars($row['address']); ?></span>
</div>

<div class="card-row">
<div class="rating">
<div class="rating-stars" aria-label="<?php echo number_format((float) ($row['avg_rating'] ?? 0), 1); ?> out of 5 stars">
<?php for($i = 1; $i <= 5; $i++): ?>
<i class="fa <?php echo $i <= $roundedRating ? 'fa-star' : 'fa-regular fa-star'; ?>"></i>
<?php endfor; ?>
</div>
<span><?php echo number_format((float) ($row['avg_rating'] ?? 0), 1); ?></span>
</div>
<span class="review-count"><?php echo (int) $row['total_reviews']; ?> reviews</span>
</div>

</div>
</a>

<?php endwhile; ?>

</div>

<div class="section-heading section-heading-spaced">
<div>
<div class="section-kicker">Community updates</div>
<h2>Latest Reviews</h2>
</div>
</div>

<?php while($review = $reviews->fetch_assoc()): ?>

<div class="review-card">

<?php
$images = [];

if(!empty($review['images'])){
    $images = explode(',', $review['images']);
}
?>

<?php if(!empty($images)): ?>

<div class="review-images">

<?php
$reviewImagePaths = [];
foreach($images as $img){
    $trimmedImg = trim($img);
    if($trimmedImg !== ""){
        $reviewImagePaths[] = "uploads/reviews/".$trimmedImg;
    }
}
?>

<?php foreach($reviewImagePaths as $index => $imagePath): ?>

<button
type="button"
class="review-image-btn"
data-images='<?php echo htmlspecialchars(json_encode($reviewImagePaths), ENT_QUOTES, "UTF-8"); ?>'
data-index="<?php echo $index; ?>"
aria-label="Open review image"
>
<img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Review image">
</button>

<?php endforeach; ?>

</div>

<?php endif; ?>

<div class="review-header">

<?php if(!$review['is_anonymous'] && !empty($review['profile_picture'])): ?>
<img src="uploads/profile/<?php echo htmlspecialchars($review['profile_picture']); ?>" class="profile-pic">
<?php else: ?>
<img src="assets/images/avatar.jpg" class="profile-pic">
<?php endif; ?>

<div>

<div class="review-name">

<?php
if($review['is_anonymous']){
echo "Anonymous";
}else{
echo htmlspecialchars($review['fname']." ".$review['lname']);
}
?>

</div>

<div class="review-business">
<?php echo htmlspecialchars($review['business_name']); ?>
</div>

<div class="review-date">
<?php echo date("F d, Y", strtotime($review['created_at'])); ?>
</div>

</div>
</div>

<div class="review-text">
<?php echo htmlspecialchars($review['comment']); ?>
</div>

<div class="react-bar">

<input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">

<button 
type="button"
class="react-btn heart-btn <?php echo $review['user_hearted'] ? 'hearted':'' ?>"
data-review="<?php echo $review['id']; ?>"
>
<i class="<?php echo $review['user_hearted'] ? 'fa-solid':'fa-regular'; ?> fa-heart"></i>
<span class="heart-count"><?php echo $review['total_hearts']; ?></span>
</button>

<button 
type="button"
class="react-btn comment-btn"
data-review="<?php echo $review['id']; ?>"
>
<i class="fa fa-comment"></i>
<span class="comment-count"><?php echo $review['total_comments']; ?></span>
</button>

</div>

<?php

$commentStmt = $conn->prepare("
SELECT 
rr.comment,
rr.account_type,
c.fname,
c.lname,
bo.business_name
FROM review_reacts rr
LEFT JOIN consumers c
ON rr.user_id = c.c_id AND rr.account_type='consumer'
LEFT JOIN business_owner bo
ON rr.user_id = bo.b_id AND rr.account_type='business_owner'
WHERE rr.review_id=? 
AND rr.type='comment'
ORDER BY rr.created_at ASC
LIMIT 2
");

$commentStmt->bind_param("i",$review['id']);
$commentStmt->execute();
$comments = $commentStmt->get_result();

?>

<?php while($c = $comments->fetch_assoc()): ?>

<div class="comment">

<strong>

<?php

if($c['account_type'] == "consumer"){
echo htmlspecialchars($c['fname']." ".$c['lname']);
}else{
echo htmlspecialchars($c['business_name'])." ";
echo "<span style='color:#ff9800;font-size:12px;'>✔ Business</span>";
}

?>

</strong>

<?php echo htmlspecialchars($c['comment']); ?>

</div>

<?php endwhile; ?>

<form method="POST" class="comment-box">

<input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">

<input type="text" name="comment" placeholder="Write a comment..." required>

<button type="submit" name="submit_comment" style="display:none;"></button>

</form>

</div>

<?php endwhile; ?>

</div>

<div id="commentModal" class="comment-modal">

<div class="modal-content">

<div class="modal-header">
Comments
<span class="close-modal">&times;</span>
</div>

<div id="modalComments" class="modal-comments"></div>

<form id="modalCommentForm" class="modal-comment-box">

<input type="hidden" id="modalReviewId" name="review_id">

<input type="text" id="modalCommentInput" name="comment" placeholder="Write a comment..." required>

<button type="submit">
<i class="fa fa-paper-plane"></i>
</button>

</form>

</div>
</div>

<div id="imageModal" class="image-modal" aria-hidden="true">
<button type="button" class="image-nav image-prev" aria-label="Previous image">
<i class="fa fa-chevron-left"></i>
</button>
<button type="button" class="image-nav image-next" aria-label="Next image">
<i class="fa fa-chevron-right"></i>
</button>
<button type="button" class="image-close" aria-label="Close image viewer">&times;</button>
<div class="image-modal-content">
<img id="imageModalPreview" src="" alt="Expanded review image">
</div>
</div>

<?php if($showBusinessOnboarding): ?>
<div id="businessSetupModal" class="business-setup-modal" aria-hidden="true" hidden>
<div class="business-setup-panel" role="dialog" aria-modal="true" aria-labelledby="businessSetupTitle">
<button type="button" class="business-setup-close" aria-label="Close setup reminder">&times;</button>
<div class="business-setup-icon">
<i class="fa fa-store"></i>
</div>
<h2 id="businessSetupTitle" class="business-setup-title">Complete your business profile</h2>
<p class="business-setup-text">Set your business pin and finish your profile so customers can find your shop and trust your page faster.</p>
<ul class="business-setup-list">
<?php if($needsBusinessPin): ?>
<li>
<i class="fa fa-location-dot"></i>
<span>Add your address and place the exact map pin for your business.</span>
</li>
<?php endif; ?>
<?php if($needsBusinessProfile): ?>
<li>
<i class="fa fa-pen"></i>
<span>Customize your business profile with business details, phone, description, and cover photo.</span>
</li>
<?php endif; ?>
</ul>
<div class="business-setup-actions">
<a href="business_profile.php" class="business-setup-link">Open Business Profile</a>
<button type="button" class="business-setup-dismiss">Maybe Later</button>
</div>
</div>
</div>
<?php endif; ?>

<?php include 'bottom_nav.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function(){
const modal = document.getElementById("commentModal");
const modalComments = document.getElementById("modalComments");
const modalReviewId = document.getElementById("modalReviewId");
const modalCommentForm = document.getElementById("modalCommentForm");
const modalCommentInput = document.getElementById("modalCommentInput");
const businessSetupModal = document.getElementById("businessSetupModal");
const businessSetupClose = document.querySelector(".business-setup-close");
const businessSetupDismiss = document.querySelector(".business-setup-dismiss");
const businessSetupCooldownMs = 5 * 60 * 60 * 1000;
const businessSetupStorageKey = businessSetupModal
? "businessSetupReminderUntil_<?php echo (int) $current_user_id; ?>"
: "";
const imageModal = document.getElementById("imageModal");
const imageModalPreview = document.getElementById("imageModalPreview");
const imageCloseBtn = document.querySelector(".image-close");
const imagePrevBtn = document.querySelector(".image-prev");
const imageNextBtn = document.querySelector(".image-next");
let modalCloseTimer = null;
let activeImages = [];
let activeImageIndex = 0;
let touchStartX = 0;
let touchCurrentX = 0;

modal.style.display = "none";

function syncBodyOverflow(){
const hasCommentModal = modal.classList.contains("is-visible");
const hasImageModal = imageModal.style.display === "flex";
const hasBusinessSetup = businessSetupModal && businessSetupModal.classList.contains("is-visible");
document.body.style.overflow = hasCommentModal || hasImageModal || hasBusinessSetup ? "hidden" : "";
}

function openBusinessSetupModal(){
if(!businessSetupModal){
return;
}

if(shouldSnoozeBusinessSetupModal()){
return;
}

businessSetupModal.hidden = false;
businessSetupModal.classList.add("is-visible");
businessSetupModal.setAttribute("aria-hidden", "false");
syncBodyOverflow();
}

function closeBusinessSetupModal(){
if(!businessSetupModal){
return;
}

businessSetupModal.classList.remove("is-visible");
businessSetupModal.setAttribute("aria-hidden", "true");
businessSetupModal.hidden = true;
syncBodyOverflow();
}

function shouldSnoozeBusinessSetupModal(){
if(!businessSetupStorageKey){
return false;
}

try{
const reminderUntil = parseInt(window.localStorage.getItem(businessSetupStorageKey), 10);
return Number.isFinite(reminderUntil) && Date.now() < reminderUntil;
}catch(error){
return false;
}
}

function snoozeBusinessSetupModal(){
if(!businessSetupStorageKey){
return;
}

try{
window.localStorage.setItem(
businessSetupStorageKey,
String(Date.now() + businessSetupCooldownMs)
);
}catch(error){
// Ignore storage issues and fall back to the default behavior.
}
}

function updateImageModal(){
if(!activeImages.length){
return;
}

imageModalPreview.src = activeImages[activeImageIndex];
imagePrevBtn.style.display = activeImages.length > 1 ? "flex" : "none";
imageNextBtn.style.display = activeImages.length > 1 ? "flex" : "none";
}

function showPrevImage(){
if(activeImages.length < 2){
return;
}

activeImageIndex = (activeImageIndex - 1 + activeImages.length) % activeImages.length;
updateImageModal();
}

function showNextImage(){
if(activeImages.length < 2){
return;
}

activeImageIndex = (activeImageIndex + 1) % activeImages.length;
updateImageModal();
}

function openImageModal(images, startIndex){
activeImages = images;
activeImageIndex = startIndex;
updateImageModal();
imageModal.style.display = "flex";
imageModal.setAttribute("aria-hidden", "false");
syncBodyOverflow();
}

function closeImageModal(){
imageModalPreview.src = "";
imageModal.style.display = "none";
imageModal.setAttribute("aria-hidden", "true");
activeImages = [];
activeImageIndex = 0;
document.body.style.overflow = modal.classList.contains("is-visible") ? "hidden" : "";
syncBodyOverflow();
}

function openCommentModal(){
if(modalCloseTimer){
clearTimeout(modalCloseTimer);
modalCloseTimer = null;
}

modal.style.display = "flex";
modal.offsetHeight;

window.requestAnimationFrame(function(){
modal.classList.add("is-visible");
});

syncBodyOverflow();
}

function closeCommentModal(){
modal.classList.remove("is-visible");

if(modalCloseTimer){
clearTimeout(modalCloseTimer);
}

modalCloseTimer = setTimeout(function(){
modal.style.display = "none";
syncBodyOverflow();
modalCloseTimer = null;
}, 280);
}

if(businessSetupModal){
openBusinessSetupModal();
}

if(businessSetupClose){
businessSetupClose.addEventListener("click", function(){
snoozeBusinessSetupModal();
closeBusinessSetupModal();
});
}

if(businessSetupDismiss){
businessSetupDismiss.addEventListener("click", function(){
snoozeBusinessSetupModal();
closeBusinessSetupModal();
});
}

/* ================= HEART REACTION ================= */

document.querySelectorAll(".heart-btn").forEach(function(btn){

btn.addEventListener("click", function(){

let reviewId = this.dataset.review;
let icon = this.querySelector("i");
let count = this.querySelector(".heart-count");
let button = this;

fetch(window.location.href,{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:"ajax_heart=1&review_id="+reviewId
})
.then(res => res.json())
.then(data =>{

if(data.total !== undefined){
count.textContent = data.total;
}

if(data.liked){
icon.classList.remove("fa-regular");
icon.classList.add("fa-solid");
button.classList.add("hearted");
}else{
icon.classList.remove("fa-solid");
icon.classList.add("fa-regular");
button.classList.remove("hearted");
}

})
.catch(err => console.log(err));

});

});


/* ================= COMMENT MODAL ================= */

/* OPEN COMMENT MODAL */

document.querySelectorAll(".comment-btn").forEach(function(btn){

btn.addEventListener("click", function(){

let reviewId = this.dataset.review;

modalReviewId.value = reviewId;
modalComments.innerHTML = "<div class='comment'>Loading comments...</div>";
openCommentModal();

fetch(window.location.href,{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:"ajax_load_comments=1&review_id="+encodeURIComponent(reviewId)
})
.then(res => res.text())
.then(data => {

modalComments.innerHTML = data;

})
.catch(err => {
modalComments.innerHTML = "<div class='comment'>Unable to load comments right now.</div>";
console.log(err);
});

});

});

document.querySelectorAll(".review-image-btn").forEach(function(btn){
btn.addEventListener("click", function(){
openImageModal(JSON.parse(this.dataset.images), Number(this.dataset.index));
});
});

/* CLOSE MODAL */

document.querySelector(".close-modal").addEventListener("click", function(){
closeCommentModal();
});

window.addEventListener("click", function(e){
if(e.target === modal){
closeCommentModal();
}

if(businessSetupModal && e.target === businessSetupModal){
snoozeBusinessSetupModal();
closeBusinessSetupModal();
}
});

window.addEventListener("keydown", function(e){
if(e.key === "Escape" && modal.classList.contains("is-visible")){
closeCommentModal();
}

if(e.key === "Escape" && businessSetupModal && businessSetupModal.classList.contains("is-visible")){
snoozeBusinessSetupModal();
closeBusinessSetupModal();
}

if(e.key === "Escape" && imageModal.style.display === "flex"){
closeImageModal();
}

if(imageModal.style.display === "flex" && e.key === "ArrowLeft"){
showPrevImage();
}

if(imageModal.style.display === "flex" && e.key === "ArrowRight"){
showNextImage();
}
});

imageCloseBtn.addEventListener("click", function(e){
e.stopPropagation();
closeImageModal();
});

imageCloseBtn.addEventListener("touchend", function(e){
e.preventDefault();
e.stopPropagation();
closeImageModal();
});

imagePrevBtn.addEventListener("click", function(e){
e.stopPropagation();
showPrevImage();
});

imageNextBtn.addEventListener("click", function(e){
e.stopPropagation();
showNextImage();
});

imageModal.addEventListener("click", function(e){
if(e.target === imageModal){
closeImageModal();
}
});

imageModal.addEventListener("touchstart", function(e){
if(!e.touches.length){
return;
}

touchStartX = e.touches[0].clientX;
touchCurrentX = touchStartX;
}, { passive: true });

imageModal.addEventListener("touchmove", function(e){
if(!e.touches.length){
return;
}

touchCurrentX = e.touches[0].clientX;
}, { passive: true });

imageModal.addEventListener("touchend", function(){
let swipeDistance = touchCurrentX - touchStartX;

if(Math.abs(swipeDistance) > 50){
if(swipeDistance > 0){
showPrevImage();
}else{
showNextImage();
}
}

touchStartX = 0;
touchCurrentX = 0;
});



/* ================= SUBMIT COMMENT AJAX ================= */

modalCommentForm.addEventListener("submit", function(e){

e.preventDefault();

let reviewId = modalReviewId.value;
let comment = modalCommentInput.value.trim();

if(!comment){
return;
}

fetch(window.location.href,{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:"ajax_submit_comment=1&review_id="+encodeURIComponent(reviewId)+"&comment="+encodeURIComponent(comment)
})
.then(res => res.json())
.then(data =>{

if(!data.success){
return;
}

modalComments.innerHTML = data.html;
modalCommentInput.value = "";

let count = document.querySelector('.comment-btn[data-review="'+reviewId+'"] .comment-count');
if(count && data.total !== undefined){
count.textContent = data.total;
}

});

});

});

</script>
</body>
</html>

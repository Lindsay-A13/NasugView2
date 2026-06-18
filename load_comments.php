<?php
require_once "config/db.php";

$review_id = intval($_GET['review_id'] ?? 0);

$stmt = $conn->prepare("
SELECT
rr.comment,
rr.created_at,
rr.account_type,
c.fname,
c.lname,
bo.business_name
FROM review_reacts rr
LEFT JOIN consumers c
ON rr.user_id=c.c_id AND rr.account_type='consumer'
LEFT JOIN business_owner bo
ON rr.user_id=bo.b_id AND rr.account_type='business_owner'
WHERE rr.review_id=? AND rr.type='comment'
ORDER BY rr.created_at ASC
");

$stmt->bind_param("i", $review_id);
$stmt->execute();
$res = $stmt->get_result();

while($c = $res->fetch_assoc()){
    echo "<div style='margin-bottom:12px;'>";

    if($c['account_type'] === "consumer"){
        $name = trim(($c['fname'] ?? "") . " " . ($c['lname'] ?? ""));
        echo "<strong>" . htmlspecialchars($name !== "" ? $name : "Customer") . "</strong>";
    }else{
        $businessName = trim((string) ($c['business_name'] ?? ""));
        echo "<strong>" . htmlspecialchars($businessName !== "" ? $businessName : "Business") . " <span style='color:#ff9800'>&#10004; Business</span></strong>";
    }

    echo "<br>" . htmlspecialchars($c['comment'] ?? "");
    echo "<div style='font-size:11px;color:#7a8798;margin-top:4px;'>" . date("M d, Y g:i A", strtotime($c['created_at'])) . "</div>";
    echo "</div>";
}

$stmt->close();

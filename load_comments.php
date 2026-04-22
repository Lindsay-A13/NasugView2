<?php
require_once "config/db.php";

$review_id = intval($_GET['review_id']);

$stmt = $conn->prepare("
SELECT 
rr.comment,
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

$stmt->bind_param("i",$review_id);
$stmt->execute();
$res = $stmt->get_result();

while($c = $res->fetch_assoc()){

echo "<div style='margin-bottom:12px;'>";

if($c['account_type']=="consumer"){
echo "<strong>".$c['fname']." ".$c['lname']."</strong>";
}else{
echo "<strong>".$c['business_name']." <span style='color:#ff9800'>✔ Business</span></strong>";
}

echo "<br>".$c['comment']."</div>";

}
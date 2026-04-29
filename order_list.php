<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/notifications_helper.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

$owner_id = $_SESSION['user_id'];

/* ===== HANDLE CONFIRM ===== */
if(isset($_POST['confirm'])){
    $code = $_POST['order_code'];

    $consumerStmt = $conn->prepare("
        SELECT consumer_id
        FROM orders
        WHERE order_code = ? AND business_id = ?
        LIMIT 1
    ");
    $consumerStmt->bind_param("si", $code, $owner_id);
    $consumerStmt->execute();
    $consumerRow = $consumerStmt->get_result()->fetch_assoc();
    $consumerStmt->close();

    $update = $conn->prepare("
        UPDATE orders 
        SET status='Confirmed'
        WHERE order_code=? AND business_id=?
    ");
    $update->bind_param("si",$code,$owner_id);
    $update->execute();
    $updatedRows = $update->affected_rows;
    $update->close();

    if($updatedRows > 0 && !empty($consumerRow['consumer_id'])){
        insertNotification(
            $conn,
            (int) $consumerRow['consumer_id'],
            "consumer",
            "Order Status Updated",
            "Your order " . $code . " is now Confirmed."
        );
    }

    header("Location: order_list.php?tab=Confirmed");
    exit;
}

/* ===== TAB FILTER ===== */
$allowedTabs = ['All','Pending','Confirmed','Completed','Cancelled'];
$currentTab = $_GET['tab'] ?? 'All';

if(!in_array($currentTab,$allowedTabs)){
    $currentTab = 'All';
}

$query = "
    SELECT o.*, i.name, i.image
    FROM orders o
    JOIN inventory i ON o.product_id = i.id
    WHERE o.business_id = ?
";

if($currentTab !== 'All'){
    $query .= " AND o.status = '".$currentTab."'";
}

$query .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i",$owner_id);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while($row = $result->fetch_assoc()){
    $grouped[$row['order_code']][] = $row;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Incoming Orders</title>
<link rel="stylesheet" href="assets/css/responsive.css">

<style>

/* ===== RESET ===== */
*{ box-sizing:border-box; }

body{
    margin:0;
    font-family: Arial, sans-serif;
    background:#f4f6f9;
}

/* ===== PAGE WRAPPER ===== */
.page-wrapper{
    min-height:100vh;
    padding-bottom:90px; /* space for bottom nav */
}

/* ===== CONTAINER ===== */
.container{
    width:100%;
    padding:15px;
}

/* Desktop center */
@media(min-width:768px){
    .container{
        max-width:1500px;
        margin:0 auto;
        padding:25px;
    }
}

h2{
    margin:0 0 15px 0;
    color:#001a47;
}

/* ===== TABS ===== */
.tabs{
    display:flex;
    gap:10px;
    margin-bottom:20px;
    overflow-x:auto;
}

.tabs::-webkit-scrollbar{ display:none; }

.tab{
    padding:6px 14px;
    border-radius:20px;
    background:#e9ecef;
    text-decoration:none;
    color:#001a47;
    font-size:13px;
    white-space:nowrap;
}

.tab.active{
    background:#001a47;
    color:#fff;
}

/* ===== ORDER CARD ===== */
.order-box{
    background:#fff;
    border-radius:14px;
    padding:15px;
    margin-bottom:15px;
    box-shadow:0 3px 10px rgba(0,0,0,0.05);
}

.order-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
    flex-wrap:wrap;
    gap:5px;
}

.status{
    padding:4px 10px;
    border-radius:15px;
    font-size:11px;
    font-weight:600;
}

.pending{ background:#fff3cd; color:#856404; }
.confirmed{ background:#cce5ff; color:#004085; }
.completed{ background:#d4edda; color:#155724; }
.cancelled{ background:#f8d7da; color:#721c24; }

/* ===== ITEM ===== */
.order-item{
    display:flex;
    gap:10px;
    padding:10px 0;
    border-top:1px solid #eee;
}

.order-item img{
    width:60px;
    height:60px;
    border-radius:8px;
    object-fit:cover;
}

.item-info{
    flex:1;
    font-size:13px;
}

/* ===== TOTAL ===== */
.order-total{
    font-weight:bold;
    margin-top:8px;
    text-align:right;
    font-size:14px;
}

/* ===== BUTTON ===== */
.confirm-btn{
    padding:6px 12px;
    border:none;
    border-radius:6px;
    font-size:12px;
    cursor:pointer;
    background:#001a47;
    color:#fff;
}
.no-orders{
    text-align:center;
    color:#9ca3af;   /* soft grey */
    font-size:15px;
    padding:40px 0;
}
/* ===== ACTIONS LAYOUT ===== */

.actions{
    margin-top:12px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.actions form{
    margin:0;
}

.left-actions,
.right-actions{
    display:flex;
    gap:8px;
}

.right-actions form{
    margin:0;
}

/* ===== BUTTON STYLE ===== */

.btn{
    padding:8px 14px;
    border:none;
    border-radius:8px;
    font-size:12px;
    cursor:pointer;
    white-space:nowrap;
}

.btn-confirm{
    background:#001a47;
    color:#fff;
}

.btn-receipt{
    background:#001a47;
    color:#fff;
}

/* ===== RECEIPT MODAL ===== */

.receipt-modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(255, 255, 255, 0.6);
    justify-content:center;
    align-items:center;
    z-index:9999;
}

.receipt-content{
    background:#f2f2f2;
    width:95%;
    max-width:480px;
    border-radius:25px;
    padding:25px;
    position:relative;
    box-shadow:0 15px 35px rgba(0,0,0,0.25);
    font-family:Arial, sans-serif;
}

.close-receipt{
    position:absolute;
    right:18px;
    top:12px;
    font-size:18px;
    cursor:pointer;
    color:#777;
}

.receipt-title{
    text-align:center;
    font-size:20px;
    font-weight:700;
    color:#001a47;
    margin-bottom:15px;
}

.receipt-ordercode{
  background:#e6eef9;
    color:#001a47;
    font-weight:700;
    letter-spacing:1px;
    text-align:center;
    padding:10px;
    border-radius:12px;
    margin-bottom:15px;
}

.receipt-status{
    text-align:center;
    font-weight:700;
    font-size:16px;
    margin-bottom:15px;
    color:#004085;
}

.receipt-section{
    font-size:14px;
    margin-bottom:4px;
}

.receipt-divider{
    border-top:1px dashed #bbb;
    margin:15px 0;
}

.receipt-item{
    display:flex;
    gap:12px;
    margin-bottom:12px;
    align-items:flex-start;
}

.receipt-item img{
    width:55px;
    height:55px;
    border-radius:8px;
    object-fit:cover;
}

.receipt-item-info{
    flex:1;
}

.receipt-item-info strong{
    display:block;
    font-size:14px;
    margin-bottom:2px;
}

.receipt-total{
    border-top:1px solid #ddd;
    padding-top:12px;
    text-align:right;
    font-weight:700;
    font-size:16px;
    color:#001a47;
}
/* ===== MOBILE ===== */
@media(max-width:768px){

    .order-header{
        flex-direction:column;
        align-items:flex-start;
    }

    .order-total{
        text-align:left;
    }

    .order-item{
        align-items:flex-start;
    }

    .confirm-btn{
        width:100%;
        margin-top:8px;
    }
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="page-wrapper">
<div class="container">

<h2>Incoming Orders</h2>

<div class="tabs">
<?php foreach($allowedTabs as $tab): ?>
<a class="tab <?= $currentTab === $tab ? 'active' : '' ?>"
   href="order_list.php?tab=<?= $tab ?>">
   <?= $tab ?>
</a>
<?php endforeach; ?>
</div>

<?php if(empty($grouped)): ?>
<div class="no-orders">
    No orders found.
</div><?php endif; ?>

<?php foreach($grouped as $code => $items): ?>
<div class="order-box">

<div class="order-header">
    <div><strong>Order Code:</strong> <?= $code ?></div>
    <?php $status = $items[0]['status']; ?>
    <span class="status <?= strtolower($status) ?>">
        <?= $status ?>
    </span>
</div>

<?php 
$total = 0;
foreach($items as $item):
$subtotal = $item['price'] * $item['quantity'];
$total += $subtotal;
?>

<div class="order-item">
    <img src="uploads/product/<?= $item['image'] ?>">
    <div class="item-info">
        <div><strong><?= $item['name'] ?></strong></div>
        <div>Qty: <?= $item['quantity'] ?></div>
        <div>₱<?= number_format($item['price'],2) ?></div>
    </div>
</div>

<?php endforeach; ?>

<div class="order-total">
Total: ₱<?= number_format($total,2) ?>
</div>

<div class="actions">

    <?php if($status === "Confirmed" || $status === "Completed"): ?>
    <button type="button" 
            class="btn btn-receipt"
            onclick="openReceiptModal('<?= $code ?>')">
        Show Pickup Receipt
    </button>
    <?php endif; ?>

    <?php if($status === "Pending"): ?>
    <form method="POST">
        <input type="hidden" name="order_code" value="<?= $code ?>">
        <button class="btn btn-confirm" name="confirm">
            Confirm Order
        </button>
    </form>
    <?php endif; ?>

</div>

</div>
<?php endforeach; ?>

</div>
</div>

<script>
const ordersData = <?= json_encode($grouped); ?>;

function openReceiptModal(orderCode){

    const items = ordersData[orderCode];
    if(!items) return;

    let total = 0;
    let status = items[0].status;

    let html = `
        <div class="receipt-title">Pickup Receipt</div>

        <div class="receipt-ordercode">
            ${orderCode}
        </div>

        <div class="receipt-status">
            Status: ${status}
        </div>

        <div class="receipt-section">
            <strong>Business:</strong> <?= htmlspecialchars($_SESSION['business_name'] ?? '') ?>
        </div>

        <div class="receipt-section">
            <strong>Customer:</strong> ${items[0].consumer_name ?? ''}
        </div>

        <div class="receipt-divider"></div>
    `;

    items.forEach(item => {

        let subtotal = item.price * item.quantity;
        total += subtotal;

        html += `
            <div class="receipt-item">
                <img src="uploads/product/${item.image}">
                <div class="receipt-item-info">
                    <strong>${item.name}</strong>
                    Qty: ${item.quantity}<br>
                    ₱${parseFloat(item.price).toFixed(2)}
                </div>
            </div>
        `;
    });

    html += `
        <div class="receipt-total">
            Total: ₱${parseFloat(total).toFixed(2)}
        </div>
    `;

    document.getElementById("receiptBody").innerHTML = html;
    document.getElementById("receiptModal").style.display = "flex";
}

function closeReceiptModal(){
    document.getElementById("receiptModal").style.display = "none";
}
</script>


<?php include 'bottom_nav.php'; ?>

<div id="receiptModal" class="receipt-modal">
    <div class="receipt-content">
        <span class="close-receipt" onclick="closeReceiptModal()">&times;</span>
        <div id="receiptBody"></div>
    </div>
</div>

</body>
</html>

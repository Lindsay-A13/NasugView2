<?php
require_once "config/session.php";
require_once "config/db.php";

if($_SESSION['account_type'] !== "consumer"){
    header("Location: more.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* ===== AUTO COMPLETE AFTER 2 DAYS ===== */
$auto = $conn->prepare("
    UPDATE orders
    SET status = 'Completed'
    WHERE status = 'Confirmed'
    AND consumer_id = ?
    AND created_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)
");
$auto->bind_param("i",$user_id);
$auto->execute();

/* ===== HANDLE ACTIONS ===== */
if(isset($_POST['receive'])){
    $code = $_POST['order_code'];

    $update = $conn->prepare("
        UPDATE orders 
        SET status='Completed'
        WHERE order_code=? AND consumer_id=?
    ");
    $update->bind_param("si",$code,$user_id);
    $update->execute();

    header("Location: orders.php?tab=Completed");
    exit;
}

if(isset($_POST['cancel'])){
    $code = $_POST['order_code'];

    $update = $conn->prepare("
        UPDATE orders 
        SET status='Cancelled'
        WHERE order_code=? AND consumer_id=? AND status='Pending'
    ");
    $update->bind_param("si",$code,$user_id);
    $update->execute();

    header("Location: orders.php?tab=Cancelled");
    exit;
}

/* ===== TAB FILTER ===== */
$allowedTabs = ['All','Pending','Confirmed','Completed','Cancelled'];
$currentTab = $_GET['tab'] ?? 'All';
if(!in_array($currentTab,$allowedTabs)){
    $currentTab = 'All';
}

$query = "
    SELECT o.*, i.name, i.image, b.business_name
    FROM orders o
    JOIN inventory i ON o.product_id = i.id
    JOIN business_owner b ON o.business_id = b.b_id
    WHERE o.consumer_id = ?
";

if($currentTab !== 'All'){
    $query .= " AND o.status = '".$currentTab."'";
}

$query .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i",$user_id);
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
<title>My Orders</title>
<link rel="stylesheet" href="assets/css/responsive.css">

<style>

/* ===== RESET ===== */
*{
    box-sizing:border-box;
}

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

/* Desktop Center */
@media(min-width:768px){
    .container{
        max-width:1500px;
        margin:0 auto;
        padding:25px;
    }
}

/* ===== TITLE ===== */
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

.tabs::-webkit-scrollbar{
    display:none;
}

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
.no-orders{
    text-align:center;
    color:#9ca3af;   /* soft grey */
    font-size:15px;
    padding:40px 0;
}

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

/* ===== ACTIONS ===== */
.actions{
    margin-top:12px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.action-form{
    margin:0;
}

.btn{
    padding:6px 12px;
    border:none;
    border-radius:6px;
    font-size:12px;
    cursor:pointer;
}

.btn-receive{
    background:#001a47;
    color:#fff;
}

.btn-cancel{
    background:#dc3545;
    color:#fff;
}
.btn-receipt{
    background:#001a47;
    color:#fff;
}

.receipt-modal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    justify-content:center;
    align-items:center;
    z-index:999;
}

.receipt-content{
    background:#fff;
    width:95%;
    max-width:420px;
    border-radius:18px;
    padding:20px;
    position:relative;
    animation:fadeIn .25s ease;
    box-shadow:0 10px 25px rgba(0,0,0,0.15);
}

@keyframes fadeIn{
    from{transform:scale(.95);opacity:0;}
    to{transform:scale(1);opacity:1;}
}

.close-receipt{
    position:absolute;
    right:15px;
    top:10px;
    font-size:20px;
    cursor:pointer;
    color:#999;
}

.receipt-header{
    text-align:center;
    margin-bottom:15px;
}

.receipt-header h3{
    margin:0;
    color:#001a47;
}

.receipt-ordercode{
    background:#e6eef9;
    color:#001a47;
    font-weight:bold;
    font-size:16px;
    padding:8px;
    border-radius:8px;
    text-align:center;
    margin:10px 0;
    letter-spacing:1px;
}

.receipt-section{
    font-size:13px;
    margin-bottom:5px;
}

.receipt-items{
    margin-top:12px;
    border-top:1px dashed #ccc;
    padding-top:10px;
}

.receipt-item{
    display:flex;
    gap:10px;
    margin-bottom:10px;
    align-items:center;
}

.receipt-item img{
    width:45px;
    height:45px;
    border-radius:8px;
    object-fit:cover;
}

.receipt-item-info{
    flex:1;
    font-size:13px;
}

.receipt-total{
    border-top:1px solid #eee;
    margin-top:10px;
    padding-top:8px;
    font-weight:bold;
    text-align:right;
    font-size:14px;
    color:#001a47;
}

/* ===== MOBILE ===== */
@media(max-width:768px){

    .order-header{
        flex-direction:column;
        align-items:flex-start;
    }

    .order-total,
    .actions{
        text-align:left;
    }

    .order-item{
        align-items:flex-start;
    }
}

</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="page-wrapper">
<div class="container">

<h2>My Orders</h2>

<div class="tabs">
<?php foreach($allowedTabs as $tab): ?>
<a class="tab <?= $currentTab === $tab ? 'active' : '' ?>"
   href="orders.php?tab=<?= $tab ?>">
   <?= $tab ?>
</a>
<?php endforeach; ?>
</div>

<?php if(empty($grouped)): ?>
<div class="no-orders">
    No orders found.
</div>
<?php endif; ?>

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
        <div>Business: <?= $item['business_name'] ?></div>
    </div>
</div>

<?php endforeach; ?>

<div class="order-total">
Total: ₱<?= number_format($total,2) ?>
</div>

<div class="actions">

    <!-- LEFT SIDE -->
    <div class="left-actions">
        <?php if($status === "Confirmed" || $status === "Completed"): ?>
        <button type="button" 
                class="btn btn-receipt"
                onclick="openReceiptModal('<?= $code ?>')">
            Show Pickup Receipt
        </button>
        <?php endif; ?>
    </div>

    <!-- RIGHT SIDE -->
    <div class="right-actions">

        <?php if($status === "Pending"): ?>
        <form method="POST">
            <input type="hidden" name="order_code" value="<?= $code ?>">
            <button class="btn btn-cancel" name="cancel">
                Cancel Order
            </button>
        </form>
        <?php endif; ?>

        <?php if($status === "Confirmed"): ?>
        <form method="POST">
            <input type="hidden" name="order_code" value="<?= $code ?>">
            <button class="btn btn-receive" name="receive">
                Order Received
            </button>
        </form>
        <?php endif; ?>

    </div>

</div>

</div>
<?php endforeach; ?>

</div>
</div>

<?php include 'bottom_nav.php'; ?>

<!-- RECEIPT MODAL -->
<div id="receiptModal" class="receipt-modal">
    <div class="receipt-content">
        <span class="close-receipt" onclick="closeReceiptModal()">&times;</span>
        <div id="receiptBody"></div>
    </div>
</div>

<script>
const ordersData = <?= json_encode($grouped); ?>;

function openReceiptModal(orderCode){

    const items = ordersData[orderCode];
    if(!items) return;

    let total = 0;
    let status = items[0].status;

    let statusColor = "#6c757d";

    if(status === "Confirmed") statusColor = "#004085";
    if(status === "Completed") statusColor = "#155724";

    let html = `
        <div class="receipt-header">
            <h3>Pickup Receipt</h3>
        </div>

        <div class="receipt-ordercode">
            ORDER CODE: ${orderCode}
        </div>

        <div style="
            text-align:center;
            margin-bottom:10px;
            font-weight:bold;
            color:${statusColor};
        ">
            Status: ${status}
        </div>

        <div class="receipt-section">
            <strong>Business:</strong> ${items[0].business_name}
        </div>

        <div class="receipt-section">
            <strong>Customer:</strong> <?= $_SESSION['username'] ?? '' ?>
        </div>

        <div class="receipt-items">
    `;

    items.forEach(item => {

        let subtotal = item.price * item.quantity;
        total += subtotal;

        html += `
            <div class="receipt-item">
                <img src="uploads/product/${item.image}">
                <div class="receipt-item-info">
                    <div><strong>${item.name}</strong></div>
                    <div>Qty: ${item.quantity}</div>
                    <div>₱${parseFloat(subtotal).toFixed(2)}</div>
                </div>
            </div>
        `;
    });

    html += `
        </div>
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

</body>
</html>

<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/orders_helper.php";

if(!in_array($_SESSION['account_type'], ["consumer", "business_owner"], true)){
    header("Location: more.php");
    exit;
}

ensureOrderPaymentSupport($conn);

$user_id = (int) $_SESSION['user_id'];
$account_type = (string) $_SESSION['account_type'];

if(isset($_POST['cancel'])){
    $code = trim($_POST['order_code'] ?? '');

    $update = $conn->prepare("
        UPDATE orders
        SET status = 'Cancelled'
        WHERE order_code = ?
          AND consumer_id = ?
          AND (buyer_account_type = ? OR (? = 'consumer' AND buyer_account_type IS NULL))
          AND status = 'Pending'
    ");
    $update->bind_param("siss", $code, $user_id, $account_type, $account_type);
    $update->execute();
    $update->close();

    header("Location: orders.php?tab=Cancelled");
    exit;
}

$allowedTabs = ['All','Pending','For Payment','Completed','Cancelled','Refund'];
$currentTab = $_GET['tab'] ?? 'All';

if(!in_array($currentTab, $allowedTabs, true)){
    $currentTab = 'All';
}

$query = "
    SELECT
        o.*,
        COALESCE(i.name, s.name) AS name,
        COALESCE(i.image, s.image) AS image,
        s.duration AS service_duration,
        b.business_name
    FROM orders o
    LEFT JOIN inventory i ON o.product_id = i.id
    LEFT JOIN services s ON o.service_id = s.id
    JOIN business_owner b ON o.business_id = b.b_id
    WHERE o.consumer_id = ?
      AND (o.buyer_account_type = ? OR (? = 'consumer' AND o.buyer_account_type IS NULL))
";

if($currentTab !== 'All'){
    $query .= " AND o.status = '" . $currentTab . "'";
}

$query .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("iss", $user_id, $account_type, $account_type);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while($row = $result->fetch_assoc()){
    $grouped[$row['order_code']][] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Orders</title>
<link rel="stylesheet" href="assets/css/responsive.css">

<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f6f9}
.page-wrapper{min-height:100vh;padding-bottom:90px}
.container{width:100%;padding:15px}
@media(min-width:768px){.container{max-width:1500px;margin:0 auto;padding:25px}}
h2{margin:0 0 15px;color:#001a47}
.tabs{display:flex;gap:10px;margin-bottom:20px;overflow-x:auto}
.tabs::-webkit-scrollbar{display:none}
.tab{padding:6px 14px;border-radius:20px;background:#e9ecef;text-decoration:none;color:#001a47;font-size:13px;white-space:nowrap}
.tab.active{background:#001a47;color:#fff}
.order-box{background:#fff;border-radius:14px;padding:15px;margin-bottom:15px;box-shadow:0 3px 10px rgba(0,0,0,.05)}
.order-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:5px}
.status{padding:4px 10px;border-radius:15px;font-size:11px;font-weight:600}
.pending{background:#fff3cd;color:#856404}
.for-payment{background:#ffe8cc;color:#9a4d00}
.completed{background:#d4edda;color:#155724}
.cancelled{background:#f8d7da;color:#721c24}
.refund{background:#f3e8ff;color:#6b21a8}
.no-orders{text-align:center;color:#9ca3af;font-size:15px;padding:40px 0}
.order-item{display:flex;gap:10px;padding:10px 0;border-top:1px solid #eee}
.order-item img{width:60px;height:60px;border-radius:8px;object-fit:cover}
.item-info{flex:1;font-size:13px}
.order-total{font-weight:700;margin-top:8px;text-align:right;font-size:14px}
.actions{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.left-actions,.right-actions{display:flex;gap:8px;flex-wrap:wrap}
.right-actions form{margin:0}
.btn{padding:6px 12px;border:none;border-radius:6px;font-size:12px;cursor:pointer}
.btn-receipt{background:#001a47;color:#fff}
.btn-cancel{background:#dc3545;color:#fff}
.payment-note{margin-top:10px;padding:10px 12px;border-radius:10px;background:#f8fafc;color:#334155;font-size:13px}
.receipt-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);justify-content:center;align-items:center;z-index:999}
.receipt-content{background:#fff;width:95%;max-width:420px;border-radius:18px;padding:20px;position:relative;animation:fadeIn .25s ease;box-shadow:0 10px 25px rgba(0,0,0,.15)}
@keyframes fadeIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.close-receipt{position:absolute;right:15px;top:10px;font-size:20px;cursor:pointer;color:#999}
.receipt-header{text-align:center;margin-bottom:15px}
.receipt-header h3{margin:0;color:#001a47}
.receipt-ordercode{background:#e6eef9;color:#001a47;font-weight:700;font-size:16px;padding:8px;border-radius:8px;text-align:center;margin:10px 0;letter-spacing:1px}
.receipt-section{font-size:13px;margin-bottom:5px}
.receipt-items{margin-top:12px;border-top:1px dashed #ccc;padding-top:10px}
.receipt-item{display:flex;gap:10px;margin-bottom:10px;align-items:center}
.receipt-item img{width:45px;height:45px;border-radius:8px;object-fit:cover}
.receipt-item-info{flex:1;font-size:13px}
.receipt-total{border-top:1px solid #eee;margin-top:10px;padding-top:8px;font-weight:700;text-align:right;font-size:14px;color:#001a47}
@media(max-width:768px){
    .order-header{flex-direction:column;align-items:flex-start}
    .order-total,.actions{text-align:left}
    .order-item{align-items:flex-start}
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
<a class="tab <?= $currentTab === $tab ? 'active' : '' ?>" href="orders.php?tab=<?= urlencode($tab) ?>">
    <?= htmlspecialchars($tab) ?>
</a>
<?php endforeach; ?>
</div>

<?php if(empty($grouped)): ?>
<div class="no-orders">No orders found.</div>
<?php endif; ?>

<?php foreach($grouped as $code => $items): ?>
<div class="order-box">
    <div class="order-header">
        <div><strong>Order Code:</strong> <?= htmlspecialchars($code) ?></div>
        <?php $status = $items[0]['status']; ?>
        <?php $statusClass = strtolower(str_replace(' ', '-', $status)); ?>
        <span class="status <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($status) ?></span>
    </div>

    <?php $total = 0; ?>
    <?php foreach($items as $item): ?>
        <?php $subtotal = (float) $item['price'] * (int) $item['quantity']; ?>
        <?php $total += $subtotal; ?>
        <?php
        $isService = ($item['order_type'] ?? '') === 'service' || !empty($item['service_id']);
        $imagePath = $isService
            ? "uploads/services/" . ($item['image'] ?: 'default_service.jpg')
            : "uploads/product/" . ($item['image'] ?: 'default_product.jpg');
        ?>
        <div class="order-item">
            <img src="<?= htmlspecialchars($imagePath) ?>">
            <div class="item-info">
                <div><strong><?= htmlspecialchars($item['name']) ?></strong></div>
                <div><?= $isService ? 'Sessions' : 'Qty' ?>: <?= (int) $item['quantity'] ?><?= $isService && !empty($item['unit_label']) ? ' ' . htmlspecialchars($item['unit_label']) . ((int) $item['quantity'] === 1 ? '' : 's') : '' ?></div>
                <?php if($isService && !empty($item['booking_date'])): ?>
                <div>Appointment: <?= htmlspecialchars(date("M d, Y", strtotime($item['booking_date']))) ?><?= !empty($item['booking_time']) ? ' ' . htmlspecialchars(date("g:i A", strtotime($item['booking_time']))) : '' ?></div>
                <?php endif; ?>
                <?php if($isService && !empty($item['booking_note'])): ?>
                <div>Note: <?= htmlspecialchars($item['booking_note']) ?></div>
                <?php endif; ?>
                <div>&#8369;<?= number_format((float) $item['price'], 2) ?></div>
                <div>Business: <?= htmlspecialchars($item['business_name']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="order-total">Total: &#8369;<?= number_format($total, 2) ?></div>

    <div class="payment-note">
        <?php if(!empty($items[0]['payment_method'])): ?>
            Payment: <?= htmlspecialchars($items[0]['payment_method']) ?>
            <?php if($items[0]['amount_paid'] !== null): ?>
                | Paid: &#8369;<?= number_format((float) $items[0]['amount_paid'], 2) ?>
            <?php endif; ?>
            <?php if($items[0]['change_amount'] !== null && (float) $items[0]['change_amount'] > 0): ?>
                | Change: &#8369;<?= number_format((float) $items[0]['change_amount'], 2) ?>
            <?php endif; ?>
            <?php if($status === "Refund"): ?>
                | Refunded
            <?php endif; ?>
        <?php elseif($status === "For Payment"): ?>
            Payment: Waiting for business owner confirmation of payment.
        <?php else: ?>
            Payment: Not yet recorded.
        <?php endif; ?>
    </div>

    <div class="actions">
        <div class="left-actions">
            <?php if($status === "For Payment" || $status === "Completed" || $status === "Refund"): ?>
            <button type="button" class="btn btn-receipt" onclick='openReceiptModal(<?= json_encode($code) ?>)'>
                Show Receipt
            </button>
            <?php endif; ?>
        </div>

        <div class="right-actions">
            <?php if($status === "Pending"): ?>
            <form method="POST">
                <input type="hidden" name="order_code" value="<?= htmlspecialchars($code) ?>">
                <button class="btn btn-cancel" name="cancel">Cancel Order</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div>
</div>

<?php include 'bottom_nav.php'; ?>

<div id="receiptModal" class="receipt-modal">
    <div class="receipt-content">
        <span class="close-receipt" onclick="closeReceiptModal()">&times;</span>
        <div id="receiptBody"></div>
    </div>
</div>

<script>
const ordersData = <?= json_encode($grouped, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const consumerDisplayName = <?= json_encode(trim(($fname ?? '') . ' ' . ($lname ?? '')) !== '' ? trim(($fname ?? '') . ' ' . ($lname ?? '')) : ($username ?? 'Customer')) ?>;

function openReceiptModal(orderCode){
    const items = ordersData[orderCode];
    if(!items || !items.length) return;

    let total = 0;
    const status = items[0].status;
    const paymentMethod = items[0].payment_method || '';
    const amountPaid = items[0].amount_paid !== null ? parseFloat(items[0].amount_paid) : null;
    const changeAmount = items[0].change_amount !== null ? parseFloat(items[0].change_amount) : null;
    let statusColor = "#6c757d";

    if(status === "For Payment") statusColor = "#9a4d00";
    if(status === "Completed") statusColor = "#155724";
    if(status === "Refund") statusColor = "#6b21a8";

    let html = `
        <div class="receipt-header">
            <h3>Order Receipt</h3>
        </div>
        <div class="receipt-ordercode">ORDER CODE: ${orderCode}</div>
        <div style="text-align:center;margin-bottom:10px;font-weight:bold;color:${statusColor};">
            Status: ${status}
        </div>
        <div class="receipt-section"><strong>Business:</strong> ${items[0].business_name}</div>
        <div class="receipt-section"><strong>Customer:</strong> ${consumerDisplayName}</div>
        <div class="receipt-items">
    `;

    items.forEach((item) => {
        const subtotal = parseFloat(item.price) * parseInt(item.quantity, 10);
        const isService = item.order_type === 'service' || item.service_id;
        const imagePath = isService ? `uploads/services/${item.image || 'default_service.jpg'}` : `uploads/product/${item.image || 'default_product.jpg'}`;
        const unitLabel = item.unit_label || (isService ? 'session' : '');
        total += subtotal;

        html += `
            <div class="receipt-item">
                <img src="${imagePath}">
                <div class="receipt-item-info">
                    <div><strong>${item.name}</strong></div>
                    <div>${isService ? 'Sessions' : 'Qty'}: ${item.quantity}${isService ? ' ' + unitLabel + (parseInt(item.quantity, 10) === 1 ? '' : 's') : ''}</div>
                    ${isService && item.booking_date ? `<div>Appointment: ${item.booking_date}${item.booking_time ? ' ' + item.booking_time : ''}</div>` : ''}
                    ${isService && item.booking_note ? `<div>Note: ${item.booking_note}</div>` : ''}
                    <div>&#8369;${subtotal.toFixed(2)}</div>
                </div>
            </div>
        `;
    });

    if(paymentMethod){
        html += `
            <div class="receipt-section"><strong>Payment Method:</strong> ${paymentMethod}</div>
            <div class="receipt-section"><strong>Amount Paid:</strong> &#8369;${(amountPaid !== null ? amountPaid : total).toFixed(2)}</div>
            ${changeAmount !== null && changeAmount > 0 ? `<div class="receipt-section"><strong>Change:</strong> &#8369;${changeAmount.toFixed(2)}</div>` : ''}
            ${status === "Refund" ? `<div class="receipt-section"><strong>Refund Status:</strong> Refunded</div>` : ''}
        `;
    }else if(status === "For Payment"){
        html += `
            <div class="receipt-section"><strong>Payment:</strong> Awaiting payment.</div>
        `;
    }

    html += `
        </div>
        <div class="receipt-total">Total: &#8369;${total.toFixed(2)}</div>
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

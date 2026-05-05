<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/notifications_helper.php";
require_once "config/orders_helper.php";

if($_SESSION['account_type'] !== "business_owner"){
    header("Location: more.php");
    exit;
}

ensureOrderPaymentSupport($conn);

$owner_id = $_SESSION['user_id'];

if(isset($_POST['confirm'])){
    $code = trim($_POST['order_code'] ?? '');

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
        SET status = 'For Payment'
        WHERE order_code = ? AND business_id = ? AND status = 'Pending'
    ");
    $update->bind_param("si", $code, $owner_id);
    $update->execute();
    $updatedRows = $update->affected_rows;
    $update->close();

    if($updatedRows > 0 && !empty($consumerRow['consumer_id'])){
        insertNotification(
            $conn,
            (int) $consumerRow['consumer_id'],
            "consumer",
            "Order Status Updated",
            "Your order " . $code . " is now For Payment."
        );
    }

    header("Location: order_list.php?tab=For+Payment");
    exit;
}

if(isset($_POST['record_payment'])){
    $code = trim($_POST['order_code'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $amountPaidInput = trim($_POST['amount_paid'] ?? '');

    if($code === '' || !in_array($paymentMethod, ['GCash', 'Cash'], true)){
        header("Location: order_list.php?tab=For+Payment&error=" . urlencode("Invalid payment details."));
        exit;
    }

    $orderStmt = $conn->prepare("
        SELECT consumer_id, SUM(quantity * price) AS total_amount
        FROM orders
        WHERE order_code = ? AND business_id = ? AND status = 'For Payment'
        GROUP BY consumer_id
        LIMIT 1
    ");
    $orderStmt->bind_param("si", $code, $owner_id);
    $orderStmt->execute();
    $orderRow = $orderStmt->get_result()->fetch_assoc();
    $orderStmt->close();

    if(!$orderRow){
        header("Location: order_list.php?tab=For+Payment&error=" . urlencode("Order is not ready for payment."));
        exit;
    }

    $totalAmount = (float) $orderRow['total_amount'];
    $amountPaid = $totalAmount;
    $changeAmount = 0.00;

    if($paymentMethod === 'Cash'){
        $amountPaid = (float) $amountPaidInput;

        if($amountPaid < $totalAmount){
            header("Location: order_list.php?tab=For+Payment&error=" . urlencode("Cash received cannot be lower than the order total."));
            exit;
        }

        $changeAmount = $amountPaid - $totalAmount;
    }

    $paymentUpdate = $conn->prepare("
        UPDATE orders
        SET status = 'Completed',
            payment_method = ?,
            amount_paid = ?,
            change_amount = ?,
            paid_at = NOW()
        WHERE order_code = ? AND business_id = ? AND status = 'For Payment'
    ");
    $paymentUpdate->bind_param("sddsi", $paymentMethod, $amountPaid, $changeAmount, $code, $owner_id);
    $paymentUpdate->execute();
    $updatedRows = $paymentUpdate->affected_rows;
    $paymentUpdate->close();

    if($updatedRows > 0 && !empty($orderRow['consumer_id'])){
        $paymentMessage = $paymentMethod === 'Cash'
            ? "Your order " . $code . " has been paid in cash and is now Completed."
            : "Your order " . $code . " has been paid via GCash and is now Completed.";

        insertNotification(
            $conn,
            (int) $orderRow['consumer_id'],
            "consumer",
            "Order Status Updated",
            $paymentMessage
        );
    }

    header("Location: order_list.php?tab=Completed&success=" . urlencode("Payment recorded successfully."));
    exit;
}

if(isset($_POST['refund'])){
    $code = trim($_POST['order_code'] ?? '');

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

    $refund = $conn->prepare("
        UPDATE orders
        SET status = 'Refund'
        WHERE order_code = ? AND business_id = ? AND status = 'Completed'
    ");
    $refund->bind_param("si", $code, $owner_id);
    $refund->execute();
    $updatedRows = $refund->affected_rows;
    $refund->close();

    if($updatedRows > 0 && !empty($consumerRow['consumer_id'])){
        insertNotification(
            $conn,
            (int) $consumerRow['consumer_id'],
            "consumer",
            "Order Status Updated",
            "Your order " . $code . " has been marked as Refund."
        );
    }

    header("Location: order_list.php?tab=Refund&success=" . urlencode("Order marked as refund."));
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
        i.name,
        i.image,
        b.business_name,
        c.username AS consumer_username,
        c.fname AS consumer_fname,
        c.lname AS consumer_lname
    FROM orders o
    JOIN inventory i ON o.product_id = i.id
    JOIN business_owner b ON o.business_id = b.b_id
    LEFT JOIN consumers c ON o.consumer_id = c.c_id
    WHERE o.business_id = ?
";

if($currentTab !== 'All'){
    $query .= " AND o.status = '" . $currentTab . "'";
}

$query .= " ORDER BY o.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while($row = $result->fetch_assoc()){
    $consumerName = trim(($row['consumer_fname'] ?? '') . ' ' . ($row['consumer_lname'] ?? ''));
    $row['consumer_name'] = $consumerName !== '' ? $consumerName : ($row['consumer_username'] ?? 'Customer');
    $grouped[$row['order_code']][] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Incoming Orders</title>
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
.order-item{display:flex;gap:10px;padding:10px 0;border-top:1px solid #eee}
.order-item img{width:60px;height:60px;border-radius:8px;object-fit:cover}
.item-info{flex:1;font-size:13px}
.order-total{font-weight:700;margin-top:8px;text-align:right;font-size:14px}
.no-orders{text-align:center;color:#9ca3af;font-size:15px;padding:40px 0}
.actions{margin-top:12px;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.btn{padding:8px 14px;border:none;border-radius:8px;font-size:12px;cursor:pointer;white-space:nowrap}
.btn-confirm,.btn-receipt{background:#001a47;color:#fff}
.btn-pay{background:#198754;color:#fff}
.btn-refund{background:#7c3aed;color:#fff}
.receipt-modal{display:none;position:fixed;inset:0;background:rgba(255,255,255,.6);justify-content:center;align-items:center;z-index:9999}
.receipt-content{background:#f2f2f2;width:95%;max-width:480px;border-radius:25px;padding:25px;position:relative;box-shadow:0 15px 35px rgba(0,0,0,.25);font-family:Arial,sans-serif}
.close-receipt{position:absolute;right:18px;top:12px;font-size:18px;cursor:pointer;color:#777}
.receipt-title{text-align:center;font-size:20px;font-weight:700;color:#001a47;margin-bottom:15px}
.receipt-ordercode{background:#e6eef9;color:#001a47;font-weight:700;letter-spacing:1px;text-align:center;padding:10px;border-radius:12px;margin-bottom:15px}
.receipt-status{text-align:center;font-weight:700;font-size:16px;margin-bottom:15px;color:#004085}
.receipt-section{font-size:14px;margin-bottom:4px}
.receipt-divider{border-top:1px dashed #bbb;margin:15px 0}
.receipt-item{display:flex;gap:12px;margin-bottom:12px;align-items:flex-start}
.receipt-item img{width:55px;height:55px;border-radius:8px;object-fit:cover}
.receipt-item-info{flex:1}
.receipt-item-info strong{display:block;font-size:14px;margin-bottom:2px}
.receipt-total{border-top:1px solid #ddd;padding-top:12px;text-align:right;font-weight:700;font-size:16px;color:#001a47}
.flash-message{margin-bottom:16px;padding:12px 14px;border-radius:10px;font-size:14px}
.flash-message.error{background:#f8d7da;color:#842029}
.flash-message.success{background:#d1e7dd;color:#0f5132}
.payment-note{margin-top:10px;padding:10px 12px;border-radius:10px;background:#f8fafc;color:#334155;font-size:13px}
.payment-modal-card{background:#fff;width:95%;max-width:420px;border-radius:20px;padding:22px;box-shadow:0 15px 35px rgba(0,0,0,.25);position:relative}
.payment-modal-card h3{margin:0 0 16px;color:#001a47}
.payment-form-group{margin-bottom:14px}
.payment-form-group label{display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:#334155}
.payment-form-group select,.payment-form-group input{width:100%;padding:11px 12px;border:1px solid #d0d7de;border-radius:10px;font-size:14px}
.payment-summary{margin:14px 0;padding:12px;border-radius:12px;background:#f4f6f9;font-size:14px;color:#0f172a}
.payment-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
.btn-secondary{background:#e9ecef;color:#001a47}
.payment-hidden{display:none}
@media(max-width:768px){
    .order-header{flex-direction:column;align-items:flex-start}
    .order-total{text-align:left}
    .order-item{align-items:flex-start}
}
</style>
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>

<body>
<?php include 'mobile_back_button.php'; ?>

<div class="page-wrapper">
<div class="container">

<h2>Incoming Orders</h2>

<?php if(isset($_GET['error'])): ?>
<div class="flash-message error"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<?php if(isset($_GET['success'])): ?>
<div class="flash-message success"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>

<div class="tabs">
<?php foreach($allowedTabs as $tab): ?>
<a class="tab <?= $currentTab === $tab ? 'active' : '' ?>" href="order_list.php?tab=<?= urlencode($tab) ?>">
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
        <div class="order-item">
            <img src="uploads/product/<?= htmlspecialchars($item['image']) ?>">
            <div class="item-info">
                <div><strong><?= htmlspecialchars($item['name']) ?></strong></div>
                <div>Qty: <?= (int) $item['quantity'] ?></div>
                <div>&#8369;<?= number_format((float) $item['price'], 2) ?></div>
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
            Payment: Waiting for customer payment.
        <?php else: ?>
            Payment: Not yet recorded.
        <?php endif; ?>
    </div>

    <div class="actions">
        <?php if($status === "For Payment" || $status === "Completed" || $status === "Refund"): ?>
        <button type="button" class="btn btn-receipt" onclick='openReceiptModal(<?= json_encode($code) ?>)'>
            Show Pickup Receipt
        </button>
        <?php endif; ?>

        <?php if($status === "Pending"): ?>
        <form method="POST">
            <input type="hidden" name="order_code" value="<?= htmlspecialchars($code) ?>">
            <button class="btn btn-confirm" name="confirm">Acknowledge Order</button>
        </form>
        <?php endif; ?>

        <?php if($status === "For Payment"): ?>
        <button type="button" class="btn btn-pay" onclick='openPaymentModal(<?= json_encode($code) ?>, <?= json_encode((float) $total) ?>)'>
            Record Payment
        </button>
        <?php endif; ?>

        <?php if($status === "Completed"): ?>
        <form method="POST">
            <input type="hidden" name="order_code" value="<?= htmlspecialchars($code) ?>">
            <button class="btn btn-refund" name="refund">Mark Refund</button>
        </form>
        <?php endif; ?>
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

<div id="paymentModal" class="receipt-modal">
    <div class="payment-modal-card">
        <span class="close-receipt" onclick="closePaymentModal()">&times;</span>
        <h3>Record Payment</h3>
        <form method="POST">
            <input type="hidden" name="record_payment" value="1">
            <input type="hidden" name="order_code" id="paymentOrderCode">
            <input type="hidden" id="paymentTotalValue" value="0">

            <div class="payment-summary">
                <div><strong>Order Code:</strong> <span id="paymentOrderText"></span></div>
                <div><strong>Total:</strong> &#8369;<span id="paymentTotalText">0.00</span></div>
                <div><strong>Change:</strong> &#8369;<span id="paymentChangeText">0.00</span></div>
            </div>

            <div class="payment-form-group">
                <label for="paymentMethod">Payment Method</label>
                <select name="payment_method" id="paymentMethod" onchange="togglePaymentFields()">
                    <option value="GCash">GCash</option>
                    <option value="Cash">Cash</option>
                </select>
            </div>

            <div class="payment-form-group payment-hidden" id="amountPaidGroup">
                <label for="amountPaid">Cash Received</label>
                <input type="number" step="0.01" min="0" name="amount_paid" id="amountPaid" oninput="updateCashChange()">
            </div>

            <div class="payment-actions">
                <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-pay">Save Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
const ordersData = <?= json_encode($grouped, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openReceiptModal(orderCode){
    const items = ordersData[orderCode];
    if(!items || !items.length) return;

    let total = 0;
    const status = items[0].status;
    const paymentMethod = items[0].payment_method || '';
    const amountPaid = items[0].amount_paid !== null ? parseFloat(items[0].amount_paid) : null;
    const changeAmount = items[0].change_amount !== null ? parseFloat(items[0].change_amount) : null;
    const customerName = items[0].consumer_name || 'Customer';
    let statusColor = "#6c757d";

    if(status === "For Payment") statusColor = "#9a4d00";
    if(status === "Completed") statusColor = "#155724";
    if(status === "Refund") statusColor = "#6b21a8";

    let html = `
        <div class="receipt-title">Pickup Receipt</div>
        <div class="receipt-ordercode">${orderCode}</div>
        <div class="receipt-status"><span style="color:${statusColor};">Status: ${status}</span></div>
        <div class="receipt-section"><strong>Business:</strong> ${items[0].business_name}</div>
        <div class="receipt-section"><strong>Customer:</strong> ${customerName}</div>
        <div class="receipt-divider"></div>
    `;

    items.forEach((item) => {
        total += parseFloat(item.price) * parseInt(item.quantity, 10);
        html += `
            <div class="receipt-item">
                <img src="uploads/product/${item.image}">
                <div class="receipt-item-info">
                    <strong>${item.name}</strong>
                    Qty: ${item.quantity}<br>
                    &#8369;${parseFloat(item.price).toFixed(2)}
                </div>
            </div>
        `;
    });

    if(paymentMethod){
        html += `
            <div class="receipt-divider"></div>
            <div class="receipt-section"><strong>Payment Method:</strong> ${paymentMethod}</div>
            <div class="receipt-section"><strong>Amount Paid:</strong> &#8369;${(amountPaid !== null ? amountPaid : total).toFixed(2)}</div>
            ${changeAmount !== null && changeAmount > 0 ? `<div class="receipt-section"><strong>Change:</strong> &#8369;${changeAmount.toFixed(2)}</div>` : ''}
            ${status === "Refund" ? `<div class="receipt-section"><strong>Refund Status:</strong> Refunded</div>` : ''}
        `;
    }else if(status === "For Payment"){
        html += `
            <div class="receipt-divider"></div>
            <div class="receipt-section"><strong>Payment:</strong> Awaiting payment.</div>
        `;
    }

    html += `<div class="receipt-total">Total: &#8369;${total.toFixed(2)}</div>`;

    document.getElementById("receiptBody").innerHTML = html;
    document.getElementById("receiptModal").style.display = "flex";
}

function closeReceiptModal(){
    document.getElementById("receiptModal").style.display = "none";
}

function openPaymentModal(orderCode, total){
    document.getElementById("paymentOrderCode").value = orderCode;
    document.getElementById("paymentOrderText").textContent = orderCode;
    document.getElementById("paymentTotalText").textContent = total.toFixed(2);
    document.getElementById("paymentTotalValue").value = total.toFixed(2);
    document.getElementById("paymentMethod").value = "GCash";
    document.getElementById("amountPaid").value = total.toFixed(2);
    document.getElementById("amountPaidGroup").classList.add("payment-hidden");
    document.getElementById("paymentChangeText").textContent = "0.00";
    document.getElementById("paymentModal").style.display = "flex";
}

function closePaymentModal(){
    document.getElementById("paymentModal").style.display = "none";
}

function togglePaymentFields(){
    const total = parseFloat(document.getElementById("paymentTotalValue").value || "0");
    const method = document.getElementById("paymentMethod").value;
    const amountGroup = document.getElementById("amountPaidGroup");
    const amountInput = document.getElementById("amountPaid");

    if(method === "Cash"){
        amountGroup.classList.remove("payment-hidden");
        if(!amountInput.value || parseFloat(amountInput.value) < total){
            amountInput.value = total.toFixed(2);
        }
    }else{
        amountGroup.classList.add("payment-hidden");
        amountInput.value = total.toFixed(2);
        document.getElementById("paymentChangeText").textContent = "0.00";
    }

    updateCashChange();
}

function updateCashChange(){
    const total = parseFloat(document.getElementById("paymentTotalValue").value || "0");
    const method = document.getElementById("paymentMethod").value;
    const amountPaid = parseFloat(document.getElementById("amountPaid").value || "0");

    if(method !== "Cash"){
        document.getElementById("paymentChangeText").textContent = "0.00";
        return;
    }

    const change = amountPaid - total;
    document.getElementById("paymentChangeText").textContent = (change > 0 ? change : 0).toFixed(2);
}
</script>

</body>
</html>

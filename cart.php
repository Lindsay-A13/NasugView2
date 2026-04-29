<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/notifications_helper.php";

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$account_type = $_SESSION['account_type'];

/* ================= DELETE SINGLE ================= */
if(isset($_GET['delete'])){
    $cart_id = intval($_GET['delete']);
    $del = $conn->prepare("DELETE FROM cart WHERE id=? AND consumer_id=? AND account_type=?");
    $del->bind_param("iis",$cart_id,$user_id,$account_type);
    $del->execute();
    $del->close();
    header("Location: cart.php");
    exit;
}

/* ================= DELETE MULTIPLE ================= */
if(isset($_POST['delete_selected'])){
    if(!empty($_POST['selected'])){
        foreach($_POST['selected'] as $cart_id){
            $cart_id = intval($cart_id);
            $del = $conn->prepare("DELETE FROM cart WHERE id=? AND consumer_id=? AND account_type=?");
            $del->bind_param("iis",$cart_id,$user_id,$account_type);
            $del->execute();
            $del->close();
        }
    }
    header("Location: cart.php");
    exit;
}

/* ================= UPDATE QTY ================= */
if(isset($_POST['update_qty'])){
    $cart_id = intval($_POST['cart_id']);
    $qty     = intval($_POST['qty']);
    if($qty < 1) $qty = 1;

    $update = $conn->prepare("UPDATE cart SET quantity=? WHERE id=? AND consumer_id=? AND account_type=?");
    $update->bind_param("iiis",$qty,$cart_id,$user_id,$account_type);
    $update->execute();
    $update->close();

    echo "success";
    exit;
}

/* ================= CHECKOUT ================= */
if(isset($_POST['checkout_selected'])){

    if(empty($_POST['selected'])){
        header("Location: cart.php");
        exit;
    }

    $conn->begin_transaction();

    try{

        /* STEP 1: GET ALL SELECTED ITEMS FIRST */
        $selectedIds = array_map('intval', $_POST['selected']);
        $ids = implode(",", $selectedIds);

        $query = "
            SELECT cart.*, inventory.stock, inventory.type, inventory.name
            FROM cart
            JOIN inventory ON cart.product_id = inventory.id
            WHERE cart.id IN ($ids) AND cart.consumer_id = ?
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $groupedByStore = [];

        while($row = $result->fetch_assoc()){
            $groupedByStore[$row['business_id']][] = $row;
        }

        $stmt->close();

        $lastGeneratedCode = null;

        /* STEP 2: LOOP PER STORE */
        foreach($groupedByStore as $business_id => $items){

            // ONE ORDER CODE PER STORE
            $order_code = "ORD-" . strtoupper(substr(md5(uniqid()), 0, 6));
            $itemCount = count($items);

            // Save one of them for modal
            if($lastGeneratedCode === null){
                $lastGeneratedCode = $order_code;
            }

            foreach($items as $item){

               /* CHECK STOCK */
if($item['type'] === "product" && $item['quantity'] > $item['stock']){
    throw new Exception("Oops! ".$item['name']." only has ".$item['stock']." left in stock.");
}
/* INSERT ORDER ROW */
                $insert = $conn->prepare("
                    INSERT INTO orders
                    (order_code, consumer_id, business_id, product_id, quantity, price, order_type, status)
                    VALUES (?,?,?,?,?,?,?, 'Pending')
                ");

                $insert->bind_param(
                    "siiiids",
                    $order_code,
                    $user_id,
                    $business_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $item['type']
                );

                $insert->execute();
                $insert->close();

                /* UPDATE STOCK */
                if($item['type'] === "product"){
                    $newStock = $item['stock'] - $item['quantity'];

                    $updateStock = $conn->prepare("
                        UPDATE inventory SET stock=? WHERE id=?
                    ");
                    $updateStock->bind_param("ii",$newStock,$item['product_id']);
                    $updateStock->execute();
                    $updateStock->close();
                }

                /* DELETE FROM CART */
                $del = $conn->prepare("DELETE FROM cart WHERE id=?");
                $del->bind_param("i",$item['id']);
                $del->execute();
                $del->close();
            }

            $customerName = notificationDisplayName($fname ?? '', $lname ?? '', $_SESSION['username'] ?? 'A customer');
            insertNotification(
                $conn,
                (int) $business_id,
                "business_owner",
                "New Order",
                "Order " . $order_code . " was placed by " . $customerName . " (" . $itemCount . " item(s))."
            );
        }

        $conn->commit();

        header("Location: cart.php?success=1&code=".$lastGeneratedCode);
        exit;

   }catch(Exception $e){

    $conn->rollback();

    $errorMsg = urlencode($e->getMessage());

    header("Location: cart.php?error=".$errorMsg);
    exit;
}
}

/* ================= LOAD CART ================= */
$stmt = $conn->prepare("
    SELECT cart.*, inventory.name, inventory.image, inventory.stock,
           business_owner.business_name, business_owner.b_id
    FROM cart
    JOIN inventory ON cart.product_id = inventory.id
    JOIN business_owner ON cart.business_id = business_owner.b_id
    WHERE cart.consumer_id=? AND cart.account_type=?
    ORDER BY cart.business_id DESC
");
$stmt->bind_param("is",$user_id,$account_type);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while($row = $result->fetch_assoc()){
    $row['subtotal'] = $row['price'] * $row['quantity'];
    $grouped[$row['business_id']]['business_name'] = $row['business_name'];
    $grouped[$row['business_id']]['b_id'] = $row['b_id'];
    $grouped[$row['business_id']]['items'][] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>My Cart</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/css/cart.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="container">

<div class="header" style="display:flex;justify-content:space-between;align-items:center;">
<div class="title">My Cart</div>
<button class="edit-btn" onclick="toggleEdit()" id="editToggle">Edit</button>
</div>

<form method="POST" id="bulkForm">

<?php if(empty($grouped)): ?>
<div class="empty">
<i class="fa fa-shopping-cart" style="font-size:50px;color:#ccc;"></i>
<p>Your cart is empty.</p>
</div>
<?php else: ?>

<?php foreach($grouped as $business_id => $business): ?>
<div class="business-header" style="display:flex;align-items:center;gap:10px;">
<input type="checkbox"
class="business-checkbox"
data-business="<?= $business_id ?>"
onclick="toggleBusiness(this)">
<a href="businessdetails.php?id=<?= $business['b_id'] ?>">
<i class="fa fa-store"></i>
<?= htmlspecialchars($business['business_name']) ?>
</a>
</div>

<?php foreach($business['items'] as $item): ?>
<div class="swipe-wrapper">

<div class="delete-btn"
onclick="openDeleteModal(<?= $item['id'] ?>)">
Delete
</div>

<div class="cart-item swipe-item">

<input type="checkbox"
class="checkbox item-checkbox business-<?= $business_id ?>"
name="selected[]"
value="<?= $item['id'] ?>"
data-price="<?= $item['price'] ?>"
onchange="updateTotal(); syncBusinessCheckbox(<?= $business_id ?>)">

<img src="uploads/product/<?= $item['image'] ?: 'default_product.jpg' ?>" class="item-img">

<div class="info">
<div class="name"><?= htmlspecialchars($item['name']) ?></div>

<div class="price">
₱<?= number_format($item['price'],2) ?>
</div>

<?php if($item['stock'] == 0): ?>
<div class="stock-warning" style="color:#dc3545;font-size:12px;font-weight:600;margin-top:4px;">
❌ Out of stock
</div>
<?php elseif($item['stock'] <= 15): ?>
<div class="stock-warning" style="color:#dc3545;font-size:12px;font-weight:600;margin-top:4px;">
⚠ Only <?= $item['stock'] ?> left in stock!
</div>
<?php endif; ?>

<div class="qty-control">
<button class="qty-btn" type="button"
onclick="changeQty(<?= $item['id'] ?>,-1)">−</button>

<span id="qty-<?= $item['id'] ?>">
<?= $item['quantity'] ?>
</span>

<button class="qty-btn" type="button"
<?= $item['quantity'] >= $item['stock'] ? 'disabled' : '' ?>
onclick="changeQty(<?= $item['id'] ?>,1)">+</button>
</div>
</span>


<div class="subtotal" id="subtotal-<?= $item['id'] ?>">
Subtotal: ₱<?= number_format($item['subtotal'],2) ?>
</div>

</div>
</div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

</form>
</div>

<?php if(!empty($grouped)): ?>
<div class="footer">

<div id="totalWrapper" class="total">
Total: ₱<span id="totalAmount">0.00</span>
</div>

<div class="select-all-wrapper" id="selectAllWrapper">
<input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
Select All
</div>

<button class="delete-selected"
type="submit"
name="delete_selected"
form="bulkForm"
id="bulkDeleteBtn">
Delete
</button>

<button class="checkout"
id="checkoutBtn"
type="submit"
name="checkout_selected"
form="bulkForm">
Checkout
</button>

</div>
<?php endif; ?>

<!-- ORDER SUCCESS MODAL -->
<div id="orderModal" class="order-modal">
  <div class="order-modal-content">
    <h2>Your order has been confirmed</h2>
    <div class="order-code" id="orderCodeText"></div>
    <button class="order-ok-btn" onclick="closeOrderModal()">OK</button>
  </div>
</div>

<!-- STOCK ERROR MODAL -->
<div id="errorModal" class="order-modal">
  <div class="order-modal-content">
    <h3 style="color:#dc3545;">Out of Stock</h3>
    <p id="errorMessage"></p>
    <button class="order-ok-btn" onclick="closeErrorModal()">OK</button>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div id="deleteModal" class="order-modal">
  <div class="order-modal-content">
    <h3>Remove Item?</h3>
    <p>Are you sure you want to remove this item from cart?</p>
    <div class="btn-group">
    <button class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
    <button class="delete-btn-confirm" onclick="confirmDelete()">Delete</button>
</div>
  </div>
</div>

<script>
let editMode = false;
let deleteCartId = null;

function openDeleteModal(cartId){
    deleteCartId = cartId;
    document.getElementById("deleteModal").style.display = "flex";
}

function closeDeleteModal(){
    document.getElementById("deleteModal").style.display = "none";
    deleteCartId = null;
}

function confirmDelete(){
    if(deleteCartId){
        window.location.href = "cart.php?delete="+deleteCartId;
    }
}

function toggleEdit(){
    editMode = !editMode;
    document.getElementById("editToggle").innerText = editMode ? "Done" : "Edit";
    document.getElementById("checkoutBtn").style.display = editMode ? "none" : "inline-block";
    document.getElementById("bulkDeleteBtn").style.display = editMode ? "inline-block" : "none";
    document.getElementById("totalWrapper").style.display = editMode ? "none" : "block";
    document.getElementById("selectAllWrapper").style.display = editMode ? "flex" : "none";
}

/* TOTAL */
function updateTotal(){
let total = 0;
document.querySelectorAll(".item-checkbox").forEach(cb=>{
if(cb.checked){
let id = cb.value;
let qty = parseInt(document.getElementById("qty-"+id).innerText);
let price = parseFloat(cb.dataset.price);
total += price * qty;
}
});
document.getElementById("totalAmount").innerText = total.toFixed(2);
}

/* SELECT ALL */
function toggleSelectAll(){
let checked = document.getElementById("selectAll").checked;
document.querySelectorAll(".item-checkbox, .business-checkbox").forEach(cb=>{
cb.checked = checked;
});
updateTotal();
}

function toggleBusiness(businessCheckbox){
let businessId = businessCheckbox.dataset.business;
document.querySelectorAll(".business-"+businessId).forEach(cb=>{
cb.checked = businessCheckbox.checked;
});
updateTotal();
}

function syncBusinessCheckbox(businessId){
let items = document.querySelectorAll(".business-"+businessId);
let businessCheckbox = document.querySelector(".business-checkbox[data-business='"+businessId+"']");
let allChecked = true;
items.forEach(cb=>{ if(!cb.checked) allChecked=false; });
businessCheckbox.checked = allChecked;
}

/* QTY */
function changeQty(cartId, change){
    let qtyElement = document.getElementById("qty-"+cartId);
    let currentQty = parseInt(qtyElement.innerText);
    let newQty = currentQty + change;

    // IF 0 → DELETE WITH CONFIRMATION
    if(newQty <= 0){
        openDeleteModal(cartId);
        return;
    }

    fetch("cart.php",{
        method:"POST",
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`update_qty=1&cart_id=${cartId}&qty=${newQty}`
    }).then(()=>{
        qtyElement.innerText = newQty;

        let checkbox = document.querySelector(".item-checkbox[value='"+cartId+"']");
        let price = parseFloat(checkbox.dataset.price);

        document.getElementById("subtotal-"+cartId)
        .innerText = "Subtotal: ₱"+(price * newQty).toFixed(2);

        updateTotal();
    });
}

/* MOBILE SWIPE ONLY */
function enableSwipe(){

    if(window.innerWidth > 768) return;

    document.querySelectorAll('.swipe-item').forEach(item=>{

        let startX = 0;
        let currentX = 0;
        let isDragging = false;

        item.addEventListener('touchstart', function(e){
            startX = e.touches[0].clientX;
            isDragging = true;
        });

        item.addEventListener('touchmove', function(e){
            if(!isDragging) return;

            currentX = e.touches[0].clientX - startX;

            if(currentX < 0 && currentX > -120){
                item.style.transform = `translateX(${currentX}px)`;
            }
        });

        item.addEventListener('touchend', function(){
            isDragging = false;

            // Only reveal delete button, NOT modal
            if(currentX < -80){
                item.style.transform = 'translateX(-100px)';
            }else{
                item.style.transform = 'translateX(0)';
            }

            currentX = 0;
        });
    });
}

window.addEventListener('load', enableSwipe);
window.addEventListener('resize', enableSwipe);

function closeOrderModal(){
    document.getElementById("orderModal").style.display = "none";
    window.location.href = "cart.php";
}

<?php if(isset($_GET['success']) && isset($_GET['code'])): ?>
    window.addEventListener("load", function(){
        document.getElementById("orderCodeText").innerText = "<?php echo htmlspecialchars($_GET['code']); ?>";
        document.getElementById("orderModal").style.display = "flex";
    });
<?php endif; ?>

function closeErrorModal(){
    document.getElementById("errorModal").style.display = "none";
    window.location.href = "cart.php";
}

<?php if(isset($_GET['error'])): ?>
window.addEventListener("load", function(){
    document.getElementById("errorMessage").innerText = 
        "<?php echo htmlspecialchars(urldecode($_GET['error'])); ?>";
    document.getElementById("errorModal").style.display = "flex";
});
<?php endif; ?>


function openBulkModal(){
    let checked = document.querySelectorAll(".item-checkbox:checked");

    if(checked.length === 0){
        alert("Please select at least one item.");
        return;
    }

    if(confirm("Are you sure you want to delete selected items?")){
        document.getElementById("bulkForm").submit();
    }
}

</script>



<?php include 'bottom_nav.php'; ?>

</div>
</body>
</html>

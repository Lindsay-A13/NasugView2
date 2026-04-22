<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

if ($currentPage === 'home.php') {
    return;
}
?>
<style>
.mobile-back-btn{
    display:none;
}

@media (max-width:768px){
    .mobile-back-btn{
        position:fixed;
        top:14px;
        left:14px;
        width:42px;
        height:42px;
        border:none;
        border-radius:999px;
        background:rgba(255,255,255,0.96);
        color:#001a47;
        box-shadow:0 6px 18px rgba(0,0,0,.16);
        z-index:10001;
        display:flex;
        align-items:center;
        justify-content:center;
        text-decoration:none;
        font-size:18px;
    }
}
</style>

<a
    href="home.php"
    class="mobile-back-btn"
    aria-label="Go back"
    onclick="if(window.history.length > 1){ window.history.back(); return false; }"
>
    <i class="fa fa-arrow-left"></i>
</a>

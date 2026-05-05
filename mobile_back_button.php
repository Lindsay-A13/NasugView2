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
        top:12px;
        left:12px;
        width:38px;
        height:38px;
        border:none;
        border-radius:999px;
        background:rgba(255,255,255,0.94);
        color:#001a47;
        box-shadow:0 4px 14px rgba(0,0,0,.12);
        z-index:10001;
        display:flex;
        align-items:center;
        justify-content:center;
        text-decoration:none;
        font-size:16px;
    }

    .mobile-back-btn ~ .container > .topbar,
    .mobile-back-btn ~ .container > .header,
    .mobile-back-btn ~ .container > .header-row,
    .mobile-back-btn ~ .container > .page-title,
    .mobile-back-btn ~ .container > .filter-bar,
    .mobile-back-btn ~ .container > .review-top,
    .mobile-back-btn ~ .container > .business-header,
    .mobile-back-btn ~ .profile-wrap > :first-child{
        padding-left:54px !important;
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

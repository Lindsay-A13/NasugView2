<?php
session_start();

$conn = new mysqli("localhost", "root", "", "nasugview2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$admin_fullname = "User";
$designation = "Admin";

if (isset($_SESSION['user_id'])) {
    $id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT fname, lname, designation FROM negosyo_center_users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $admin_fullname = trim($row['fname'] . " " . $row['lname']);
        $designation = $row['designation'];
    }
}

$saveDir = __DIR__ . "/saved_templates/";
$saveUrl = "saved_templates/";

if (!is_dir($saveDir)) {
    mkdir($saveDir, 0777, true);
}

if (isset($_POST['save_layout'])) {
    $layout = $_POST['layout'] ?? '';
    $image = $_POST['image'] ?? '';
    $customName = trim($_POST['template_name'] ?? '');
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customName);
    $name = $safeName !== '' ? $safeName . "_" . time() : "template_" . time();

    file_put_contents($saveDir . $name . ".json", $layout);

    if (strpos($image, 'data:image/png;base64,') === 0) {
        $image = str_replace('data:image/png;base64,', '', $image);
        file_put_contents($saveDir . $name . ".png", base64_decode($image));
    }

    header("Content-Type: application/json");
    echo json_encode(["status" => "saved", "name" => $name]);
    exit;
}

if (isset($_GET['load'])) {
    $name = basename($_GET['load']);
    $file = $saveDir . $name . ".json";

    header("Content-Type: application/json");

    if (is_file($file)) {
        echo file_get_contents($file);
    } else {
        echo json_encode(["board" => [], "elements" => []]);
    }
    exit;
}

$templateFiles = glob($saveDir . "*.png") ?: [];
$templates = [];

foreach ($templateFiles as $file) {
    $base = basename($file, ".png");
    $templates[] = [
        "name" => $base,
        "preview" => $saveUrl . basename($file),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate Designer</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Great+Vibes&family=Montserrat:wght@400;500;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
:root{
    --navy:#001a47;
    --navy-deep:#00308a;
    --gold:#d4af37;
    --gold-soft:#f4d889;
    --cream:#f7f2e8;
    --secondary:#f8f9fa;
    --paper:#fffdf8;
    --panel:#ffffff;
    --ink:#1e293b;
    --muted:#64748b;
    --line:#d9e0ea;
    --shadow:0 22px 55px rgba(0,26,71,.14);
    --sidebar-width:250px;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:'Poppins',sans-serif;
    background:linear-gradient(135deg, var(--navy) 0%, var(--navy-deep) 100%);
    color:var(--ink);
}

.main-content{
    margin-left:var(--sidebar-width);
    min-height:100vh;
    padding:12px;
    background:var(--secondary);
}

body.left-panel-hidden .main-content{
    padding-top:64px;
}

.page-shell{
    display:grid;
    grid-template-columns:212px minmax(0, 1fr) 244px;
    gap:10px;
    align-items:start;
}

.page-shell.inspector-hidden{
    grid-template-columns:212px minmax(0, 1fr);
}

.page-shell.left-panel-hidden{
    grid-template-columns:minmax(0, 1fr) 244px;
}

.page-shell.left-panel-hidden.inspector-hidden{
    grid-template-columns:minmax(0, 1fr);
}

.panel{
    background:rgba(255,255,255,.9);
    border:1px solid rgba(0,26,71,.08);
    border-radius:20px;
    box-shadow:var(--shadow);
    backdrop-filter:blur(10px);
}

.panel-head{
    padding:16px 16px 8px;
}

.panel-head.compact-head{
    display:block;
}

.panel-head-main{
    min-width:0;
    flex:1;
}

.panel-head-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
}

.panel-head h1,
.panel-head h2,
.panel-head h3{
    margin:0;
}

.eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 12px;
    border-radius:999px;
    background:rgba(0,26,71,.07);
    color:var(--navy);
    font-size:12px;
    font-weight:600;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.subtitle{
    margin-top:8px;
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
}

.panel-body{
    padding:0 16px 16px;
}

.tool-group{
    margin-top:12px;
}

.tool-group:first-child{
    margin-top:0;
}

.group-label{
    display:block;
    margin-bottom:8px;
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.tool-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
}

.tool-btn,
.action-btn,
.template-card,
.align-btn{
    border:none;
    cursor:pointer;
    transition:.2s ease;
}

.tool-btn{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    justify-content:space-between;
    min-height:74px;
    padding:10px;
    border-radius:14px;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    border:1px solid rgba(0,26,71,.08);
    box-shadow:0 12px 24px rgba(0,26,71,.18);
    color:#fff;
    text-align:left;
}

.tool-btn strong{
    font-size:12px;
}

.tool-btn span{
    font-size:11px;
    color:rgba(255,255,255,.8);
    line-height:1.3;
}

.tool-btn:hover,
.template-card:hover,
.action-btn:hover,
.align-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 16px 28px rgba(0,26,71,.12);
}

.workspace{
    display:flex;
    flex-direction:column;
    gap:10px;
}

.workspace-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px;
    padding:14px 14px 0;
}

.workspace-title h2{
    font-size:22px;
    line-height:1.1;
    color:var(--navy-deep);
}

.workspace-title p{
    margin:6px 0 0;
    color:var(--muted);
    font-size:12px;
}

.workspace-actions{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    justify-content:flex-end;
}

.action-btn{
    position:relative;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-width:44px;
    height:44px;
    padding:0 14px;
    border-radius:14px;
    font-size:13px;
    font-weight:600;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    color:#fff;
    box-shadow:0 10px 22px rgba(0,26,71,.16);
}

.action-btn i{
    font-size:15px;
}

.action-btn.icon-only{
    width:44px;
    min-width:44px;
    padding:0;
}

.action-btn .btn-text{
    display:none;
}

.action-btn .btn-tooltip{
    position:absolute;
    left:50%;
    bottom:calc(100% + 10px);
    transform:translateX(-50%) translateY(4px);
    padding:8px 10px;
    border-radius:10px;
    background:rgba(7,22,38,.96);
    color:#fff;
    font-size:11px;
    font-weight:600;
    letter-spacing:.02em;
    white-space:nowrap;
    opacity:0;
    pointer-events:none;
    transition:opacity .18s ease, transform .18s ease;
    box-shadow:0 14px 26px rgba(7,22,38,.22);
    z-index:30;
}

.action-btn .btn-tooltip::after{
    content:"";
    position:absolute;
    left:50%;
    top:100%;
    width:8px;
    height:8px;
    background:rgba(7,22,38,.96);
    transform:translateX(-50%) rotate(45deg);
}

.action-btn:hover .btn-tooltip,
.action-btn:focus-visible .btn-tooltip{
    opacity:1;
    transform:translateX(-50%) translateY(0);
}

.action-btn.primary{
    background:linear-gradient(135deg, var(--navy) 0%, var(--navy-deep) 100%);
    color:#fff;
}

.action-btn.gold{
    background:linear-gradient(135deg, #f7e7b2 0%, var(--gold) 100%);
    color:#3a2b03;
}

.board-toolbar{
    margin:0 14px;
    padding:8px 10px;
    border-radius:14px;
    background:linear-gradient(180deg, #ffffff 0%, #eef3fa 100%);
    border:1px solid rgba(0,26,71,.08);
    display:flex;
    flex-wrap:wrap;
    gap:6px 10px;
    align-items:center;
}

.toolbar-cluster{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.toolbar-cluster label{
    font-size:11px;
    font-weight:700;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.06em;
}

.toolbar-cluster input[type="color"]{
    width:40px;
    height:40px;
    border:none;
    background:none;
    padding:0;
    cursor:pointer;
}

.toolbar-cluster input[type="text"]{
    width:140px;
}

.toolbar-cluster input[type="text"],
.toolbar-cluster select,
.inspector input[type="text"],
.inspector input[type="number"],
.inspector textarea,
.inspector select{
    height:40px;
    padding:0 12px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fff;
    font:inherit;
    color:var(--ink);
}

.inspector textarea{
    height:72px;
    padding:12px;
    resize:vertical;
}

.board-wrap{
    padding:0 14px 14px;
}

.board-stage{
    position:relative;
    padding:10px;
    border-radius:20px;
    background:
        linear-gradient(180deg, rgba(255,255,255,.94), rgba(238,243,250,.96)),
        repeating-linear-gradient(0deg, rgba(0,26,71,.04) 0, rgba(0,26,71,.04) 1px, transparent 1px, transparent 34px),
        repeating-linear-gradient(90deg, rgba(0,26,71,.04) 0, rgba(0,26,71,.04) 1px, transparent 1px, transparent 34px);
    border:1px solid rgba(0,26,71,.08);
    overflow:auto;
    display:flex;
    align-items:flex-start;
    justify-content:flex-start;
    min-height:340px;
}

.board-stage.is-centered{
    justify-content:center;
}

.canvas-viewport{
    position:relative;
    width:1123px;
    height:794px;
    transform-origin:top left;
    transition:width .18s ease, height .18s ease;
}

#canvas{
    position:relative;
    width:1123px;
    height:794px;
    background-color:var(--paper);
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 28px 60px rgba(15,23,42,.18);
    background-size:cover;
    background-position:center;
    transform-origin:top left;
}

#canvas::before{
    content:"";
    position:absolute;
    inset:18px;
    border:1px dashed rgba(0,26,71,.12);
    pointer-events:none;
}

.design-item{
    position:absolute;
    min-width:60px;
    min-height:30px;
    cursor:move;
    user-select:none;
}

.design-item.selected{
    box-shadow:0 0 0 2px rgba(21,66,111,.4);
}

.item-content{
    width:100%;
    height:100%;
}

.item-text .item-content{
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    line-height:1.25;
    white-space:pre-wrap;
    word-break:break-word;
    padding:4px 8px;
}

.item-image .item-content{
    overflow:hidden;
    border-radius:inherit;
}

.item-image img{
    display:block;
    width:100%;
    height:100%;
    object-fit:cover;
    pointer-events:none;
}

.rotate-handle{
    position:absolute;
    top:-34px;
    left:50%;
    width:24px;
    height:24px;
    margin-left:-12px;
    border-radius:50%;
    background:var(--navy);
    color:#fff;
    display:none;
    align-items:center;
    justify-content:center;
    font-size:12px;
    box-shadow:0 10px 24px rgba(0,26,71,.25);
    cursor:grab;
}

.design-item.selected .rotate-handle{
    display:flex;
}

.empty-state{
    padding:12px 14px;
    border:1px dashed rgba(0,26,71,.15);
    border-radius:14px;
    color:rgba(255,255,255,.88);
    background:linear-gradient(135deg, rgba(0,26,71,.92) 0%, rgba(0,48,138,.92) 100%);
    font-size:12px;
    line-height:1.5;
}

.template-list{
    display:grid;
    gap:8px;
}

.template-card{
    width:100%;
    padding:8px;
    border-radius:14px;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    border:1px solid rgba(0,26,71,.08);
    text-align:left;
}

.template-card img{
    width:100%;
    aspect-ratio:1.6/1;
    object-fit:cover;
    border-radius:10px;
    display:block;
    margin-bottom:8px;
}

.template-card strong{
    display:block;
    font-size:12px;
    color:#fff;
}

.template-card span{
    font-size:11px;
    color:rgba(255,255,255,.8);
}

.inspector{
    padding-bottom:24px;
}

.inspector .panel-head{
    padding-bottom:14px;
}

.selection-name{
    margin-top:8px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 10px;
    border-radius:999px;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    color:#fff;
    font-size:12px;
    font-weight:600;
}

.inspector-section{
    padding:0 16px;
    margin-top:14px;
}

.field{
    margin-top:10px;
}

.field:first-child{
    margin-top:0;
}

.field label{
    display:block;
    margin-bottom:6px;
    font-size:11px;
    color:var(--muted);
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.06em;
}

.field.two-col{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
}

.field.two-col > div label{
    margin-bottom:7px;
}

.range-wrap{
    display:flex;
    align-items:center;
    gap:8px;
}

.range-wrap input[type="range"]{
    flex:1;
    -webkit-appearance:none;
    appearance:none;
    height:8px;
    border-radius:999px;
    background:linear-gradient(135deg, var(--navy) 0%, var(--navy-deep) 100%);
    outline:none;
}

.range-wrap input[type="range"]::-webkit-slider-thumb{
    -webkit-appearance:none;
    appearance:none;
    width:18px;
    height:18px;
    border-radius:50%;
    background:linear-gradient(135deg, #f7e7b2 0%, var(--gold) 100%);
    border:2px solid #fff;
    box-shadow:0 4px 12px rgba(0,26,71,.25);
    cursor:pointer;
}

.range-wrap input[type="range"]::-moz-range-track{
    height:8px;
    border:none;
    border-radius:999px;
    background:linear-gradient(135deg, var(--navy) 0%, var(--navy-deep) 100%);
}

.range-wrap input[type="range"]::-moz-range-thumb{
    width:18px;
    height:18px;
    border-radius:50%;
    background:linear-gradient(135deg, #f7e7b2 0%, var(--gold) 100%);
    border:2px solid #fff;
    box-shadow:0 4px 12px rgba(0,26,71,.25);
    cursor:pointer;
}

.align-row{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:6px;
}

.align-btn{
    height:36px;
    border-radius:10px;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    color:#fff;
    font-weight:700;
    font-size:12px;
}

.align-btn.active{
    background:linear-gradient(135deg, #f7e7b2 0%, var(--gold) 100%);
    color:#3a2b03;
    box-shadow:0 10px 20px rgba(212,175,55,.28);
}

.hidden{
    display:none !important;
}

.footer-note{
    margin:14px 16px 0;
    padding:12px 14px;
    border-radius:14px;
    background:linear-gradient(180deg, rgba(212,175,55,.12), rgba(212,175,55,.04));
    color:#5e4a0a;
    font-size:12px;
    line-height:1.5;
}

.shortcut-note{
    margin:6px 16px 0;
    color:var(--muted);
    font-size:11px;
}

.zoom-controls{
    display:flex;
    align-items:center;
    gap:8px;
}

.zoom-badge{
    min-width:72px;
    height:36px;
    padding:0 12px;
    border-radius:12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    border:1px solid var(--line);
    color:#fff;
    font-size:12px;
    font-weight:700;
    letter-spacing:.04em;
}

.collapsible{
    margin-top:10px;
    border:1px solid rgba(0,26,71,.08);
    border-radius:14px;
    background:linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(238,243,250,.95) 100%);
    overflow:hidden;
}

.board-toolbar .collapsible{
    margin-top:0;
}

.inspector-section.collapsible{
    padding:0;
}

.collapsible summary{
    list-style:none;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    font-size:12px;
    font-weight:700;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    color:#fff;
}

.collapsible summary::-webkit-details-marker{
    display:none;
}

.collapsible summary::after{
    content:"+";
    font-size:16px;
    color:rgba(255,255,255,.78);
}

.collapsible[open] summary::after{
    content:"-";
}

.collapsible-body{
    padding:0 12px 12px;
}

.collapsible-body .tool-grid,
.collapsible-body .template-list{
    margin-top:2px;
}

.panel-head h1{
    font-size:22px;
}

.panel-head h3{
    font-size:18px;
}

.panel-toggle{
    width:42px;
    height:42px;
    min-width:42px;
    border:none;
    border-radius:12px;
    background:linear-gradient(135deg, rgba(0,26,71,.94) 0%, rgba(0,48,138,.94) 100%);
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.2s ease;
    flex-shrink:0;
}

.panel-toggle:hover{
    transform:translateY(-1px);
    box-shadow:0 12px 24px rgba(0,26,71,.12);
}

.panel-toggle-float{
    position:fixed;
    top:14px;
    left:calc(var(--sidebar-width) + 14px);
    z-index:1200;
    display:none;
    box-shadow:0 12px 24px rgba(0,26,71,.12);
    border-radius:12px;
}

.page-shell.left-panel-hidden ~ .panel-toggle-float,
body.left-panel-hidden .panel-toggle-float{
    display:inline-flex;
}

.left-tools-panel{
    transition:opacity .2s ease, transform .2s ease;
}

body.left-panel-hidden .left-tools-panel{
    display:none;
}

.inspector-panel{
    transition:opacity .2s ease, transform .2s ease;
}

body.inspector-hidden .inspector-panel{
    display:none;
}

.context-menu{
    position:fixed;
    min-width:220px;
    padding:8px;
    border-radius:18px;
    border:1px solid rgba(0,26,71,.12);
    background:rgba(255,255,255,.98);
    box-shadow:0 22px 55px rgba(15,23,42,.18);
    z-index:5000;
    display:none;
}

.context-menu.open{
    display:block;
}

.context-title{
    padding:8px 10px 10px;
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.context-item{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:11px 12px;
    border:none;
    border-radius:12px;
    background:transparent;
    color:var(--ink);
    font:inherit;
    text-align:left;
    cursor:pointer;
}

.context-item:hover{
    background:#eef3fa;
    color:var(--navy);
}

.context-item span{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
}

.context-divider{
    height:1px;
    margin:6px 4px;
    background:rgba(0,26,71,.08);
}

body.exporting .rotate-handle,
body.exporting .design-item.selected{
    box-shadow:none !important;
}

body.exporting #canvas::before{
    display:none;
}

@media (max-width:1500px){
    .page-shell{
        grid-template-columns:200px minmax(0, 1fr);
    }

    .page-shell.inspector-hidden{
        grid-template-columns:200px minmax(0, 1fr);
    }

    .page-shell.left-panel-hidden{
        grid-template-columns:1fr;
    }

    .page-shell.left-panel-hidden.inspector-hidden{
        grid-template-columns:1fr;
    }

    .inspector{
        grid-column:1 / -1;
    }
}

@media (max-width:1080px){
    .main-content{
        margin-left:0;
        padding:12px;
    }

    body.left-panel-hidden .main-content{
        padding-top:64px;
    }

    .page-shell{
        grid-template-columns:1fr;
    }

    .page-shell.inspector-hidden{
        grid-template-columns:1fr;
    }

    .page-shell.left-panel-hidden{
        grid-template-columns:1fr;
    }

    .page-shell.left-panel-hidden.inspector-hidden{
        grid-template-columns:1fr;
    }

    .workspace-header{
        flex-direction:column;
    }

    .board-stage{
        padding:16px;
    }

    .panel-toggle-float{
        top:18px;
        left:18px;
        border-radius:12px;
    }
}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="page-shell" id="pageShell">
        <aside class="panel left-tools-panel" id="leftToolsPanel">
            <div class="panel-head compact-head">
                <div class="panel-head-row">
                    <span class="eyebrow">Drag and Drop</span>
                    <button type="button" class="panel-toggle" id="hideLeftPanelBtn" aria-label="Hide certificate builder">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="panel-head-main">
                    <h1>Certificate Builder</h1>
                    <p class="subtitle">Compact layout with quick tools first, extra options hidden until you need them.</p>
                </div>
            </div>
            <div class="panel-body">
                <div class="tool-group">
                    <span class="group-label">Quick Start</span>
                    <div class="tool-grid">
                        <button class="tool-btn" data-add="title">
                            <strong>Title</strong>
                            <span>Large certificate heading</span>
                        </button>
                        <button class="tool-btn" data-add="recipient">
                            <strong>Recipient</strong>
                            <span>Name placeholder line</span>
                        </button>
                        <button class="tool-btn" data-add="body">
                            <strong>Body Text</strong>
                            <span>Event description paragraph</span>
                        </button>
                        <button class="tool-btn" data-add="signature">
                            <strong>Signature</strong>
                            <span>Role and signer block</span>
                        </button>
                    </div>
                </div>

                <details class="collapsible tool-group">
                    <summary>More Elements</summary>
                    <div class="collapsible-body">
                        <div class="tool-grid">
                            <button class="tool-btn" data-add="image">
                                <strong>Image</strong>
                                <span>Upload logo or seal</span>
                            </button>
                            <button class="tool-btn" data-add="border">
                                <strong>Border</strong>
                                <span>Elegant frame around page</span>
                            </button>
                            <button class="tool-btn" data-add="rectangle">
                                <strong>Rectangle</strong>
                                <span>Panels and highlights</span>
                            </button>
                            <button class="tool-btn" data-add="circle">
                                <strong>Circle</strong>
                                <span>Badge or stamp base</span>
                            </button>
                            <button class="tool-btn" data-add="line">
                                <strong>Line</strong>
                                <span>Divider or underline</span>
                            </button>
                        </div>
                    </div>
                </details>

                <details class="collapsible tool-group">
                    <summary>Saved Templates</summary>
                    <div class="collapsible-body">
                        <div class="template-list" id="templateList">
                            <?php if (empty($templates)): ?>
                                <div class="empty-state">Your saved designs will show here after you click <strong>Save Template</strong>.</div>
                            <?php else: ?>
                                <?php foreach ($templates as $template): ?>
                                    <button class="template-card" type="button" data-template="<?= htmlspecialchars($template['name']) ?>">
                                        <img src="<?= htmlspecialchars($template['preview']) ?>" alt="<?= htmlspecialchars($template['name']) ?>">
                                        <strong><?= htmlspecialchars(str_replace('_', ' ', $template['name'])) ?></strong>
                                        <span>Click to load this layout</span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>
            </div>
        </aside>

        <main class="panel workspace">
            <div class="workspace-header">
                <div class="workspace-title">
                    <span class="eyebrow">Certificate Studio</span>
                    <h2>Build your layout by dragging pieces into place</h2>
                    <p>Double-click text to edit it on the canvas, or use the inspector for more exact styling.</p>
                </div>
                <div class="workspace-actions">
                    <button class="action-btn icon-only" type="button" id="undoBtn" aria-label="Undo">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i>
                        <span class="btn-tooltip">Undo (Ctrl + Z)</span>
                    </button>
                    <button class="action-btn icon-only" type="button" id="redoBtn" aria-label="Redo">
                        <i class="fas fa-rotate-right" aria-hidden="true"></i>
                        <span class="btn-tooltip">Redo (Ctrl + Y)</span>
                    </button>
                    <button class="action-btn icon-only" type="button" id="duplicateBtn" aria-label="Duplicate">
                        <i class="fas fa-clone" aria-hidden="true"></i>
                        <span class="btn-tooltip">Duplicate (Ctrl + D)</span>
                    </button>
                    <button class="action-btn icon-only" type="button" id="deleteBtn" aria-label="Delete">
                        <i class="fas fa-trash" aria-hidden="true"></i>
                        <span class="btn-tooltip">Delete (Del)</span>
                    </button>
                    <button class="action-btn icon-only gold" type="button" id="saveBtn" aria-label="Save template">
                        <i class="fas fa-floppy-disk" aria-hidden="true"></i>
                        <span class="btn-tooltip">Save Template (Ctrl + S)</span>
                    </button>
                    <button class="action-btn icon-only primary" type="button" id="downloadBtn" aria-label="Download PNG">
                        <i class="fas fa-download" aria-hidden="true"></i>
                        <span class="btn-tooltip">Download PNG</span>
                    </button>
                </div>
            </div>

            <div class="board-toolbar">
                <div class="toolbar-cluster">
                    <label for="templateName">Template Name</label>
                    <input type="text" id="templateName" placeholder="My certificate layout">
                </div>
                <div class="toolbar-cluster">
                    <label>Zoom</label>
                    <div class="zoom-controls">
                        <button class="action-btn icon-only" type="button" id="zoomOutBtn" aria-label="Zoom out">
                            <i class="fas fa-magnifying-glass-minus" aria-hidden="true"></i>
                            <span class="btn-tooltip">Zoom Out</span>
                        </button>
                        <button class="action-btn" type="button" id="zoomFitBtn" aria-label="Fit to screen">
                            <i class="fas fa-expand" aria-hidden="true"></i>
                            <span class="btn-text">Fit</span>
                            <span class="btn-tooltip">Fit to Screen</span>
                        </button>
                        <button class="action-btn icon-only" type="button" id="zoomInBtn" aria-label="Zoom in">
                            <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i>
                            <span class="btn-tooltip">Zoom In</span>
                        </button>
                        <div class="zoom-badge" id="zoomValue">100%</div>
                    </div>
                </div>
                <details class="collapsible">
                    <summary>More Board Options</summary>
                    <div class="collapsible-body">
                        <div class="toolbar-cluster">
                            <label for="canvasColor">Canvas Color</label>
                            <input type="color" id="canvasColor" value="#fffdf8">
                        </div>
                        <div class="toolbar-cluster" style="margin-top:8px;">
                            <button class="action-btn icon-only" type="button" id="backgroundImageBtn" aria-label="Add background image">
                                <i class="fas fa-image" aria-hidden="true"></i>
                                <span class="btn-tooltip">Background Image</span>
                            </button>
                            <button class="action-btn icon-only" type="button" id="clearBackgroundBtn" aria-label="Clear background">
                                <i class="fas fa-eraser" aria-hidden="true"></i>
                                <span class="btn-tooltip">Clear Background</span>
                            </button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="board-wrap">
                <div class="board-stage">
                    <div class="canvas-viewport" id="canvasViewport">
                        <div id="canvas"></div>
                    </div>
                </div>
            </div>
            <div class="shortcut-note">Shortcuts: Ctrl + S save, Ctrl + Z undo, Ctrl + Y or Ctrl + Shift + Z redo, Ctrl + Plus zoom in, Ctrl + Minus zoom out, Delete remove, right-click any element for layer options.</div>
        </main>

        <aside class="panel inspector inspector-panel" id="inspectorPanel">
            <div class="panel-head">
                <span class="eyebrow">Inspector</span>
                <h3>Selected Element</h3>
                <div class="selection-name" id="selectionLabel">Nothing selected</div>
            </div>

            <div class="inspector-section">
                <div class="empty-state" id="inspectorEmpty">Pick any element on the canvas to edit text, colors, size, spacing, and position.</div>
            </div>

            <div id="inspectorFields" class="hidden">
                <details class="collapsible inspector-section" id="textFields" open>
                    <summary>Text Style</summary>
                    <div class="collapsible-body">
                        <div class="field">
                            <label for="textContent">Text</label>
                            <textarea id="textContent"></textarea>
                        </div>
                        <div class="field">
                            <label for="fontFamily">Font Family</label>
                            <select id="fontFamily">
                                <option value="'Cormorant Garamond', serif">Cormorant Garamond</option>
                                <option value="'Poppins', sans-serif">Poppins</option>
                                <option value="'Montserrat', sans-serif">Montserrat</option>
                                <option value="'Great Vibes', cursive">Great Vibes</option>
                            </select>
                        </div>
                        <div class="field two-col">
                            <div>
                                <label for="fontSize">Font Size</label>
                                <input type="number" id="fontSize" min="10" max="120">
                            </div>
                            <div>
                                <label for="fontWeight">Weight</label>
                                <select id="fontWeight">
                                    <option value="400">Regular</option>
                                    <option value="500">Medium</option>
                                    <option value="600">Semibold</option>
                                    <option value="700">Bold</option>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label>Text Align</label>
                            <div class="align-row">
                                <button class="align-btn" type="button" data-align="left">Left</button>
                                <button class="align-btn" type="button" data-align="center">Center</button>
                                <button class="align-btn" type="button" data-align="right">Right</button>
                            </div>
                        </div>
                        <div class="field">
                            <label for="textColor">Text Color</label>
                            <input type="color" id="textColor" value="#001a47">
                        </div>
                    </div>
                </details>

                <details class="collapsible inspector-section" id="shapeFields">
                    <summary>Shape Style</summary>
                    <div class="collapsible-body">
                        <div class="field two-col">
                            <div>
                                <label for="fillColor">Fill</label>
                                <input type="color" id="fillColor" value="#d4af37">
                            </div>
                            <div>
                                <label for="borderColor">Border</label>
                                <input type="color" id="borderColor" value="#d4af37">
                            </div>
                        </div>
                        <div class="field two-col">
                            <div>
                                <label for="borderWidth">Border Width</label>
                                <input type="number" id="borderWidth" min="0" max="20">
                            </div>
                            <div>
                                <label for="cornerRadius">Radius</label>
                                <input type="number" id="cornerRadius" min="0" max="500">
                            </div>
                        </div>
                    </div>
                </details>

                <details class="collapsible inspector-section" id="imageFields">
                    <summary>Image Options</summary>
                    <div class="collapsible-body">
                        <div class="field">
                            <button class="action-btn" type="button" id="replaceImageBtn">Replace Image</button>
                        </div>
                    </div>
                </details>

                <details class="collapsible inspector-section" open>
                    <summary>Transform</summary>
                    <div class="collapsible-body">
                        <div class="field">
                            <label for="opacityRange">Opacity</label>
                            <div class="range-wrap">
                                <input type="range" id="opacityRange" min="0" max="1" step="0.05">
                                <span id="opacityValue">1</span>
                            </div>
                        </div>
                        <div class="field">
                            <label for="rotationRange">Rotation</label>
                            <div class="range-wrap">
                                <input type="range" id="rotationRange" min="-180" max="180" step="1">
                                <span id="rotationValue">0 deg</span>
                            </div>
                        </div>
                        <div class="field two-col">
                            <div>
                                <label for="posX">X</label>
                                <input type="number" id="posX">
                            </div>
                            <div>
                                <label for="posY">Y</label>
                                <input type="number" id="posY">
                            </div>
                        </div>
                        <div class="field two-col">
                            <div>
                                <label for="boxWidth">Width</label>
                                <input type="number" id="boxWidth" min="20">
                            </div>
                            <div>
                                <label for="boxHeight">Height</label>
                                <input type="number" id="boxHeight" min="20">
                            </div>
                        </div>
                    </div>
                </details>

                <details class="collapsible inspector-section">
                    <summary>Layer</summary>
                    <div class="collapsible-body">
                        <div class="field">
                            <div class="align-row">
                                <button class="align-btn" type="button" id="bringFrontBtn">Front</button>
                                <button class="align-btn" type="button" id="bringForwardBtn">Up</button>
                                <button class="align-btn" type="button" id="sendBackwardBtn">Down</button>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            <div class="footer-note">
                Tip: use the left panel for quick certificate blocks, then drag items on the page and polish them here.
            </div>
        </aside>
    </div>
    <button type="button" class="panel-toggle panel-toggle-float" id="showLeftPanelBtn" aria-label="Show certificate builder">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
</div>

<input type="file" id="imageInput" accept="image/*" class="hidden">
<input type="file" id="backgroundInput" accept="image/*" class="hidden">
<div id="contextMenu" class="context-menu">
    <div class="context-title">Element Options</div>
    <button type="button" class="context-item" data-menu-action="bring-front">Bring to front <span>Top</span></button>
    <button type="button" class="context-item" data-menu-action="bring-forward">Bring forward <span>Up</span></button>
    <button type="button" class="context-item" data-menu-action="send-backward">Send backward <span>Down</span></button>
    <button type="button" class="context-item" data-menu-action="send-back">Send to back <span>Bottom</span></button>
    <div class="context-divider"></div>
    <button type="button" class="context-item" data-menu-action="duplicate">Duplicate <span>Ctrl+D</span></button>
    <button type="button" class="context-item" data-menu-action="delete">Delete <span>Del</span></button>
</div>

<script>
const canvas = document.getElementById('canvas');
const canvasViewport = document.getElementById('canvasViewport');
const boardStage = document.querySelector('.board-stage');
const pageShell = document.getElementById('pageShell');
const inspectorPanel = document.getElementById('inspectorPanel');
const hideLeftPanelBtn = document.getElementById('hideLeftPanelBtn');
const showLeftPanelBtn = document.getElementById('showLeftPanelBtn');
const templateNameInput = document.getElementById('templateName');
const imageInput = document.getElementById('imageInput');
const backgroundInput = document.getElementById('backgroundInput');
const selectionLabel = document.getElementById('selectionLabel');
const inspectorEmpty = document.getElementById('inspectorEmpty');
const inspectorFields = document.getElementById('inspectorFields');
const textFields = document.getElementById('textFields');
const shapeFields = document.getElementById('shapeFields');
const imageFields = document.getElementById('imageFields');
const textContent = document.getElementById('textContent');
const fontFamily = document.getElementById('fontFamily');
const fontSize = document.getElementById('fontSize');
const fontWeight = document.getElementById('fontWeight');
const textColor = document.getElementById('textColor');
const fillColor = document.getElementById('fillColor');
const borderColor = document.getElementById('borderColor');
const borderWidth = document.getElementById('borderWidth');
const cornerRadius = document.getElementById('cornerRadius');
const opacityRange = document.getElementById('opacityRange');
const opacityValue = document.getElementById('opacityValue');
const rotationRange = document.getElementById('rotationRange');
const rotationValue = document.getElementById('rotationValue');
const posX = document.getElementById('posX');
const posY = document.getElementById('posY');
const boxWidth = document.getElementById('boxWidth');
const boxHeight = document.getElementById('boxHeight');
const canvasColor = document.getElementById('canvasColor');
const alignButtons = [...document.querySelectorAll('[data-align]')];
const contextMenu = document.getElementById('contextMenu');
const zoomValue = document.getElementById('zoomValue');

let selectedItem = null;
let historyStack = [];
let redoStack = [];
let highestZ = 10;
let pendingImageMode = 'element';
const BASE_CANVAS_WIDTH = 1123;
const BASE_CANVAS_HEIGHT = 794;
let currentCanvasScale = 1;
let fitCanvasScale = 1;
let userZoomLevel = 1;
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 2.5;
const ZOOM_STEP = 0.1;
const LEFT_PANEL_STORAGE_KEY = 'certificate_left_panel_hidden';

const presets = {
    title: {
        type: 'text',
        x: 220,
        y: 92,
        width: 680,
        height: 90,
        rotation: 0,
        zIndex: 20,
        opacity: 1,
        html: 'Certificate of Appreciation',
        styles: {
            fontFamily: "'Cormorant Garamond', serif",
            fontSize: '44px',
            fontWeight: '700',
            color: '#001a47',
            textAlign: 'center',
            backgroundColor: 'transparent',
            borderRadius: '0px',
            borderWidth: '0px',
            borderColor: '#001a47'
        }
    },
    recipient: {
        type: 'text',
        x: 250,
        y: 270,
        width: 620,
        height: 88,
        rotation: 0,
        zIndex: 21,
        opacity: 1,
        html: 'Juan Dela Cruz',
        styles: {
            fontFamily: "'Great Vibes', cursive",
            fontSize: '52px',
            fontWeight: '400',
            color: '#c2971f',
            textAlign: 'center',
            backgroundColor: 'transparent',
            borderRadius: '0px',
            borderWidth: '0px',
            borderColor: '#c2971f'
        }
    },
    body: {
        type: 'text',
        x: 190,
        y: 375,
        width: 740,
        height: 100,
        rotation: 0,
        zIndex: 22,
        opacity: 1,
        html: 'This certificate is proudly presented for outstanding participation and valuable contribution to the program.',
        styles: {
            fontFamily: "'Poppins', sans-serif",
            fontSize: '18px',
            fontWeight: '400',
            color: '#334155',
            textAlign: 'center',
            backgroundColor: 'transparent',
            borderRadius: '0px',
            borderWidth: '0px',
            borderColor: '#334155'
        }
    },
    signature: {
        type: 'text',
        x: 740,
        y: 625,
        width: 220,
        height: 74,
        rotation: 0,
        zIndex: 23,
        opacity: 1,
        html: 'Maria Santos<br><span style="font-size:14px;">Program Head</span>',
        styles: {
            fontFamily: "'Poppins', sans-serif",
            fontSize: '22px',
            fontWeight: '600',
            color: '#001a47',
            textAlign: 'center',
            backgroundColor: 'transparent',
            borderRadius: '0px',
            borderWidth: '0px',
            borderColor: '#001a47'
        }
    },
    rectangle: {
        type: 'shape',
        x: 100,
        y: 100,
        width: 240,
        height: 120,
        rotation: 0,
        zIndex: 15,
        opacity: 1,
        html: '',
        styles: {
            backgroundColor: '#d4af37',
            borderRadius: '18px',
            borderWidth: '0px',
            borderColor: '#d4af37'
        }
    },
    circle: {
        type: 'shape',
        x: 130,
        y: 130,
        width: 140,
        height: 140,
        rotation: 0,
        zIndex: 15,
        opacity: 1,
        html: '',
        styles: {
            backgroundColor: '#001a47',
            borderRadius: '999px',
            borderWidth: '0px',
            borderColor: '#001a47'
        }
    },
    line: {
        type: 'shape',
        x: 320,
        y: 348,
        width: 480,
        height: 4,
        rotation: 0,
        zIndex: 14,
        opacity: 1,
        html: '',
        styles: {
            backgroundColor: '#d4af37',
            borderRadius: '999px',
            borderWidth: '0px',
            borderColor: '#d4af37'
        }
    },
    border: {
        type: 'shape',
        x: 26,
        y: 26,
        width: 1071,
        height: 742,
        rotation: 0,
        zIndex: 1,
        opacity: 1,
        html: '',
        lockedFill: true,
        styles: {
            backgroundColor: 'transparent',
            borderRadius: '0px',
            borderWidth: '8px',
            borderColor: '#d4af37'
        }
    },
    image: {
        type: 'image',
        x: 86,
        y: 90,
        width: 140,
        height: 140,
        rotation: 0,
        zIndex: 18,
        opacity: 1,
        src: '',
        html: '',
        styles: {
            backgroundColor: 'transparent',
            borderRadius: '14px',
            borderWidth: '0px',
            borderColor: '#001a47'
        }
    }
};

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function deepClone(value) {
    return JSON.parse(JSON.stringify(value));
}

function snap(value) {
    return Math.round(value / 5) * 5;
}

function uid() {
    return 'item_' + Math.random().toString(36).slice(2, 10);
}

function getCanvasWidth() {
    return BASE_CANVAS_WIDTH;
}

function getCanvasHeight() {
    return BASE_CANVAS_HEIGHT;
}

function updateZoomBadge() {
    zoomValue.textContent = `${Math.round(userZoomLevel * 100)}%`;
}

function applyCanvasScale() {
    const style = window.getComputedStyle(boardStage);
    const horizontalPadding = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
    const verticalPadding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
    const availableWidth = Math.max(320, boardStage.clientWidth - horizontalPadding);
    const viewportTop = boardStage.getBoundingClientRect().top;
    const availableHeight = Math.max(280, window.innerHeight - viewportTop - 36 - verticalPadding);

    fitCanvasScale = Math.min(
        availableWidth / BASE_CANVAS_WIDTH,
        availableHeight / BASE_CANVAS_HEIGHT,
        1
    );
    currentCanvasScale = fitCanvasScale * userZoomLevel;

    canvasViewport.style.width = `${Math.round(BASE_CANVAS_WIDTH * currentCanvasScale)}px`;
    canvasViewport.style.height = `${Math.round(BASE_CANVAS_HEIGHT * currentCanvasScale)}px`;
    canvas.style.transform = `scale(${currentCanvasScale})`;
    boardStage.style.minHeight = `${Math.round(BASE_CANVAS_HEIGHT * currentCanvasScale) + verticalPadding}px`;
    boardStage.classList.toggle('is-centered', canvasViewport.offsetWidth <= availableWidth + 1);
    boardStage.scrollLeft = Math.max(0, Math.min(boardStage.scrollLeft, boardStage.scrollWidth - boardStage.clientWidth));
    boardStage.scrollTop = Math.max(0, Math.min(boardStage.scrollTop, boardStage.scrollHeight - boardStage.clientHeight));
    updateZoomBadge();
}

function setZoom(level) {
    userZoomLevel = clamp(Math.round(level * 100) / 100, MIN_ZOOM, MAX_ZOOM);
    applyCanvasScale();
}

function zoomIn() {
    setZoom(userZoomLevel + ZOOM_STEP);
}

function zoomOut() {
    setZoom(userZoomLevel - ZOOM_STEP);
}

function zoomToFit() {
    setZoom(1);
    boardStage.scrollLeft = 0;
    boardStage.scrollTop = 0;
}

function setLeftPanelHidden(hidden) {
    document.body.classList.toggle('left-panel-hidden', hidden);
    pageShell.classList.toggle('left-panel-hidden', hidden);
    localStorage.setItem(LEFT_PANEL_STORAGE_KEY, hidden ? '1' : '0');
    applyCanvasScale();
}

function setInspectorHidden(hidden) {
    document.body.classList.toggle('inspector-hidden', hidden);
    pageShell.classList.toggle('inspector-hidden', hidden);
    if (inspectorPanel) {
        inspectorPanel.setAttribute('aria-hidden', hidden ? 'true' : 'false');
    }
    applyCanvasScale();
}

function readFileAsDataURL(file, callback) {
    const reader = new FileReader();
    reader.onload = () => callback(reader.result);
    reader.readAsDataURL(file);
}

function getSelectionName(item) {
    if (!item) {
        return 'Nothing selected';
    }

    const type = item.dataset.type;
    if (type === 'text') return 'Text block';
    if (type === 'image') return 'Image';
    return 'Shape';
}

function createItem(data, shouldSave = true) {
    const item = document.createElement('div');
    item.className = `design-item item-${data.type}`;
    item.dataset.id = data.id || uid();
    item.dataset.type = data.type;
    item.dataset.rotation = data.rotation || 0;
    item.dataset.lockedFill = data.lockedFill ? '1' : '0';
    item.style.left = `${data.x || 0}px`;
    item.style.top = `${data.y || 0}px`;
    item.style.width = `${data.width || 160}px`;
    item.style.height = `${data.height || 80}px`;
    item.style.opacity = data.opacity ?? 1;
    item.style.zIndex = data.zIndex || ++highestZ;

    const content = document.createElement('div');
    content.className = 'item-content';
    item.appendChild(content);

    if (data.type === 'image') {
        const img = document.createElement('img');
        img.src = data.src || 'assets/nasugviewlogoblue.png';
        content.appendChild(img);
    } else if (data.type === 'text') {
        content.innerHTML = data.html || 'Edit text';
    }

    applyStyles(item, data.styles || {});

    const rotateHandle = document.createElement('div');
    rotateHandle.className = 'rotate-handle';
    rotateHandle.innerHTML = '&#8635;';
    item.appendChild(rotateHandle);

    canvas.appendChild(item);
    bindItem(item);
    setRotation(item, Number(item.dataset.rotation || 0));
    highestZ = Math.max(highestZ, Number(item.style.zIndex || 1));

    if (shouldSave) {
        selectItem(item);
        saveHistory();
    }

    return item;
}

function normalizeLegacyElement(item) {
    const probe = document.createElement('div');
    probe.style.cssText = item.style || '';

    return {
        id: uid(),
        type: item.tag === 'IMG' ? 'image' : 'text',
        x: Number(item.x || 0),
        y: Number(item.y || 0),
        width: parseInt(probe.style.width || '180', 10),
        height: parseInt(probe.style.height || (item.tag === 'IMG' ? '180' : '80'), 10),
        rotation: Number(item.rotate || 0),
        zIndex: Number(probe.style.zIndex || ++highestZ),
        opacity: parseFloat(probe.style.opacity || 1),
        src: item.src || '',
        html: item.html || 'Edit text',
        styles: {
            fontFamily: probe.style.fontFamily || "'Poppins', sans-serif",
            fontSize: probe.style.fontSize || '18px',
            fontWeight: probe.style.fontWeight || '400',
            color: probe.style.color || '#001a47',
            textAlign: probe.style.textAlign || 'center',
            backgroundColor: probe.style.backgroundColor || 'transparent',
            borderRadius: probe.style.borderRadius || '0px',
            borderWidth: probe.style.borderWidth || '0px',
            borderColor: probe.style.borderColor || '#001a47'
        }
    };
}

function applyStyles(item, styles) {
    const content = item.querySelector('.item-content');
    const type = item.dataset.type;

    if (type === 'text') {
        content.style.fontFamily = styles.fontFamily || "'Poppins', sans-serif";
        content.style.fontSize = styles.fontSize || '18px';
        content.style.fontWeight = styles.fontWeight || '400';
        content.style.color = styles.color || '#001a47';
        content.style.textAlign = styles.textAlign || 'center';
        content.style.backgroundColor = styles.backgroundColor || 'transparent';
        content.style.borderRadius = styles.borderRadius || '0px';
        content.style.borderStyle = 'solid';
        content.style.borderWidth = styles.borderWidth || '0px';
        content.style.borderColor = styles.borderColor || 'transparent';
    } else {
        content.style.backgroundColor = styles.backgroundColor || 'transparent';
        content.style.borderRadius = styles.borderRadius || '0px';
        content.style.borderStyle = 'solid';
        content.style.borderWidth = styles.borderWidth || '0px';
        content.style.borderColor = styles.borderColor || 'transparent';
    }

    if (type === 'image') {
        item.style.borderRadius = styles.borderRadius || '14px';
        item.style.overflow = 'hidden';
        content.style.borderRadius = 'inherit';
        item.style.border = `${styles.borderWidth || '0px'} solid ${styles.borderColor || 'transparent'}`;
        content.style.backgroundColor = 'transparent';
    } else {
        item.style.border = 'none';
    }
}

function bindItem(item) {
    const content = item.querySelector('.item-content');
    const rotateHandle = item.querySelector('.rotate-handle');

    item.addEventListener('mousedown', (event) => {
        if (event.target.closest('.rotate-handle')) {
            return;
        }
        selectItem(item);
    });

    item.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        event.stopPropagation();
        selectItem(item);
        openContextMenu(event.clientX, event.clientY);
    });

    item.addEventListener('dblclick', () => {
        if (item.dataset.type !== 'text') {
            return;
        }

        selectItem(item);
        content.contentEditable = 'true';
        content.focus();
        document.execCommand('selectAll', false, null);
    });

    content.addEventListener('blur', () => {
        if (item.dataset.type === 'text') {
            content.contentEditable = 'false';
            syncInspector();
            saveHistory();
        }
    });

    content.addEventListener('input', () => {
        if (item.dataset.type === 'text' && selectedItem === item) {
            textContent.value = content.innerText;
        }
    });

    interact(item)
        .draggable({
            ignoreFrom: '[contenteditable="true"], .rotate-handle',
            listeners: {
                move(event) {
                    const left = snap(parseFloat(item.style.left || 0) + (event.dx / currentCanvasScale));
                    const top = snap(parseFloat(item.style.top || 0) + (event.dy / currentCanvasScale));
                    item.style.left = `${clamp(left, 0, getCanvasWidth() - item.offsetWidth)}px`;
                    item.style.top = `${clamp(top, 0, getCanvasHeight() - item.offsetHeight)}px`;
                    syncInspector();
                },
                end() {
                    saveHistory();
                }
            }
        })
        .resizable({
            edges: { left: true, right: true, bottom: true, top: true },
            listeners: {
                move(event) {
                    let width = clamp(event.rect.width / currentCanvasScale, 30, getCanvasWidth());
                    let height = clamp(event.rect.height / currentCanvasScale, 20, getCanvasHeight());
                    let left = snap(parseFloat(item.style.left || 0) + (event.deltaRect.left / currentCanvasScale));
                    let top = snap(parseFloat(item.style.top || 0) + (event.deltaRect.top / currentCanvasScale));

                    left = clamp(left, 0, getCanvasWidth() - width);
                    top = clamp(top, 0, getCanvasHeight() - height);

                    item.style.width = `${snap(width)}px`;
                    item.style.height = `${snap(height)}px`;
                    item.style.left = `${left}px`;
                    item.style.top = `${top}px`;
                    syncInspector();
                },
                end() {
                    saveHistory();
                }
            },
            modifiers: [
                interact.modifiers.restrictSize({
                    min: { width: 30, height: 20 }
                })
            ]
        });

    let rotating = false;

    rotateHandle.addEventListener('mousedown', (event) => {
        event.stopPropagation();
        selectItem(item);
        rotating = true;
    });

    document.addEventListener('mousemove', (event) => {
        if (!rotating || selectedItem !== item) {
            return;
        }

        const rect = item.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        const angle = Math.atan2(event.clientY - cy, event.clientX - cx) * (180 / Math.PI) + 90;
        setRotation(item, Math.round(angle));
        syncInspector();
    });

    document.addEventListener('mouseup', () => {
        if (rotating) {
            rotating = false;
            saveHistory();
        }
    });
}

function setRotation(item, angle) {
    item.dataset.rotation = angle;
    item.style.transform = `rotate(${angle}deg)`;
}

function openContextMenu(x, y) {
    const menuWidth = 220;
    const menuHeight = 260;
    const maxX = window.innerWidth - menuWidth - 16;
    const maxY = window.innerHeight - menuHeight - 16;
    contextMenu.style.left = `${Math.max(12, Math.min(x, maxX))}px`;
    contextMenu.style.top = `${Math.max(12, Math.min(y, maxY))}px`;
    contextMenu.classList.add('open');
}

function closeContextMenu() {
    contextMenu.classList.remove('open');
}

function selectItem(item) {
    document.querySelectorAll('.design-item.selected').forEach((node) => node.classList.remove('selected'));
    selectedItem = item;

    if (selectedItem) {
        setInspectorHidden(false);
        selectedItem.classList.add('selected');
        selectionLabel.textContent = getSelectionName(selectedItem);
        inspectorEmpty.classList.add('hidden');
        inspectorFields.classList.remove('hidden');
        syncInspector();
    } else {
        setInspectorHidden(true);
        selectionLabel.textContent = 'Nothing selected';
        inspectorEmpty.classList.remove('hidden');
        inspectorFields.classList.add('hidden');
    }
}

function clearSelection() {
    if (selectedItem) {
        selectedItem.classList.remove('selected');
    }
    selectedItem = null;
    closeContextMenu();
    selectItem(null);
}

function getItemStyles(item) {
    const content = item.querySelector('.item-content');
    return {
        fontFamily: content.style.fontFamily || '',
        fontSize: content.style.fontSize || '',
        fontWeight: content.style.fontWeight || '',
        color: content.style.color || '',
        textAlign: content.style.textAlign || '',
        backgroundColor: content.style.backgroundColor || 'transparent',
        borderRadius: item.dataset.type === 'image' ? item.style.borderRadius || '0px' : content.style.borderRadius || '0px',
        borderWidth: item.dataset.type === 'image'
            ? (item.style.borderWidth || '0px')
            : (content.style.borderWidth || '0px'),
        borderColor: item.dataset.type === 'image'
            ? (item.style.borderColor || 'transparent')
            : (content.style.borderColor || 'transparent')
    };
}

function serializeLayout() {
    return {
        board: {
            backgroundColor: canvas.style.backgroundColor || '#fffdf8',
            backgroundImage: canvas.dataset.bgImage || ''
        },
        elements: [...canvas.querySelectorAll('.design-item')].map((item) => {
            const content = item.querySelector('.item-content');
            const payload = {
                id: item.dataset.id,
                type: item.dataset.type,
                x: parseFloat(item.style.left || 0),
                y: parseFloat(item.style.top || 0),
                width: parseFloat(item.style.width || 0),
                height: parseFloat(item.style.height || 0),
                rotation: Number(item.dataset.rotation || 0),
                zIndex: Number(item.style.zIndex || 1),
                opacity: parseFloat(item.style.opacity || 1),
                lockedFill: item.dataset.lockedFill === '1',
                styles: getItemStyles(item)
            };

            if (item.dataset.type === 'image') {
                payload.src = content.querySelector('img')?.src || '';
            } else {
                payload.html = content.innerHTML;
            }

            return payload;
        })
    };
}

function restoreLayout(layout, pushHistory = false) {
    canvas.innerHTML = '';
    highestZ = 10;

    const normalized = Array.isArray(layout)
        ? { board: { backgroundColor: '#fffdf8', backgroundImage: '' }, elements: layout }
        : layout;

    canvas.style.backgroundColor = normalized.board?.backgroundColor || '#fffdf8';
    canvasColor.value = toHexColor(normalized.board?.backgroundColor || '#fffdf8');
    canvas.dataset.bgImage = normalized.board?.backgroundImage || '';
    canvas.style.backgroundImage = canvas.dataset.bgImage ? `url(${canvas.dataset.bgImage})` : 'none';

    (normalized.elements || []).forEach((item) => {
        const prepared = item.type ? item : normalizeLegacyElement(item);
        createItem(prepared, false);
    });

    clearSelection();
    applyCanvasScale();

    if (pushHistory) {
        saveHistory();
    }
}

function saveHistory(clearRedo = true) {
    const snapshot = JSON.stringify(serializeLayout());
    if (historyStack[historyStack.length - 1] === snapshot) {
        return;
    }
    historyStack.push(snapshot);
    if (historyStack.length > 60) {
        historyStack.shift();
    }
    if (clearRedo) {
        redoStack = [];
    }
}

function undo() {
    if (historyStack.length <= 1) {
        return;
    }
    const current = historyStack.pop();
    redoStack.push(current);
    restoreLayout(JSON.parse(historyStack[historyStack.length - 1]), false);
}

function redo() {
    if (!redoStack.length) {
        return;
    }
    const snapshot = redoStack.pop();
    historyStack.push(snapshot);
    restoreLayout(JSON.parse(snapshot), false);
}

function duplicateSelected() {
    if (!selectedItem) {
        return;
    }

    const payload = serializeLayout().elements.find((item) => item.id === selectedItem.dataset.id);
    if (!payload) {
        return;
    }

    payload.id = uid();
    payload.x = clamp(payload.x + 25, 0, getCanvasWidth() - payload.width);
    payload.y = clamp(payload.y + 25, 0, getCanvasHeight() - payload.height);
    payload.zIndex = ++highestZ;
    const clone = createItem(payload, true);
    closeContextMenu();
    selectItem(clone);
}

function deleteSelected() {
    if (!selectedItem) {
        return;
    }
    selectedItem.remove();
    clearSelection();
    saveHistory();
}

function syncInspector() {
    if (!selectedItem) {
        return;
    }

    const styles = getItemStyles(selectedItem);
    const content = selectedItem.querySelector('.item-content');
    const type = selectedItem.dataset.type;

    textFields.classList.toggle('hidden', type !== 'text');
    shapeFields.classList.toggle('hidden', type === 'text');
    imageFields.classList.toggle('hidden', type !== 'image');

    if (type === 'text') {
        textContent.value = content.innerText.replace(/\u00a0/g, ' ');
        fontFamily.value = styles.fontFamily || "'Poppins', sans-serif";
        fontSize.value = parseInt(styles.fontSize, 10) || 18;
        fontWeight.value = styles.fontWeight || '400';
        textColor.value = toHexColor(styles.color || '#001a47');
        alignButtons.forEach((btn) => btn.classList.toggle('active', btn.dataset.align === (styles.textAlign || 'center')));
    }

    if (type !== 'text') {
        fillColor.value = toHexColor(styles.backgroundColor || '#d4af37');
        borderColor.value = toHexColor(styles.borderColor || '#d4af37');
        borderWidth.value = parseInt(styles.borderWidth, 10) || 0;
        cornerRadius.value = parseInt(styles.borderRadius, 10) || 0;
        fillColor.disabled = type === 'image' || selectedItem.dataset.lockedFill === '1';
    }

    if (type === 'image') {
        borderColor.value = toHexColor(styles.borderColor || '#001a47');
        borderWidth.value = parseInt(styles.borderWidth, 10) || 0;
        cornerRadius.value = parseInt(styles.borderRadius, 10) || 14;
    }

    if (type === 'text') {
        fillColor.disabled = true;
    }

    opacityRange.value = parseFloat(selectedItem.style.opacity || 1);
    opacityValue.textContent = Number(opacityRange.value).toFixed(2);
    rotationRange.value = parseInt(selectedItem.dataset.rotation || 0, 10);
    rotationValue.textContent = `${rotationRange.value} deg`;
    posX.value = parseInt(selectedItem.style.left || 0, 10);
    posY.value = parseInt(selectedItem.style.top || 0, 10);
    boxWidth.value = parseInt(selectedItem.style.width || 0, 10);
    boxHeight.value = parseInt(selectedItem.style.height || 0, 10);
}

function toHexColor(value) {
    const input = document.createElement('canvas').getContext('2d');
    input.fillStyle = value || '#000000';
    return input.fillStyle;
}

function updateSelectedText() {
    if (!selectedItem || selectedItem.dataset.type !== 'text') {
        return;
    }

    selectedItem.querySelector('.item-content').innerHTML = textContent.value.replace(/\n/g, '<br>');
}

function updateSelectedStyles() {
    if (!selectedItem) {
        return;
    }

    const content = selectedItem.querySelector('.item-content');
    const type = selectedItem.dataset.type;

    if (type === 'text') {
        content.style.fontFamily = fontFamily.value;
        content.style.fontSize = `${fontSize.value}px`;
        content.style.fontWeight = fontWeight.value;
        content.style.color = textColor.value;
    }

    if (type === 'shape') {
        content.style.backgroundColor = selectedItem.dataset.lockedFill === '1' ? 'transparent' : fillColor.value;
        content.style.borderColor = borderColor.value;
        content.style.borderWidth = `${borderWidth.value}px`;
        content.style.borderRadius = `${cornerRadius.value}px`;
    }

    if (type === 'image') {
        selectedItem.style.border = `${borderWidth.value}px solid ${borderColor.value}`;
        selectedItem.style.borderRadius = `${cornerRadius.value}px`;
    }

    selectedItem.style.opacity = opacityRange.value;
    setRotation(selectedItem, rotationRange.value);
    selectedItem.style.left = `${clamp(Number(posX.value || 0), 0, getCanvasWidth() - selectedItem.offsetWidth)}px`;
    selectedItem.style.top = `${clamp(Number(posY.value || 0), 0, getCanvasHeight() - selectedItem.offsetHeight)}px`;
    selectedItem.style.width = `${clamp(Number(boxWidth.value || 50), 30, getCanvasWidth())}px`;
    selectedItem.style.height = `${clamp(Number(boxHeight.value || 20), 20, getCanvasHeight())}px`;
    opacityValue.textContent = Number(opacityRange.value).toFixed(2);
    rotationValue.textContent = `${rotationRange.value} deg`;
}

function bringToFront() {
    if (!selectedItem) return;
    selectedItem.style.zIndex = ++highestZ;
    closeContextMenu();
    saveHistory();
}

function bringForward() {
    if (!selectedItem) return;
    selectedItem.style.zIndex = Number(selectedItem.style.zIndex || 1) + 1;
    highestZ = Math.max(highestZ, Number(selectedItem.style.zIndex));
    closeContextMenu();
    saveHistory();
}

function sendBackward() {
    if (!selectedItem) return;
    selectedItem.style.zIndex = Math.max(1, Number(selectedItem.style.zIndex || 1) - 1);
    closeContextMenu();
    saveHistory();
}

function sendToBack() {
    if (!selectedItem) return;
    selectedItem.style.zIndex = 1;
    closeContextMenu();
    saveHistory();
}

function addPreset(name) {
    if (name === 'image') {
        pendingImageMode = 'element';
        imageInput.click();
        return;
    }

    const item = createItem(deepClone(presets[name]), true);
    selectItem(item);
}

function saveTemplate() {
    const payload = JSON.stringify(serializeLayout());
    document.body.classList.add('exporting');

    html2canvas(canvas, { backgroundColor: null }).then((result) => {
        const params = new URLSearchParams();
        params.set('save_layout', '1');
        params.set('layout', payload);
        params.set('image', result.toDataURL('image/png'));
        params.set('template_name', templateNameInput.value.trim());

        return fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });
    }).then((response) => response.json())
      .then(() => window.location.reload())
      .finally(() => document.body.classList.remove('exporting'));
}

function downloadPNG() {
    document.body.classList.add('exporting');

    html2canvas(canvas, { backgroundColor: null }).then((result) => {
        const link = document.createElement('a');
        link.href = result.toDataURL('image/png');
        link.download = 'certificate-design.png';
        link.click();
    }).finally(() => document.body.classList.remove('exporting'));
}

function loadTemplate(name) {
    fetch(`?load=${encodeURIComponent(name)}`)
        .then((response) => response.json())
        .then((layout) => restoreLayout(layout, true));
}

document.querySelectorAll('[data-add]').forEach((button) => {
    button.addEventListener('click', () => addPreset(button.dataset.add));
});

document.querySelectorAll('[data-template]').forEach((button) => {
    button.addEventListener('click', () => loadTemplate(button.dataset.template));
});

document.getElementById('undoBtn').addEventListener('click', undo);
document.getElementById('redoBtn').addEventListener('click', redo);
document.getElementById('duplicateBtn').addEventListener('click', duplicateSelected);
document.getElementById('deleteBtn').addEventListener('click', deleteSelected);
document.getElementById('saveBtn').addEventListener('click', saveTemplate);
document.getElementById('downloadBtn').addEventListener('click', downloadPNG);
document.getElementById('zoomInBtn').addEventListener('click', zoomIn);
document.getElementById('zoomOutBtn').addEventListener('click', zoomOut);
document.getElementById('zoomFitBtn').addEventListener('click', zoomToFit);
hideLeftPanelBtn.addEventListener('click', () => setLeftPanelHidden(true));
showLeftPanelBtn.addEventListener('click', () => setLeftPanelHidden(false));
document.getElementById('bringFrontBtn').addEventListener('click', bringToFront);
document.getElementById('bringForwardBtn').addEventListener('click', bringForward);
document.getElementById('sendBackwardBtn').addEventListener('click', sendBackward);
contextMenu.addEventListener('click', (event) => {
    const action = event.target.closest('[data-menu-action]')?.dataset.menuAction;
    if (!action) {
        return;
    }

    if (action === 'bring-front') bringToFront();
    if (action === 'bring-forward') bringForward();
    if (action === 'send-backward') sendBackward();
    if (action === 'send-back') sendToBack();
    if (action === 'duplicate') duplicateSelected();
    if (action === 'delete') deleteSelected();
});
document.getElementById('replaceImageBtn').addEventListener('click', () => {
    if (selectedItem?.dataset.type !== 'image') {
        return;
    }
    pendingImageMode = 'replace';
    imageInput.click();
});

canvas.addEventListener('mousedown', (event) => {
    closeContextMenu();
    if (event.target === canvas) {
        clearSelection();
    }
});

boardStage.addEventListener('wheel', (event) => {
    if (!event.ctrlKey) {
        return;
    }

    event.preventDefault();

    if (event.deltaY < 0) {
        zoomIn();
    } else {
        zoomOut();
    }
}, { passive: false });

canvas.addEventListener('contextmenu', (event) => {
    if (event.target === canvas) {
        event.preventDefault();
        clearSelection();
    }
});

document.addEventListener('mousedown', (event) => {
    if (!event.target.closest('#contextMenu') && !event.target.closest('.design-item')) {
        closeContextMenu();
    }
});

document.addEventListener('scroll', closeContextMenu, true);
window.addEventListener('resize', closeContextMenu);
window.addEventListener('resize', applyCanvasScale);

canvasColor.addEventListener('input', () => {
    canvas.style.backgroundColor = canvasColor.value;
    saveHistory();
});

document.getElementById('backgroundImageBtn').addEventListener('click', () => {
    pendingImageMode = 'background';
    backgroundInput.click();
});

document.getElementById('clearBackgroundBtn').addEventListener('click', () => {
    canvas.dataset.bgImage = '';
    canvas.style.backgroundImage = 'none';
    saveHistory();
});

imageInput.addEventListener('change', (event) => {
    const file = event.target.files[0];
    if (!file) {
        return;
    }

    readFileAsDataURL(file, (dataUrl) => {
        if (pendingImageMode === 'replace' && selectedItem?.dataset.type === 'image') {
            selectedItem.querySelector('img').src = dataUrl;
            saveHistory();
        } else {
            const payload = deepClone(presets.image);
            payload.src = dataUrl;
            const item = createItem(payload, true);
            selectItem(item);
        }
    });

    event.target.value = '';
});

backgroundInput.addEventListener('change', (event) => {
    const file = event.target.files[0];
    if (!file) {
        return;
    }

    readFileAsDataURL(file, (dataUrl) => {
        canvas.dataset.bgImage = dataUrl;
        canvas.style.backgroundImage = `url(${dataUrl})`;
        saveHistory();
    });

    event.target.value = '';
});

textContent.addEventListener('input', updateSelectedText);
textContent.addEventListener('change', saveHistory);
fontFamily.addEventListener('change', () => { updateSelectedStyles(); saveHistory(); });
fontSize.addEventListener('input', updateSelectedStyles);
fontSize.addEventListener('change', saveHistory);
fontWeight.addEventListener('change', () => { updateSelectedStyles(); saveHistory(); });
textColor.addEventListener('input', updateSelectedStyles);
textColor.addEventListener('change', saveHistory);
fillColor.addEventListener('input', updateSelectedStyles);
fillColor.addEventListener('change', saveHistory);
borderColor.addEventListener('input', updateSelectedStyles);
borderColor.addEventListener('change', saveHistory);
borderWidth.addEventListener('input', updateSelectedStyles);
borderWidth.addEventListener('change', saveHistory);
cornerRadius.addEventListener('input', updateSelectedStyles);
cornerRadius.addEventListener('change', saveHistory);
opacityRange.addEventListener('input', updateSelectedStyles);
opacityRange.addEventListener('change', saveHistory);
rotationRange.addEventListener('input', updateSelectedStyles);
rotationRange.addEventListener('change', saveHistory);
posX.addEventListener('input', updateSelectedStyles);
posX.addEventListener('change', saveHistory);
posY.addEventListener('input', updateSelectedStyles);
posY.addEventListener('change', saveHistory);
boxWidth.addEventListener('input', updateSelectedStyles);
boxWidth.addEventListener('change', saveHistory);
boxHeight.addEventListener('input', updateSelectedStyles);
boxHeight.addEventListener('change', saveHistory);

alignButtons.forEach((button) => {
    button.addEventListener('click', () => {
        if (!selectedItem || selectedItem.dataset.type !== 'text') {
            return;
        }
        selectedItem.querySelector('.item-content').style.textAlign = button.dataset.align;
        syncInspector();
        saveHistory();
    });
});

document.addEventListener('keydown', (event) => {
    const activeTag = document.activeElement?.tagName;
    const editingField = ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeTag) || document.activeElement?.isContentEditable;

    if (event.ctrlKey && event.key.toLowerCase() === 's') {
        event.preventDefault();
        saveTemplate();
        return;
    }

    if (event.ctrlKey && (event.key === '+' || event.key === '=')) {
        event.preventDefault();
        zoomIn();
        return;
    }

    if (event.ctrlKey && event.key === '-') {
        event.preventDefault();
        zoomOut();
        return;
    }

    if (event.ctrlKey && event.key === '0') {
        event.preventDefault();
        zoomToFit();
        return;
    }

    if (event.ctrlKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undo();
        return;
    }

    if (event.ctrlKey && event.key.toLowerCase() === 'y') {
        event.preventDefault();
        redo();
        return;
    }

    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        redo();
        return;
    }

    if (event.ctrlKey && event.key.toLowerCase() === 'd' && selectedItem) {
        event.preventDefault();
        duplicateSelected();
        return;
    }

    if (editingField || !selectedItem) {
        return;
    }

    if (event.key === 'Delete') {
        deleteSelected();
        return;
    }

    if (event.key === 'Escape') {
        closeContextMenu();
        return;
    }

    const step = event.shiftKey ? 10 : 1;
    let moved = false;
    let left = parseInt(selectedItem.style.left || 0, 10);
    let top = parseInt(selectedItem.style.top || 0, 10);

    if (event.key === 'ArrowLeft') {
        left -= step;
        moved = true;
    }
    if (event.key === 'ArrowRight') {
        left += step;
        moved = true;
    }
    if (event.key === 'ArrowUp') {
        top -= step;
        moved = true;
    }
    if (event.key === 'ArrowDown') {
        top += step;
        moved = true;
    }

    if (moved) {
        event.preventDefault();
        selectedItem.style.left = `${clamp(left, 0, getCanvasWidth() - selectedItem.offsetWidth)}px`;
        selectedItem.style.top = `${clamp(top, 0, getCanvasHeight() - selectedItem.offsetHeight)}px`;
        syncInspector();
        saveHistory();
    }
});

restoreLayout({
    board: {
        backgroundColor: '#fffdf8',
        backgroundImage: ''
    },
    elements: [
        deepClone(presets.border),
        deepClone(presets.title),
        deepClone(presets.recipient),
        deepClone(presets.body),
        deepClone(presets.signature),
        {
            ...deepClone(presets.line),
            y: 246,
            width: 220,
            x: 452
        },
        {
            ...deepClone(presets.line),
            y: 590,
            width: 220,
            x: 742
        }
    ]
}, false);

saveHistory();
setLeftPanelHidden(localStorage.getItem(LEFT_PANEL_STORAGE_KEY) === '1');
setInspectorHidden(true);
applyCanvasScale();
window.addEventListener('load', applyCanvasScale);
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(applyCanvasScale);
}
</script>
</body>
</html>

<?php
require_once "config/session.php";
require_once "config/db.php";
require_once "config/evaluation_helper.php";

ensureEventRegistrationCodeSupport($conn);

$events = [];

$stmt = $conn->prepare("
    SELECT 
        id,
        event_code,
        title,
        description,
        start_date_and_time,
        address,
        mode_of_delivery,
        google_meet_link,
        speaker,
        audience,
        duration
    FROM events
    ORDER BY start_date_and_time ASC
");

if($stmt === false){
    die("SQL ERROR: " . $conn->error);
}

$stmt->execute();
$result = $stmt->get_result();

while($row = $result->fetch_assoc()){

    $dateOnly = date("Y-m-d", strtotime($row['start_date_and_time']));
    $isPast = $dateOnly < date("Y-m-d");

    $events[] = [
        "event_id" => $row['id'],
        "event_code" => $row['event_code'],
        "title" => $row['title'],
        "description" => $row['description'],
        "event_date" => $dateOnly,
        "is_past" => $isPast,
        "address" => $row['address'],
        "mode_of_delivery" => $row['mode_of_delivery'],
        "google_meet_link" => $row['google_meet_link'],
        "speaker" => $row['speaker'],
        "audience" => $row['audience'],
        "duration" => $row['duration']
    ];
}

$events_json = json_encode($events);
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Calendar</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="assets/css/calendar.css?v=2">
<link rel="stylesheet" href="assets/css/responsive.css">
<?php require_once "config/theme.php"; render_theme_head(); ?>
</head>
<body>
<?php include 'mobile_back_button.php'; ?>

<div class="page-wrapper">

<div class="header"></div>

<div class="container">
<div class="calendar">

<div class="calendar-header">
<div class="calendar-title">Event Calendar</div>

<div class="calendar-nav">
<button onclick="prevMonth()">
<i class="fa fa-chevron-left"></i>
</button>

<div id="monthYear"></div>

<button onclick="nextMonth()">
<i class="fa fa-chevron-right"></i>
</button>
</div>
</div>

<div id="calendarGrid" class="calendar-grid"></div>

</div>
</div>

<!-- MODAL -->
<div id="eventModal" class="modal-overlay">
<div class="modal-box">

<div class="modal-header">
<div id="modalDate"></div>
<button onclick="closeModal()" class="modal-close">×</button>
</div>

<div class="slider-header">
<button onclick="prevEvent()" id="prevBtn" class="slider-btn">‹</button>
<div id="sliderTitle" class="slider-title"></div>
<button onclick="nextEvent()" id="nextBtn" class="slider-btn">›</button>
</div>

<div id="sliderBody"></div>

</div>
</div>

<script>

/* DATABASE EVENTS ONLY */
let events = <?php echo $events_json;?>;
if(!Array.isArray(events)){ events = []; }

let currentDate = new Date();
let modalEvents = [];
let currentSlide = 0;


/* HELPERS */
function hasValue(v){
    return v !== null && v !== undefined && String(v).trim() !== "";
}

function escapeHTML(value){
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function isWebinar(event){
    return hasValue(event.mode_of_delivery)
        && String(event.mode_of_delivery).toLowerCase().includes("webinar");
}

/* hide button ONLY when all event details are missing */
function allEventDetailsMissing(event){
    return !hasValue(event.address)
        && !hasValue(event.audience)
        && !hasValue(event.mode_of_delivery)
        && !(isWebinar(event) && hasValue(event.google_meet_link))
        && !hasValue(event.speaker)
        && !hasValue(event.duration);
}


/* RENDER CALENDAR */
function renderCalendar(){

    const grid = document.getElementById("calendarGrid");
    grid.innerHTML = "";

    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay  = new Date(year, month + 1, 0);

    document.getElementById("monthYear").innerHTML =
        firstDay.toLocaleString("default",{month:"long"}) + " " + year;

    const names = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
    names.forEach(name=>{
        grid.innerHTML += `<div class="day-name">${name}</div>`;
    });

    let startDay = firstDay.getDay();
    startDay = (startDay === 0) ? 6 : startDay - 1;

    for(let i=0;i<startDay;i++){
        grid.innerHTML += "<div></div>";
    }

    for(let d=1; d<=lastDay.getDate(); d++){

        const dateStr =
            year + "-" +
            String(month+1).padStart(2,'0') + "-" +
            String(d).padStart(2,'0');

        const dayEvents = events.filter(e=>e.event_date === dateStr);

        let html = `<div class="day-number">${d}</div>`;

        if(dayEvents.length > 0){

            html += `<div class="day-events">`;

            dayEvents.slice(0,2).forEach(e=>{
                html += `<div class="day-event">• ${escapeHTML(e.title)}</div>`;
            });

            if(dayEvents.length > 2){
                html += `
                <div class="day-more"
                onclick="openEventModal('${dateStr}');event.stopPropagation();">
                +${dayEvents.length-2} more
                </div>`;
            }

            html += `</div>`;
        }

        grid.innerHTML += `
        <div class="day"
        onclick="openEventModal('${dateStr}')">
        ${html}
        </div>`;
    }
}


/* OPEN MODAL */
function openEventModal(date){

    document.getElementById("eventModal").classList.add("show");
    document.getElementById("modalDate").innerHTML = formatDate(date);

    modalEvents = events.filter(e=>e.event_date === date);
    currentSlide = 0;

    renderSlide();
}


/* CLOSE MODAL */
function closeModal(){
    document.getElementById("eventModal").classList.remove("show");
}


/* CLOSE WHEN CLICK OUTSIDE */
window.onclick = function(e){
    if(e.target.id === "eventModal"){
        closeModal();
    }
};


/* RENDER SLIDE */
function renderSlide(){

    const sliderTitle = document.getElementById("sliderTitle");
    const sliderBody  = document.getElementById("sliderBody");
    const prevBtn     = document.getElementById("prevBtn");
    const nextBtn     = document.getElementById("nextBtn");

    if(modalEvents.length === 0){

        sliderTitle.innerHTML = "";
        sliderBody.innerHTML = `<div class="no-events">No Events for this date</div>`;
        prevBtn.style.visibility = "hidden";
        nextBtn.style.visibility = "hidden";
        return;
    }

    const event = modalEvents[currentSlide];

    sliderTitle.innerHTML = escapeHTML(event.title);

    let infoHTML = "";

    if(hasValue(event.address)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Address</span>
            <span class="info-value">${escapeHTML(event.address)}</span>
        </div>`;
    }

    if(hasValue(event.audience)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Audience</span>
            <span class="info-value">${escapeHTML(event.audience)}</span>
        </div>`;
    }

    if(hasValue(event.mode_of_delivery)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Mode of Delivery</span>
            <span class="info-value">${escapeHTML(event.mode_of_delivery)}</span>
        </div>`;
    }

    if(isWebinar(event) && hasValue(event.google_meet_link)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Google Meet</span>
            <span class="info-value">
                <a href="${escapeHTML(event.google_meet_link)}" target="_blank" rel="noopener noreferrer">${escapeHTML(event.google_meet_link)}</a>
            </span>
        </div>`;
    }

    if(hasValue(event.speaker)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Speaker</span>
            <span class="info-value">${escapeHTML(event.speaker)}</span>
        </div>`;
    }

    if(hasValue(event.duration)){
        infoHTML += `
        <div class="info-item">
            <span class="info-label">Duration</span>
            <span class="info-value">${escapeHTML(event.duration)}</span>
        </div>`;
    }

    const showRegisterButton =
        !allEventDetailsMissing(event) && hasValue(event.event_code) && !event.is_past;

    sliderBody.innerHTML = `
    <div class="event-content">

        <p class="event-description">
            ${escapeHTML(event.description)}
        </p>

        ${infoHTML !== "" ? `<div class="event-info">${infoHTML}</div>` : ""}

    </div>

    ${
        showRegisterButton
        ? `<button class="register-btn" onclick='registerEvent(${JSON.stringify(event.event_code)})'>Register</button>`
        : event.is_past
            ? `<button class="register-btn register-btn-disabled" disabled>Registration Closed</button>`
        : ``
    }
    `;

    prevBtn.style.visibility = currentSlide === 0 ? "hidden" : "visible";
    nextBtn.style.visibility =
        currentSlide === modalEvents.length - 1 ? "hidden" : "visible";
}


/* SLIDER NAV */
function nextEvent(){
    if(currentSlide < modalEvents.length-1){
        currentSlide++;
        renderSlide();
    }
}

function prevEvent(){
    if(currentSlide > 0){
        currentSlide--;
        renderSlide();
    }
}


/* REGISTER */
function registerEvent(code){
    window.location.href = "registration.php?event_code=" + encodeURIComponent(code);
}


/* MONTH NAV */
function prevMonth(){
    currentDate.setMonth(currentDate.getMonth()-1);
    renderCalendar();
}

function nextMonth(){
    currentDate.setMonth(currentDate.getMonth()+1);
    renderCalendar();
}


/* FORMAT DATE */
function formatDate(dateStr){
    const d = new Date(dateStr);
    return d.toLocaleDateString("en-US",{
        year:"numeric",
        month:"long",
        day:"numeric"
    });
}


/* INIT */
renderCalendar();

</script>

</div>

<?php include "bottom_nav.php"; ?>

</body>
</html>

<?php

if (!function_exists('render_theme_head')) {
    function render_theme_head()
    {
        echo <<<'HTML'
<script>
(function () {
    try {
        if (localStorage.getItem("darkmode") === "on") {
            document.documentElement.classList.add("theme-dark");
        }
    } catch (e) {}
})();
</script>
<link rel="stylesheet" href="assets/css/theme.css">
<script src="assets/js/theme.js" defer></script>
HTML;
    }
}


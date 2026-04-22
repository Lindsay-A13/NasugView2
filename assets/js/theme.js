(function () {
    const storageKey = "darkmode";
    const skipTags = new Set([
        "HTML", "BODY", "SCRIPT", "STYLE", "LINK", "META", "IMG", "SVG", "PATH",
        "CANVAS", "VIDEO", "AUDIO", "SOURCE", "BR"
    ]);

    function parseColor(value) {
        if (!value || value === "transparent") {
            return null;
        }

        const match = value.match(/rgba?\(([^)]+)\)/i);
        if (!match) {
            return null;
        }

        const parts = match[1].split(",").map(function (part) {
            return parseFloat(part.trim());
        });

        return {
            r: parts[0] || 0,
            g: parts[1] || 0,
            b: parts[2] || 0,
            a: parts.length > 3 ? parts[3] : 1
        };
    }

    function luminance(color) {
        return (0.2126 * color.r) + (0.7152 * color.g) + (0.0722 * color.b);
    }

    function shouldSkip(el) {
        return skipTags.has(el.tagName) || el.classList.contains("theme-no-dark");
    }

    function markElement(el) {
        if (shouldSkip(el)) {
            return;
        }

        const styles = window.getComputedStyle(el);
        const bg = parseColor(styles.backgroundColor);
        const text = parseColor(styles.color);
        const border = parseColor(styles.borderTopColor);

        if (bg && bg.a > 0.85 && luminance(bg) > 200) {
            el.classList.add("theme-auto-surface");
        }

        if (text && luminance(text) < 150) {
            el.classList.add("theme-auto-text");
        }

        if (border && border.a > 0.2 && luminance(border) > 170) {
            el.classList.add("theme-auto-border");
        }
    }

    function scanPage() {
        if (!document.body) {
            return;
        }

        document.querySelectorAll("body *").forEach(markElement);
    }

    function syncTheme(isDark) {
        document.documentElement.classList.toggle("theme-dark", isDark);

        if (document.body) {
            document.body.classList.toggle("theme-dark", isDark);
        }

        if (isDark) {
            scanPage();
        }
    }

    function readPreference() {
        try {
            return localStorage.getItem(storageKey) === "on";
        } catch (e) {
            return false;
        }
    }

    function writePreference(isDark) {
        try {
            localStorage.setItem(storageKey, isDark ? "on" : "off");
        } catch (e) {}
    }

    window.NVTheme = {
        set: function (isDark) {
            writePreference(isDark);
            syncTheme(isDark);
        },
        refresh: function () {
            if (document.documentElement.classList.contains("theme-dark")) {
                scanPage();
            }
        },
        isDark: function () {
            return document.documentElement.classList.contains("theme-dark");
        }
    };

    document.addEventListener("DOMContentLoaded", function () {
        syncTheme(readPreference());
    });
})();

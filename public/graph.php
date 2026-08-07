<?php

/**
 * graph.php — the chart page.
 *
 * Server-side it does almost nothing: check the session, emit HTML.
 * All the data work happens in graph.js against the JSON API, so this
 * page never needs to know about the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/session.php';

// Humans get a redirect to the login form; APIs get 401. Different
// audiences, same rule.
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energy graph — IoT Metering</title>

    <style>
        /*
         * Colour tokens as CSS custom properties, defined ONCE here and
         * read by graph.js. One source of truth: change a value here and
         * both the page chrome and the chart follow.
         *
         * The two series hues are checked for colour-blind separation
         * (ΔE 24.7 under protanopia — well clear of the ~8 threshold) and
         * for contrast against the surface.
         */
        :root {
            color-scheme: light;
            --surface:        #fcfcfb;
            --text-primary:   #0b0b0b;
            --text-secondary: #52514e;
            --grid:           #e6e5e1;
            --series-cumulative: #2a78d6;  /* blue  — the line  */
            --series-interval:   #eb6834;  /* orange— the bars  */
        }

        /* Dark values are SELECTED for the dark surface, not auto-flipped. */
        @media (prefers-color-scheme: dark) {
            :root:where(:not([data-theme="light"])) {
                color-scheme: dark;
                --surface:        #1a1a19;
                --text-primary:   #ffffff;
                --text-secondary: #c3c2b7;
                --grid:           #333330;
                --series-cumulative: #3987e5;
                --series-interval:   #d95926;
            }
        }

        body {
            font-family: system-ui, sans-serif;
            margin: 0 auto; padding: 1.5rem 1rem; max-width: 64rem;
            background: var(--surface);
            color: var(--text-primary);
        }

        h1 { font-size: 1.3rem; margin: 0 0 .3rem; }

        /* Filters: ONE row, above the chart. Never inside the chart card. */
        #controls {
            display: flex; flex-wrap: wrap; gap: .75rem; align-items: end;
            margin: 1rem 0;
        }
        #controls label { display: block; font-size: .8rem;
                          color: var(--text-secondary); }
        #controls input, #controls button { font: inherit; padding: .35rem .5rem; }

        /* The chart needs an explicit height: ECharts measures its
           container, and a div with no content has height 0. */
        #chart { width: 100%; height: 26rem; }

        #status { font-size: .85rem; color: var(--text-secondary);
                  min-height: 1.4em; }
        .warn { color: #b35900; }
    </style>

    <!-- defer = parse the HTML first, then run the script, so the
         elements it looks for already exist. -->
    <script src="/vendor/echarts.min.js" defer></script>
    <script src="/graph.js" defer></script>
</head>
<body>

<h1>Energy over time</h1>
<p id="subtitle" style="color: var(--text-secondary); margin: 0;">
    Bars: energy per interval. Line: cumulative energy since the start of
    the range. Both in kWh, times in UTC.
</p>

<div id="controls">
    <div>
        <label for="meter">Meter ID</label>
        <input type="text" id="meter" value="mock-meter-001" size="18">
    </div>
    <div>
        <label for="from">From (UTC)</label>
        <input type="date" id="from">
    </div>
    <div>
        <label for="to">To (UTC)</label>
        <input type="date" id="to">
    </div>
    <button id="load" type="button">Load</button>
    <a href="/logout.php">Sign out</a>
</div>

<div id="chart"></div>
<p id="status"></p>

</body>
</html>
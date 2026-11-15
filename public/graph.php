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
            --power-l1:      #2a78d6;  /* blue   */
            --power-l2:      #eb6834;  /* orange */
            --power-l3:      #4a3aa7;  /* violet */
            --energy-import: #e34948;  /* red   — drawn from the grid */
            --energy-export: #1baf7a;  /* green — sent back to the grid */
        }

        /* Dark values are SELECTED for the dark surface, not auto-flipped. */
        @media (prefers-color-scheme: dark) {
            :root:where(:not([data-theme="light"])) {
                color-scheme: dark;
                --surface:        #1a1a19;
                --text-primary:   #ffffff;
                --text-secondary: #c3c2b7;
                --grid:           #333330;
                --power-l1:      #3987e5;
                --power-l2:      #d95926;
                --power-l3:      #9085e9;
                --energy-import: #e66767;
                --energy-export: #199e70;
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

        /* Each chart needs an explicit height: ECharts measures its
           container, and a div with no content has height 0. */
        #chart-power, #chart-energy { width: 100%; height: 17rem; }
        .chart-label { font-size: .8rem; color: var(--text-secondary);
                       margin: .8rem 0 .2rem; }

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
    Readings exactly as the meter reported them. Top: instantaneous power per
    phase in watts, where negative means that phase is exporting. Bottom:
    cumulative import and export counters in kWh. Times in UTC; zooming
    either chart moves both.
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
     <a href="/index.php?logout=1">Sign out</a>
</div>

<p class="chart-label">Power per phase (W) — instantaneous</p>
<div id="chart-power"></div>

<p class="chart-label">Energy counters (kWh) — cumulative meter totals</p>
<div id="chart-energy"></div>
<p id="status"></p>

</body>
</html>
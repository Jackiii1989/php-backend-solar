'use strict';

/*
 * graph.js — one chart, one y-axis.
 *
 * Two series, both in kWh so they share a scale honestly:
 *   bars = energy in each interval        (the raw measurement)
 *   line = cumulative energy since the start of the visible range
 *
 * Why not the raw meter counter? It reads ~1000.000 -> 1000.500 over a
 * day: a huge constant plus a tiny variation, which forces a second
 * y-axis and makes the two scales' alignment arbitrary. Re-basing to
 * "energy since range start" keeps ONE axis, and makes the relationship
 * real — the line's height equals the sum of the bars to its left.
 * The raw counter still travels in the data and shows in the tooltip.
 */

// ── Colours: read the CSS tokens so the palette is defined once ────────
// getComputedStyle resolves custom properties to the

const css = getComputedStyle(document.documentElement);
const token = (name) => css.getPropertyValue(name).trim();

const COLOR = {
    cumulative: token('--series-cumulative'),
    interval:   token('--series-interval'),
    text:       token('--text-primary'),
    textMuted:  token('--text-secondary'),
    grid:       token('--grid'),
};

const meterInput = document.getElementById('meter');
const fromInput  = document.getElementById('from');
const toInput    = document.getElementById('to');
const loadButton = document.getElementById('load');
const statusEl   = document.getElementById('status');



// ECharts must be given the FULL option once. After that we send only
// the series data: setOption merges, so everything we omit — axes,
// legend, and crucially the user's dataZoom position — is left alone.
let chartInitialised = false;

function drawChart(series) {
    if (!chartInitialised) {
        chart.setOption(buildOption(series));
        chartInitialised = true;
        return;
    }

    chart.setOption({
        series: [
            { name: 'Energy per interval', data: series.intervalPoints },
            { name: 'Cumulative energy',   data: series.cumulativePoints },
        ],
    });
}

/**
 * Turn API slots into the two series ECharts draws.
 *
 * Each point is [timestampMs, value]; the raw counter rides along as a
 * third element so the tooltip can show it without a second lookup.
 * (ECharts ignores extra array elements when plotting.)
 */
function buildSeries(slots) {
    const intervalPoints = [];
    const cumulativePoints = [];
    let runningTotal = 0;

    for (const slot of slots) {
        // Date.parse understands ISO 8601 with an offset and returns
        // milliseconds since the epoch — an absolute instant, so no
        // timezone ambiguity can creep in here.
        const t = Date.parse(slot.end_timestamp);
        const delta = Number(slot.meter_reading_delta_kwh);

        runningTotal += delta;

        intervalPoints.push([t, delta, slot.meter_reading_now_kwh]);
        cumulativePoints.push([t, runningTotal, slot.meter_reading_now_kwh]);
    }

    return { intervalPoints, cumulativePoints, total: runningTotal };
}


/**
 * The ECharts option object — the whole chart described as data.
 */
function buildOption({ intervalPoints, cumulativePoints }) {
    return {
        // The DB is UTC and the API sends UTC. Without this, ECharts
        // would label the axis in the viewer's local timezone while the
        // tooltip said UTC — the same convention slip we guard against
        // on the PHP side. One flag, one truth.
        useUTC: true,

        // Chart chrome wears TEXT colours, never series colours: a
        // coloured label competes with the data for attention.
        textStyle: { color: COLOR.textMuted },

        grid: { left: 56, right: 20, top: 40, bottom: 78, containLabel: false },

        // Legend is mandatory with two series: identity must never rest
        // on colour alone. Clicking an entry toggles that series.
        legend: {
            top: 6,
            textStyle: { color: COLOR.text },
            data: ['Energy per interval', 'Cumulative energy'],
        },

        tooltip: {
            // 'axis' = one tooltip listing EVERY series at that x, so the
            // pointer never has to land exactly on a bar or the line.
            trigger: 'axis',
            axisPointer: {
                type: 'line',                       // the crosshair
                lineStyle: { color: COLOR.grid },
            },
            formatter(params) {
                if (!params.length) { return ''; }

                // params[0].value = [timestampMs, value, rawCounter]
                const when = echarts.time.format(
                    params[0].value[0], '{yyyy}-{MM}-{dd} {HH}:{mm}', true   // true = UTC
                );

                // Value leads, label follows: the reader already knows
                // which series they're looking at; they want the number.
                const rows = params.map((p) => {
                    const stroke =
                        `<span style="display:inline-block;width:14px;height:2px;
                          background:${p.color};vertical-align:middle;
                          margin-right:6px"></span>`;
                    return `${stroke}<strong>${p.value[1].toFixed(3)} kWh</strong>
                            <span style="opacity:.7"> ${p.seriesName}</span>`;
                });

                const counter = params[0].value[2];
                rows.push(`<span style="opacity:.7">meter reads
                           ${Number(counter).toFixed(3)} kWh</span>`);

                return `<div style="font-weight:600">${when} UTC</div>
                        ${rows.join('<br>')}`;
            },
        },

        xAxis: {
            type: 'time',
            axisLine:  { lineStyle: { color: COLOR.grid } },
            axisLabel: { color: COLOR.textMuted, hideOverlap: true },
            splitLine: { show: false },
        },

        // ONE y-axis. Both series are kWh, so one scale tells the truth
        // about both — and the line is literally the running sum of the
        // bars, which only reads correctly when they share a scale.
        yAxis: {
            type: 'value',
            name: 'kWh',
            nameTextStyle: { color: COLOR.textMuted, align: 'right' },
            axisLabel: { color: COLOR.textMuted },
            // Recessive grid: present enough to read a value against,
            // never competing with the data.
            splitLine: { lineStyle: { color: COLOR.grid, type: 'dashed' } },
        },

        // Zoom: 'inside' = wheel / pinch on the plot itself;
        // 'slider' = the draggable strip, which also SHOWS where you are
        // in the whole range (a wheel alone leaves the reader lost).
        dataZoom: [
            { type: 'inside', xAxisIndex: 0 },
            {
                type: 'slider', xAxisIndex: 0, bottom: 12, height: 22,
                borderColor: COLOR.grid,
                fillerColor: 'rgba(42,120,214,0.12)',
                handleStyle: { color: COLOR.cumulative },
                textStyle: { color: COLOR.textMuted },
            },
        ],

        series: [
            {
                name: 'Energy per interval',
                type: 'bar',
                data: intervalPoints,
                itemStyle: {
                    color: COLOR.interval,
                    // Rounded data-end, square baseline end: the bar
                    // grows FROM the axis, and the shape says so.
                    borderRadius: [4, 4, 0, 0],
                },
                // Cap the width so neighbours keep a visible gap instead
                // of fusing into one block at wide zoom levels.
                barMaxWidth: 14,
                emphasis: { focus: 'series' },
            },
            {
                name: 'Cumulative energy',
                type: 'line',
                data: cumulativePoints,
                lineStyle: { color: COLOR.cumulative, width: 2 },
                itemStyle: { color: COLOR.cumulative },
                // No smoothing: energy accumulates in steps, and a curve
                // would invent values between readings that never existed.
                smooth: false,
                // Hide the per-point dots (they'd be noise at 96+ points)
                // but keep them 8px when hover brings one back.
                showSymbol: false,
                symbolSize: 8,
                // ONE selective direct label — the current total, at the
                // end of the line. Never a number on every point.
                endLabel: {
                    show: true,
                    color: COLOR.cumulative,
                    formatter: (p) => `${p.value[1].toFixed(2)} kWh`,
                },
                z: 3,               // line rides above the bars
            },
        ],
    };
}


function setStatus(message, className) {
    statusEl.textContent = message;        // textContent, not innerHTML:
    statusEl.className = className || '';  // messages carry API text
}

/** "YYYY-MM-DD" for a Date, in UTC. */
function isoDate(date) {
    return date.toISOString().slice(0, 10);
}



/** Fetch one range of slots. Throws on any non-2xx. */
async function fetchSlots(meterId, from, to) {
    // URL + searchParams encodes values properly — never glue a query
    // string together by hand.
    const url = new URL('/v1/get-meter-data.php', window.location.origin);
    url.searchParams.set('meter_id', meterId);
    url.searchParams.set('from', from);
    url.searchParams.set('to', to);

    const response = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        // Default for same-origin anyway, but stated so the mechanism is
        // visible: THIS is what carries the session cookie. No token.
        credentials: 'same-origin',
        cache: 'no-store',
    });

    // 401 = the session expired while this tab sat open. A fetch cannot
    // follow a redirect to a login page (it would just fetch the HTML),
    // so the page navigates itself.
    if (response.status === 401) {
        window.location.href = '/index.php';
        throw new Error('Session expired.');
    }

    // Parse first: our API sends errors as JSON too, and "Range too
    // large" beats "HTTP 400".
    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new Error('Server returned invalid JSON (HTTP ' + response.status + ').');
    }

    if (!response.ok) {
        throw new Error(payload.error ?? ('HTTP ' + response.status));
    }
    if (!Array.isArray(payload.slots)) {
        throw new Error('Response has no slots array.');
    }

    return payload.slots;
}

/** Read the controls, fetch, redraw. */
async function loadChart() {
    const meterId = meterInput.value.trim();
    const from = fromInput.value;
    const to = toInput.value;

    if (meterId === '' || from === '' || to === '') {
        setStatus('Meter ID, from and to are all required.', 'warn');
        return;
    }

    loadButton.disabled = true;
    setStatus('Loading…');

    try {
        const slots = await fetchSlots(meterId, from, to);
        const series = buildSeries(slots);

        drawChart(series);

        setStatus(
            slots.length === 0
                ? 'No readings for ' + meterId + ' between ' + from + ' and ' + to + '.'
                : slots.length + ' readings · ' + series.total.toFixed(3) + ' kWh total'
        );
    } catch (error) {
        // Keep the previous chart on screen: a transient failure should
        // not wipe out what the reader was looking at.
        setStatus(error.message ?? 'Request failed.', 'warn');
    } finally {
        loadButton.disabled = false;
    }
}

// ── Boot ───────────────────────────────────────────────────────────────

const chartElement = document.getElementById('chart');
const chart = echarts.init(chartElement, null, { renderer: 'canvas' });

// Default range: the last 7 days, ending today — in UTC, the same clock
// as the data, so "today" means the same thing on both sides.
const now = new Date();
toInput.value = isoDate(now);
fromInput.value = isoDate(new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000));

loadButton.addEventListener('click', function() {
    chartInitialised = false;   // new query -> new view
    loadChart();
});

window.addEventListener('resize', () => chart.resize());

loadChart();


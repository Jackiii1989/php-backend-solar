'use strict';

/*
 * graph.js — two charts, one time axis.
 *
 *   top:    instantaneous power per phase, in watts
 *   bottom: cumulative energy counters (import / export), in kWh
 *
 * Two charts rather than two y-axes on one chart. Watts are a RATE and kWh an
 * ACCUMULATION; putting them on one plot with independent scales invents a
 * correlation the data does not contain. Separate plots, one shared x-axis,
 * linked zoom — the honest form.
 *
 * Nothing is computed here. The API returns readings exactly as stored and
 * every line is drawn straight from them.
 */

// ── Colours: read the CSS tokens so the palette is defined once ────────
const css = getComputedStyle(document.documentElement);
const token = (name) => css.getPropertyValue(name).trim();

const COLOR = {
    l1:           token('--power-l1'),
    l2:           token('--power-l2'),
    l3:           token('--power-l3'),
    energyImport: token('--energy-import'),
    energyExport: token('--energy-export'),
    text:         token('--text-primary'),
    textMuted:    token('--text-secondary'),
    grid:         token('--grid'),
};

const meterInput = document.getElementById('meter');
const fromInput  = document.getElementById('from');
const toInput    = document.getElementById('to');
const loadButton = document.getElementById('load');
const statusEl   = document.getElementById('status');

const powerChart  = echarts.init(document.getElementById('chart-power'),  null, { renderer: 'canvas' });
const energyChart = echarts.init(document.getElementById('chart-energy'), null, { renderer: 'canvas' });

// One call, and the two charts share zoom AND crosshair: dragging the slider
// on either moves both, so the eye is always comparing the same time window.
echarts.connect([powerChart, energyChart]);

/** Minimal HTML escape — seriesName lands inside a tooltip HTML string. */
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

/**
 * API readings -> [timestampMs, value] pairs per series.
 *
 * Date.parse understands ISO 8601 with an offset and returns an absolute
 * instant, so no timezone ambiguity can creep in.
 */
function toSeriesData(readings) {
    const at = (reading) => Date.parse(reading.timestamp);

    return {
        l1:     readings.map((r) => [at(r), r.power_l1_w]),
        l2:     readings.map((r) => [at(r), r.power_l2_w]),
        l3:     readings.map((r) => [at(r), r.power_l3_w]),
        import: readings.map((r) => [at(r), r.import_kwh]),
        export: readings.map((r) => [at(r), r.export_kwh]),
    };
}

/** One line series. No smoothing: a curve would invent unmeasured values. */
function lineSeries(name, data, color) {
    return {
        name,
        type: 'line',
        data,
        lineStyle: { color, width: 2 },
        itemStyle: { color },
        smooth: false,
        showSymbol: false,
        symbolSize: 7,
        // Thins the drawn points at wide ranges while keeping the shape.
        // Affects rendering only, never the data.
        sampling: 'lttb',
        emphasis: { focus: 'series' },
    };
}

/** Tooltip formatter bound to a unit and a decimal count. */
function tooltipFormatter(unit, digits) {
    return function (params) {
        if (!params.length) { return ''; }

        const when = echarts.time.format(
            params[0].value[0], '{yyyy}-{MM}-{dd} {HH}:{mm}', true   // true = UTC
        );

        const rows = params.map((p) => {
            const stroke =
                `<span style="display:inline-block;width:14px;height:2px;` +
                `background:${p.color};vertical-align:middle;margin-right:6px"></span>`;

            return `${stroke}<strong>${Number(p.value[1]).toFixed(digits)} ${unit}</strong>` +
                   `<span style="opacity:.7"> ${escapeHtml(p.seriesName)}</span>`;
        });

        return `<div style="font-weight:600">${when} UTC</div>${rows.join('<br>')}`;
    };
}

/**
 * Everything both charts share. scaleY is the one real difference.
 */
function baseOption(axisName, scaleY, unit, digits) {
    return {
        // The DB and API are UTC. Without this ECharts labels the axis in the
        // viewer's local timezone while the tooltip says UTC.
        useUTC: true,

        // Chart chrome wears TEXT colours, never series colours.
        textStyle: { color: COLOR.textMuted },

        grid: { left: 64, right: 24, top: 34, bottom: 56, containLabel: false },

        legend: { top: 4, textStyle: { color: COLOR.text } },

        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: COLOR.grid } },
            formatter: tooltipFormatter(unit, digits),
        },

        xAxis: {
            type: 'time',
            axisLine:  { lineStyle: { color: COLOR.grid } },
            axisLabel: { color: COLOR.textMuted, hideOverlap: true },
            splitLine: { show: false },
        },

        yAxis: {
            type: 'value',
            name: axisName,
            nameTextStyle: { color: COLOR.textMuted, align: 'right' },
            axisLabel: { color: COLOR.textMuted },
            splitLine: { lineStyle: { color: COLOR.grid, type: 'dashed' } },

            // THE setting that decides whether the energy chart is readable.
            // ECharts includes zero by default, which is right for power
            // (0 W is a real baseline and negative means exporting) but
            // ruinous for a lifetime counter sitting at 1000.2 -> 1000.6:
            // the line would render as a dead-flat stroke against a 0..1000
            // axis. scale:true fits the axis to the data instead.
            scale: scaleY,
        },

        dataZoom: [
            { type: 'inside', xAxisIndex: 0 },
            {
                type: 'slider', xAxisIndex: 0, bottom: 6, height: 18,
                borderColor: COLOR.grid,
                textStyle: { color: COLOR.textMuted },
            },
        ],
    };
}

// The full option is set ONCE. After that only series data is sent, because
// setOption merges — and including dataZoom in a refresh would snap
// start/end back to 0-100 and throw away the user's zoom every 15 seconds.
let chartsInitialised = false;

function drawCharts(readings) {
    const data = toSeriesData(readings);

    const powerSeries = [
        lineSeries('L1', data.l1, COLOR.l1),
        lineSeries('L2', data.l2, COLOR.l2),
        lineSeries('L3', data.l3, COLOR.l3),
    ];

    const energySeries = [
        lineSeries('Import', data.import, COLOR.energyImport),
        lineSeries('Export', data.export, COLOR.energyExport),
    ];

    if (!chartsInitialised) {
        powerChart.setOption({
            ...baseOption('W', false, 'W', 1),
            series: powerSeries,
        });

        energyChart.setOption({
            ...baseOption('kWh', true, 'kWh', 3),
            series: energySeries,
        });

        chartsInitialised = true;
        return;
    }

    // Series merge BY NAME, so name must be included.
    powerChart.setOption({
        series: powerSeries.map((s) => ({ name: s.name, data: s.data })),
    });

    energyChart.setOption({
        series: energySeries.map((s) => ({ name: s.name, data: s.data })),
    });
}

function setStatus(message, className) {
    statusEl.textContent = message;        // textContent, not innerHTML:
    statusEl.className = className || '';  // messages carry API text
}

/** "YYYY-MM-DD" for a Date, in UTC. */
function isoDate(date) {
    return date.toISOString().slice(0, 10);
}

/** Fetch one range of readings. Throws on any non-2xx. */
async function fetchReadings(meterId, from, to) {
    const url = new URL('/v1/get-meter-data.php', window.location.origin);
    url.searchParams.set('meter_id', meterId);
    url.searchParams.set('from', from);
    url.searchParams.set('to', to);

    const response = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        // THIS is what carries the session cookie. No token in the browser.
        credentials: 'same-origin',
        cache: 'no-store',
    });

    // 401 = the session expired while this tab sat open. A fetch cannot follow
    // a redirect to a login page, so the page navigates itself.
    if (response.status === 401) {
        window.location.href = '/index.php';
        throw new Error('Session expired.');
    }

    // Parse first: the API sends errors as JSON too, and "Range too large"
    // beats "HTTP 400".
    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new Error('Server returned invalid JSON (HTTP ' + response.status + ').');
    }

    if (!response.ok) {
        throw new Error(payload.error ?? ('HTTP ' + response.status));
    }
    if (!Array.isArray(payload.readings)) {
        throw new Error('Response has no readings array.');
    }

    return payload.readings;
}

/** Read the controls, fetch, redraw. */
async function loadCharts() {
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
        const readings = await fetchReadings(meterId, from, to);

        drawCharts(readings);

        if (readings.length === 0) {
            setStatus('No readings for ' + meterId + ' between ' + from + ' and ' + to + '.');
        } else {
            const latest = readings[readings.length - 1];

            setStatus(
                readings.length + ' readings · latest import ' +
                latest.import_kwh.toFixed(3) + ' kWh · export ' +
                latest.export_kwh.toFixed(3) + ' kWh'
            );
        }
    } catch (error) {
        // Keep the previous charts on screen: a transient failure should not
        // wipe out what the reader was looking at.
        setStatus(error.message ?? 'Request failed.', 'warn');
    } finally {
        loadButton.disabled = false;
    }
}

// ── Boot ───────────────────────────────────────────────────────────────

// Default range: the last 7 days ending today, in UTC — the same clock as the
// data, so "today" means the same thing on both sides.
const now = new Date();
toInput.value = isoDate(now);
fromInput.value = isoDate(new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000));

loadButton.addEventListener('click', function () {
    chartsInitialised = false;   // new query -> new view
    loadCharts();
});

window.addEventListener('resize', function () {
    powerChart.resize();
    energyChart.resize();
});

loadCharts();

// ── Live refresh ───────────────────────────────────────────────────────
// 15 seconds. setTimeout, NOT setInterval: the next request is scheduled only
// after the previous one finishes, so a slow network cannot stack requests.
const REFRESH_MS = 15000;
let refreshTimer = null;

function scheduleRefresh() {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refreshTick, REFRESH_MS);
}

async function refreshTick() {
    // A background tab gets throttled by the browser anyway; skipping the
    // request outright is honest about that and saves the server the work.
    if (!document.hidden) {
        await loadCharts();
    }

    scheduleRefresh();
}

document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
        window.clearTimeout(refreshTimer);
        refreshTick();
    }
});

scheduleRefresh();
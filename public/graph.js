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

// ── Sample data — the exact shape the API returns ──────────────────────
// Replaced by a fetch in the next step; nothing else changes.
const SAMPLE_SLOTS = [
    { end_timestamp: '2026-08-06T00:00:00+00:00', meter_reading_delta_kwh: 0.08, meter_reading_now_kwh: 1000.08 },
    { end_timestamp: '2026-08-06T00:15:00+00:00', meter_reading_delta_kwh: 0.09, meter_reading_now_kwh: 1000.17 },
    { end_timestamp: '2026-08-06T00:30:00+00:00', meter_reading_delta_kwh: 0.11, meter_reading_now_kwh: 1000.28 },
    { end_timestamp: '2026-08-06T00:45:00+00:00', meter_reading_delta_kwh: 0.12, meter_reading_now_kwh: 1000.40 },
    { end_timestamp: '2026-08-06T01:00:00+00:00', meter_reading_delta_kwh: 0.05, meter_reading_now_kwh: 1000.45 },
    { end_timestamp: '2026-08-06T01:15:00+00:00', meter_reading_delta_kwh: 0.04, meter_reading_now_kwh: 1000.49 },
    { end_timestamp: '2026-08-06T01:30:00+00:00', meter_reading_delta_kwh: 0.31, meter_reading_now_kwh: 1000.80 },
    { end_timestamp: '2026-08-06T01:45:00+00:00', meter_reading_delta_kwh: 0.28, meter_reading_now_kwh: 1001.08 },
    { end_timestamp: '2026-08-06T02:00:00+00:00', meter_reading_delta_kwh: 0.06, meter_reading_now_kwh: 1001.14 },
    { end_timestamp: '2026-08-06T02:15:00+00:00', meter_reading_delta_kwh: 0.07, meter_reading_now_kwh: 1001.21 },
    { end_timestamp: '2026-08-06T02:30:00+00:00', meter_reading_delta_kwh: 0.09, meter_reading_now_kwh: 1001.30 },
    { end_timestamp: '2026-08-06T02:45:00+00:00', meter_reading_delta_kwh: 0.10, meter_reading_now_kwh: 1001.40 },
];


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

    return { intervalPoints, cumulativePoints };
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

// ── Boot ───────────────────────────────────────────────────────────────
const chartElement = document.getElementById('chart');
const chart = echarts.init(chartElement, null, { renderer: 'canvas' });

chart.setOption(buildOption(buildSeries(SAMPLE_SLOTS)));

// ECharts sizes itself to its container ONCE at init. Without this the
// chart keeps its first width forever and looks broken after a resize.
window.addEventListener('resize', () => chart.resize());
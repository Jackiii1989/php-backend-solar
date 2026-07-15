'use strict';

/*
 * dashboard.js
 *
 * Loads authenticated meter measurements from api-v1.php and refreshes them
 * every 15 seconds. The API token remains in the current page's password input.
 * It is never added to the request URL or stored in localStorage.
 */

/*
 * These elements already exist in index.php. The script will be loaded with
 * "defer", so the browser parses the HTML before executing this code.
 */
const tokenInput = document.getElementById('dashboard-token');
const meterInput = document.getElementById('dashboard-meter');
const dateInput = document.getElementById('dashboard-date');
const loadButton = document.getElementById('dashboard-load');
const statusElement = document.getElementById('dashboard-status');
const tableElement = document.getElementById('dashboard-table');
const slotsElement = document.getElementById('dashboard-slots');

/*
 * JavaScript time values are expressed in milliseconds:
 * 15,000 milliseconds = 15 seconds.
 */
const refreshIntervalMilliseconds = 15000;

let pollingEnabled = false;
let refreshTimer = null;

/**
 * Display a dashboard status message.
 *
 * textContent treats the message as plain text instead of interpreting it as
 * HTML. className selects the existing "ok" or "warn" CSS style.
 */
function setDashboardStatus(message, className = '') {
    statusElement.textContent = message;
    statusElement.className = className;
}

/**
 * Create a table cell containing plain text.
 *
 * API values must not be inserted with innerHTML because they originate outside
 * the page and could contain HTML-like text.
 */
function createTextCell(value) {
    const cell = document.createElement('td');

    cell.textContent = String(value);

    return cell;
}

/**
 * Replace the existing table rows with slots from the latest API response.
 */
function renderSlots(slots) {
    // Remove rows from the previous successful response.
    slotsElement.replaceChildren();

    if (slots.length === 0) {
        tableElement.hidden = true;
        return;
    }

    for (const slot of slots) {
        const row = document.createElement('tr');

        const startCell = createTextCell(
            slot.start_timestamp ?? ''
        );

        const endCell = createTextCell(
            slot.end_timestamp ?? ''
        );

        const energyCell = createTextCell(
            `${slot.value ?? ''} ${slot.unit ?? 'kWh'}`
        );

        row.append(startCell, endCell, energyCell);
        slotsElement.append(row);
    }

    tableElement.hidden = false;
}

/**
 * Stop a scheduled refresh.
 */
function clearRefreshTimer() {
    if (refreshTimer === null) {
        return;
    }

    window.clearTimeout(refreshTimer);
    refreshTimer = null;
}

/**
 * Schedule another request after the current request has finished.
 *
 * setTimeout is used instead of setInterval so slow network requests cannot
 * overlap with the following request.
 */
function scheduleNextRefresh() {
    clearRefreshTimer();

    refreshTimer = window.setTimeout(
        loadDashboard,
        refreshIntervalMilliseconds
    );
}

/**
 * Read and validate the current dashboard controls.
 *
 * Returning null prevents an invalid request from reaching api-v1.php.
 */
function readDashboardControls() {
    const token = tokenInput.value.trim();
    const meterId = meterInput.value.trim();
    const date = dateInput.value;

    if (token === '' || meterId === '' || date === '') {
        pollingEnabled = false;
        clearRefreshTimer();
        tableElement.hidden = true;

        setDashboardStatus(
            'API token, meter ID, and date are required.',
            'warn'
        );

        return null;
    }

    return {
        token,
        meterId,
        date
    };
}

/**
 * Parse the API response as JSON.
 *
 * A separate function gives us a useful error when PHP or the web server
 * unexpectedly returns HTML instead of the API's normal JSON contract.
 */
async function parseJsonResponse(response) {
    try {
        return await response.json();
    } catch {
        throw new Error(
            `The server returned invalid JSON (HTTP ${response.status}).`
        );
    }
}

/**
 * Load one meter's measurements from the authenticated GET endpoint.
 */
async function loadDashboard() {
    const controls = readDashboardControls();

    if (controls === null) {
        return;
    }

    loadButton.disabled = true;
    setDashboardStatus('Loading measurements…');

    try {
        /*
         * URL and URLSearchParams safely encode the meter ID and date.
         * The API token is deliberately not included in this URL.
         */
        const requestUrl = new URL(
            'api-v1.php',
            window.location.href
        );

        requestUrl.searchParams.set('date', controls.date);
        requestUrl.searchParams.set('meter_id', controls.meterId);

        const response = await fetch(requestUrl, {
            method: 'GET',

            headers: {
                'Accept': 'application/json',

                /*
                 * The token travels in the standard Authorization header.
                 * It is visible only to the current browser session and server.
                 */
                'Authorization': `Bearer ${controls.token}`
            },

            // Always request the latest available measurements.
            cache: 'no-store'
        });

        const responseData = await parseJsonResponse(response);

        if (!response.ok) {
            /*
             * Do not repeatedly send invalid authentication or input every
             * 15 seconds. The user can correct the controls and click again.
             */
            if (response.status >= 400 && response.status < 500) {
                pollingEnabled = false;
            }

            const errorMessage =
                typeof responseData.error === 'string'
                    ? responseData.error
                    : `Request failed with HTTP ${response.status}.`;

            throw new Error(errorMessage);
        }

        if (!Array.isArray(responseData.slots)) {
            throw new Error(
                'The API response does not contain a slots array.'
            );
        }

        renderSlots(responseData.slots);

        const updateTime = new Date().toISOString();

        if (responseData.slots.length === 0) {
            setDashboardStatus(
                `No measurements found for ${controls.meterId} ` +
                `on ${controls.date}. Last checked ${updateTime}.`,
                'ok'
            );
        } else {
            setDashboardStatus(
                `Loaded ${responseData.slots.length} measurement(s). ` +
                `Last updated ${updateTime}.`,
                'ok'
            );
        }
    } catch (error) {
        /*
         * Keep the last successful table visible if a later network or server
         * error occurs.
         */
        const errorMessage =
            error instanceof Error
                ? error.message
                : 'The dashboard request failed.';

        setDashboardStatus(errorMessage, 'warn');
    } finally {
        loadButton.disabled = false;

        /*
         * After a successful request or retryable server/network failure,
         * schedule the next request for 15 seconds from now.
         */
        if (pollingEnabled) {
            scheduleNextRefresh();
        }
    }
}

/*
 * The first request is manual. After that, loadDashboard() schedules the next
 * request every 15 seconds.
 */
loadButton.addEventListener('click', () => {
    pollingEnabled = true;
    clearRefreshTimer();

    void loadDashboard();
});
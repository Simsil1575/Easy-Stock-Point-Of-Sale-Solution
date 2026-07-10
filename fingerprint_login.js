/**
 * DigitalPersona capture + POST probe to fingerprint_auth.php (1:N finger match).
 */
(function () {
    'use strict';

    if (typeof Fingerprint === 'undefined') {
        return;
    }

    var currentFormat = Fingerprint.SampleFormat.Raw;
    var acquisitionStarted = false;
    var fpSdk = new Fingerprint.WebApi();
    var ACCEPT_QUALITY = 0;

    var errorMessages = {
        no_match: 'No match. Try again or sign in with your password.',
        no_probe: 'Scan quality too low. Press firmly and scan again.',
        no_enrolled: 'No fingerprints enrolled.',
        server_error: 'Try again.',
        bad_request: 'Try again.'
    };

    fpSdk.onDeviceConnected = function () {
        setFpStatus('Place your finger on the reader.', false);
    };
    fpSdk.onDeviceDisconnected = function () {
        setFpError('Reader disconnected.', { reason: 'device_disconnected' });
        acquisitionStarted = false;
        var startBtn = document.getElementById('fpLoginStartBtn');
        if (startBtn) startBtn.disabled = false;
    };
    fpSdk.onCommunicationFailed = function () {
        setFpError('Reader error.', { reason: 'communication_failed' });
        acquisitionStarted = false;
        var startBtn = document.getElementById('fpLoginStartBtn');
        if (startBtn) startBtn.disabled = false;
    };
    fpSdk.onQualityReported = function (evt) {
        fpSdk._lastQuality = evt && typeof evt.quality === 'number' ? evt.quality : null;
    };
    fpSdk.onSamplesAcquired = function (s) {
        if (fpSdk._lastQuality !== null && fpSdk._lastQuality !== undefined && fpSdk._lastQuality !== ACCEPT_QUALITY) {
            setFpError('Scan quality too low. Press firmly and try again.', { reason: 'quality_rejected', quality: fpSdk._lastQuality });
            fpSdk._lastQuality = null;
            var startBtn = document.getElementById('fpLoginStartBtn');
            if (startBtn) startBtn.disabled = false;
            return;
        }
        var samples = JSON.parse(s.samples);
        var rawScan = typeof fpParseRawSample === 'function'
            ? fpParseRawSample(samples[0].Data)
            : null;
        if (!rawScan) {
            setFpError('Scan again.', { reason: 'raw_parse_failed' });
            var startBtn = document.getElementById('fpLoginStartBtn');
            if (startBtn) startBtn.disabled = false;
            return;
        }
        stopCapture().then(function () {
            sendFingerprint(rawScan);
        });
    };

    function setFpStatus(msg, isError) {
        var el = document.getElementById('fpLoginStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'mt-3 min-h-[1.25rem] text-sm font-medium ' + (isError ? 'text-red-600' : 'text-gray-700');
    }

    function setFpError(codeOrMsg, detail) {
        var code = typeof codeOrMsg === 'string' && errorMessages[codeOrMsg] ? codeOrMsg : null;
        var msg = code ? errorMessages[code] : (typeof codeOrMsg === 'string' ? codeOrMsg : 'Try again.');
        setFpStatus(msg, true);
        if (detail !== undefined) {
            logFpDetail('error', code || codeOrMsg, detail);
        } else if (code) {
            logFpDetail('error', code, { code: code });
        }
    }

    function logFpDetail(level, label, payload) {
        var entry = {
            label: label,
            time: new Date().toISOString(),
            payload: payload
        };
        if (level === 'info') {
            console.info('[fingerprint]', entry);
        } else {
            console.error('[fingerprint]', entry);
        }
    }

    function stopCapture() {
        if (!acquisitionStarted) {
            return Promise.resolve();
        }
        return fpSdk.stopAcquisition().then(
            function () {
                acquisitionStarted = false;
            },
            function () {
                acquisitionStarted = false;
            }
        );
    }

    function startCapture(deviceIdOverride) {
        if (acquisitionStarted) return;
        var sel = document.getElementById('fpLoginReaderSelect');
        var deviceId = deviceIdOverride || (sel && sel.value) || '';
        if (!deviceId) {
            setFpError('No reader.', { reason: 'no_device_selected' });
            var startBtn = document.getElementById('fpLoginStartBtn');
            if (startBtn) startBtn.disabled = false;
            return;
        }
        setFpStatus('Place your finger on the reader.', false);
        fpSdk.startAcquisition(currentFormat, deviceId).then(
            function () {
                acquisitionStarted = true;
            },
            function (err) {
                setFpError('Reader error.', {
                    reason: 'start_acquisition_failed',
                    message: err && err.message ? err.message : null
                });
                var startBtn = document.getElementById('fpLoginStartBtn');
                if (startBtn) startBtn.disabled = false;
            }
        );
    }

    function beginFingerprintScan(deviceIdOverride) {
        var startBtn = document.getElementById('fpLoginStartBtn');
        if (startBtn) startBtn.disabled = true;
        startCapture(deviceIdOverride);
    }

    function setReaderRowVisible(showMultiReaderPicker) {
        var readerRow = document.getElementById('fpLoginReaderRow');
        if (!readerRow) return;
        if (showMultiReaderPicker) {
            readerRow.classList.remove('hidden');
        } else {
            readerRow.classList.add('hidden');
        }
    }

    function fillReaders() {
        var sel = document.getElementById('fpLoginReaderSelect');
        var startBtn = document.getElementById('fpLoginStartBtn');
        if (!sel) return;
        if (startBtn) startBtn.disabled = true;
        setReaderRowVisible(false);
        sel.innerHTML = '<option value="">Detecting reader…</option>';
        sel.disabled = true;
        setFpStatus('Looking for reader…', false);
        fpSdk.enumerateDevices().then(
            function (readers) {
                sel.innerHTML = '';
                sel.disabled = false;
                if (!readers || readers.length === 0) {
                    setFpError('No reader.', { reason: 'enumerate_no_devices' });
                    if (startBtn) startBtn.disabled = true;
                    return;
                }
                readers.forEach(function (r) {
                    var opt = document.createElement('option');
                    opt.value = r;
                    opt.textContent = r;
                    sel.appendChild(opt);
                });
                sel.selectedIndex = 0;
                if (sel.value !== readers[0]) {
                    sel.value = readers[0];
                }
                var defaultId = readers[0];
                setReaderRowVisible(readers.length > 1);
                setTimeout(function () {
                    beginFingerprintScan(defaultId);
                }, 0);
            },
            function (err) {
                sel.innerHTML = '<option value="">—</option>';
                sel.disabled = true;
                setFpError('Reader error.', {
                    reason: 'enumerate_failed',
                    message: err && err.message ? err.message : null
                });
                if (startBtn) startBtn.disabled = true;
            }
        );
    }

    function handleAuthResponse(res, context) {
        var startBtn = document.getElementById('fpLoginStartBtn');
        if (startBtn) startBtn.disabled = false;

        if (res.ok && res.redirect) {
            logFpDetail('info', 'login_success', { context: context, redirect: res.redirect });
            window.location.href = res.redirect;
            return;
        }

        setFpError(res.code || 'server_error', {
            context: context,
            code: res.code || 'server_error',
            detail: res.detail || null,
            response: res
        });
    }

    function parseAuthResponse(xhr, context) {
        var raw = xhr.responseText || '';
        try {
            var res = JSON.parse(raw || '{}');
            handleAuthResponse(res, context);
        } catch (e) {
            setFpError('server_error', {
                context: context,
                parse_error: e.message,
                status: xhr.status,
                raw: raw
            });
        }
    }

    function sendFingerprint(rawScan) {
        setFpStatus('Verifying…', false);

        var usernameEl = document.getElementById('username');
        var username = usernameEl && usernameEl.value ? String(usernameEl.value).trim() : '';

        var bundle = {
            id: 0,
            raw_scan: rawScan,
            index_finger: [rawScan],
            middle_finger: [],
            username: username
        };
        var payload = 'data=' + encodeURIComponent(JSON.stringify(bundle));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.FP_AUTH_ENDPOINT || 'fingerprint_auth.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            parseAuthResponse(xhr, 'scan');
        };
        xhr.send(payload);
    }

    function openModal() {
        var m = document.getElementById('fpLoginModal');
        if (!m) return;
        setReaderRowVisible(false);
        m.classList.remove('hidden');
        setFpStatus('', false);
        fillReaders();
    }

    function closeModal() {
        stopCapture().then(function () {
            var m = document.getElementById('fpLoginModal');
            if (m) m.classList.add('hidden');
            setFpStatus('', false);
            var startBtn = document.getElementById('fpLoginStartBtn');
            if (startBtn) startBtn.disabled = false;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var openBtn = document.getElementById('fpOpenModalBtn');
        var closeBtn = document.getElementById('fpLoginCloseBtn');
        var startBtn = document.getElementById('fpLoginStartBtn');
        var backdrop = document.getElementById('fpLoginModalBackdrop');

        if (openBtn) openBtn.addEventListener('click', openModal);
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', closeModal);
        if (startBtn) {
            startBtn.addEventListener('click', function () {
                beginFingerprintScan();
            });
        }
    });
})();

/**
 * Fingerprint enrollment: Raw capture → server dpfj.dll → true FMD (v3) in user.db.
 * Rejects poor-quality / duplicate scans; server merges four raw scans per finger into one FMD.
 */
(function () {
    'use strict';

    if (typeof Fingerprint === 'undefined') {
        return;
    }

    var FORMAT = Fingerprint.SampleFormat.Raw;
    var ACCEPT_QUALITY = 0; // Fingerprint.QualityCode.Good
    var lastReportedQuality = null;
    var lastAcceptedScanData = null;
    var lastAcceptedAt = 0;
    var DUPLICATE_EVENT_MS = 1200;

    function apiUrl() {
        return window.FP_ENROLL_API_URL || '../fingerprint_enroll_api.php';
    }

    function setStatus(message, isError) {
        var el = document.getElementById('fpAddUserStatus');
        if (!el) return;
        el.textContent = message || '';
        el.className =
            'text-sm font-medium min-h-[1.25rem] ' +
            (isError ? 'text-red-600' : 'text-gray-700');
    }

    function qualityLabel(code) {
        if (typeof Fingerprint.QualityCode !== 'undefined' && Fingerprint.QualityCode[code]) {
            return Fingerprint.QualityCode[code];
        }
        return 'code ' + code;
    }

    function rawScansMatch(a, b) {
        if (!a || !b || !a.data || !b.data) {
            return false;
        }
        return a.width === b.width &&
            a.height === b.height &&
            a.dpi === b.dpi &&
            a.data === b.data;
    }

    function sampleAlreadyCaptured(hand, rawScan) {
        var i;
        for (i = 0; i < hand.index_finger.length; i++) {
            if (rawScansMatch(hand.index_finger[i], rawScan)) {
                return true;
            }
        }
        for (i = 0; i < hand.middle_finger.length; i++) {
            if (rawScansMatch(hand.middle_finger[i], rawScan)) {
                return true;
            }
        }
        return false;
    }

    function resetScanSession() {
        lastReportedQuality = null;
        lastAcceptedScanData = null;
        lastAcceptedAt = 0;
    }

    function FingerprintSdkWrap() {
        var self = this;
        this.acquisitionStarted = false;
        this.sdk = new Fingerprint.WebApi();
        this.sdk.onDeviceConnected = function () {
            setStatus('Reader connected.', false);
        };
        this.sdk.onDeviceDisconnected = function () {
            setStatus('Reader disconnected.', true);
            self.acquisitionStarted = false;
        };
        this.sdk.onCommunicationFailed = function () {
            setStatus('Reader communication failed.', true);
            self.acquisitionStarted = false;
        };
        this.sdk.onQualityReported = function (evt) {
            lastReportedQuality = evt && typeof evt.quality === 'number' ? evt.quality : null;
        };
        this.sdk.onSamplesAcquired = function (evt) {
            if (!window._fpAddUserActiveHand) return;

            if (lastReportedQuality !== null && lastReportedQuality !== ACCEPT_QUALITY) {
                setStatus(
                    'Scan quality too low (' + qualityLabel(lastReportedQuality) + '). Press firmly and try again.',
                    true
                );
                lastReportedQuality = null;
                return;
            }

            var samples = JSON.parse(evt.samples);
            var rawScan = typeof fpParseRawSample === 'function'
                ? fpParseRawSample(samples[0].Data)
                : null;
            if (!rawScan) {
                setStatus('Could not read fingerprint image. Try again.', true);
                lastReportedQuality = null;
                return;
            }
            var hand = window._fpAddUserActiveHand;
            var now = Date.now();

            if (rawScan.data === lastAcceptedScanData && (now - lastAcceptedAt) < DUPLICATE_EVENT_MS) {
                return;
            }

            if (sampleAlreadyCaptured(hand, rawScan)) {
                setStatus('Same scan detected — lift finger fully and scan again.', true);
                lastReportedQuality = null;
                return;
            }

            var nextSlot = nextOpenSlot();
            if (!nextSlot) return;

            var id = nextSlot.id;
            if (id.indexOf('indexfingerFpAu') === 0) {
                hand.index_finger.push(rawScan);
            } else if (id.indexOf('middleFingerFpAu') === 0) {
                hand.middle_finger.push(rawScan);
            } else {
                return;
            }

            lastReportedQuality = null;
            lastAcceptedScanData = rawScan.data;
            lastAcceptedAt = now;
            markSlot(nextSlot, 'done');
            var nx = nextOpenSlot();
            if (nx) markSlot(nx, 'next');

            if (hand.index_finger.length >= 4 && hand.middle_finger.length >= 4) {
                self.stopCapture().then(function () {
                    setStatus(
                        'All scans captured. Tap Finish enrollment to build FMD templates.',
                        false
                    );
                });
            } else if (hand.index_finger.length === 4 && hand.middle_finger.length === 0) {
                setStatus('Index finger done. Scan middle finger four times.', false);
            }
        };
    }

    FingerprintSdkWrap.prototype.startCapture = function (deviceUid) {
        if (this.acquisitionStarted) return;
        var self = this;
        lastReportedQuality = null;
        this.sdk.startAcquisition(FORMAT, deviceUid || '').then(
            function () {
                self.acquisitionStarted = true;
            },
            function (err) {
                setStatus((err && err.message) || 'Could not start reader.', true);
            }
        );
    };

    FingerprintSdkWrap.prototype.stopCapture = function () {
        var self = this;
        if (!self.acquisitionStarted) return Promise.resolve();
        return self.sdk.stopAcquisition().then(
            function () {
                self.acquisitionStarted = false;
            },
            function () {
                self.acquisitionStarted = false;
            }
        );
    };

    function wrapSlots(containerId) {
        var el = document.getElementById(containerId);
        return el ? el.children : [];
    }

    function resetSlots() {
        ;[].forEach.call(wrapSlots('fpAddUserIndexFingers'), resetSlot);
        ;[].forEach.call(wrapSlots('fpAddUserMiddleFingers'), resetSlot);
    }

    function resetSlot(div) {
        var dot = div.firstElementChild;
        if (!dot) return;
        dot.className =
            'inline-block h-10 w-10 rounded-full border-2 border-gray-300 bg-gray-50';
        dot.setAttribute('data-state', 'empty');
    }

    function markSlot(div, state) {
        var dot = div.firstElementChild;
        if (!dot) return;
        if (state === 'done') {
            dot.className =
                'inline-block h-10 w-10 rounded-full border-2 border-teal-600 bg-teal-200';
            dot.setAttribute('data-state', 'done');
        } else if (state === 'next') {
            dot.className =
                'inline-block h-10 w-10 rounded-full border-2 border-teal-500 bg-teal-50 animate-pulse';
            dot.setAttribute('data-state', 'next');
        }
    }

    function nextOpenSlot() {
        var i;
        var idxKids = wrapSlots('fpAddUserIndexFingers');
        for (i = 0; i < idxKids.length; i++) {
            var d = idxKids[i].firstElementChild;
            if (d && d.getAttribute('data-state') !== 'done') return idxKids[i];
        }
        var midKids = wrapSlots('fpAddUserMiddleFingers');
        for (i = 0; i < midKids.length; i++) {
            var d2 = midKids[i].firstElementChild;
            if (d2 && d2.getAttribute('data-state') !== 'done') return midKids[i];
        }
        return null;
    }

    function pulseFirstSlot() {
        resetSlots();
        var hand = window._fpAddUserActiveHand;
        if (!hand) return;
        var idxKids = wrapSlots('fpAddUserIndexFingers');
        var midKids = wrapSlots('fpAddUserMiddleFingers');
        var i;
        for (i = 0; i < hand.index_finger.length && i < idxKids.length; i++) {
            markSlot(idxKids[i], 'done');
        }
        for (i = 0; i < hand.middle_finger.length && i < midKids.length; i++) {
            markSlot(midKids[i], 'done');
        }
        var next = nextOpenSlot();
        if (next) markSlot(next, 'next');
    }

    var sdkSingleton = new FingerprintSdkWrap();

    function refreshReaders() {
        var sel = document.getElementById('fpAddUserReaderSelect');
        if (!sel) return;
        sel.innerHTML = '<option value="">Select fingerprint reader</option>';
        sdkSingleton.sdk.enumerateDevices().then(
            function (readers) {
                if (!readers || readers.length === 0) {
                    setStatus('No reader found. Connect the fingerprint USB device.', true);
                    return;
                }
                for (var j = 0; j < readers.length; j++) {
                    var r = readers[j];
                    sel.innerHTML += '<option value="' + r + '">' + r + '</option>';
                }
                setStatus('Select a reader.', false);
            },
            function () {
                setStatus('Could not list readers.', true);
            }
        );
    }

    function beginCapture() {
        var sel = document.getElementById('fpAddUserReaderSelect');
        if (!sel || !sel.value) {
            setStatus('Choose a fingerprint reader first.', true);
            return;
        }
        window._fpAddUserActiveHand = {
            id: 0,
            index_finger: [],
            middle_finger: [],
        };
        resetScanSession();
        pulseFirstSlot();
        sdkSingleton.stopCapture().then(function () {
            sdkSingleton.startCapture(sel.value);
            setStatus(
                'Scan index finger 4 times, then middle finger 4 times. Press firmly; poor scans are rejected.',
                false
            );
        });
    }

    function clearAll() {
        sdkSingleton.stopCapture();
        window._fpAddUserActiveHand = null;
        resetScanSession();
        resetSlots();
        document.getElementById('enrolled_index_finger').value = '';
        document.getElementById('enrolled_middle_finger').value = '';
        setStatus('', false);
    }

    function finishEnroll() {
        var hand = window._fpAddUserActiveHand;
        if (!hand || hand.index_finger.length < 4 || hand.middle_finger.length < 4) {
            setStatus('Need four scans for index and four for middle before finishing.', true);
            return;
        }
        setStatus('Validating templates…', false);
        var payload =
            'data=' +
            encodeURIComponent(
                JSON.stringify({
                    id: hand.id,
                    index_finger: hand.index_finger,
                    middle_finger: hand.middle_finger,
                })
            );
        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl(), true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 0) {
                setStatus('Enrollment failed (server connection). Restart Apache and try again.', true);
                console.error('[fingerprint-enroll] connection failed — native FMD worker may be unavailable');
                return;
            }
            try {
                var res = JSON.parse(xhr.responseText || '{}');
                if (res.ok && res.enrolled_index_finger && res.enrolled_middle_finger) {
                    document.getElementById('enrolled_index_finger').value =
                        res.enrolled_index_finger;
                    document.getElementById('enrolled_middle_finger').value =
                        res.enrolled_middle_finger;
                    setStatus(
                        'Templates validated. Submit the form to save this user.',
                        false
                    );
                    console.info('[fingerprint-enroll] templates saved', {
                        format: res.format || 'unknown',
                        index_samples: hand.index_finger.length,
                        middle_samples: hand.middle_finger.length,
                        native: res.native || null
                    });
                } else {
                    setStatus(res.error || 'Enrollment failed.', true);
                    console.error('[fingerprint-enroll]', res);
                }
            } catch (e) {
                setStatus('Unexpected server response.', true);
                console.error('[fingerprint-enroll] parse error', e, xhr.responseText);
            }
        };
        xhr.send(payload);
    }

    function bind() {
        if (!document.getElementById('fpAddUserEnrollmentWrap')) return;
        var r = document.getElementById('fpAddUserRefreshReadersBtn');
        var s = document.getElementById('fpAddUserStartCaptureBtn');
        var c = document.getElementById('fpAddUserClearBtn');
        var f = document.getElementById('fpAddUserFinishEnrollBtn');
        if (r) r.addEventListener('click', refreshReaders);
        if (s) s.addEventListener('click', beginCapture);
        if (c) c.addEventListener('click', clearAll);
        if (f) f.addEventListener('click', finishEnroll);
        refreshReaders();
    }

    document.addEventListener('DOMContentLoaded', bind);
})();

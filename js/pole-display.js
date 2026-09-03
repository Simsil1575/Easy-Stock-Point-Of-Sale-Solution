/**

 * Posiflex PD-320 customer pole display (20 x 2) via QZ Tray RS-232 (COM port).

 * Default command set: Epson ESC/POS (factory default on PD-320).

 */

(function (window) {

    'use strict';



    var PORT_KEY = 'pos_pole_display_port';

    var WIDTH = 20;

    var holdUntil = 0;

    var openPromise = null;

    var openPortName = '';
    var openPortVariant = '';

    var lastFrame = '';

    var sendChain = Promise.resolve();

    var qzSecurityConfigured = false;

    var welcomeTimer = null;



    function cfg() {

        return window.POS_POLE_DISPLAY || {};

    }



    function normalizePort(port) {

        return String(port || '').trim().toUpperCase();

    }



    function canonicalComPort(port) {

        port = normalizePort(port);

        var m = port.match(/^COM(\d+)$/);

        if (m) {

            return 'COM' + parseInt(m[1], 10);

        }

        m = port.match(/^\\\\\.\\COM(\d+)$/);

        if (m) {

            return 'COM' + parseInt(m[1], 10);

        }

        return port;

    }



    function isWindows() {

        try {

            return /windows/i.test(String(navigator.userAgent || navigator.platform || ''));

        } catch (e) {

            return false;

        }

    }



    function isComPort(port) {

        return /^COM\d+$/i.test(canonicalComPort(port));

    }



    function isWindowsPrinterPort(port) {

        port = normalizePort(port);

        return /^LPT\d+/i.test(port) || /^USB\d+/i.test(port) || /^ESDPRT\d+$/i.test(port);

    }



    function isEsdPort(port) {

        return /^ESDPRT\d+$/i.test(normalizePort(port));

    }



    function sanitizePort(port) {

        port = canonicalComPort(port);

        if (!port || port === 'AUTO') {

            return '';

        }

        if (isWindowsPrinterPort(port) || !isComPort(port)) {

            return '';

        }

        return port;

    }



    function clearStoredPortIfInvalid() {

        try {

            var stored = normalizePort(localStorage.getItem(PORT_KEY) || '');

            if (stored && stored !== 'AUTO' && sanitizePort(stored) !== stored) {

                localStorage.removeItem(PORT_KEY);

            }

        } catch (e) {

            // ignore

        }

    }



    function readThisTillPort() {

        clearStoredPortIfInvalid();

        try {

            var value = String(localStorage.getItem(PORT_KEY) || '').trim();

            return sanitizePort(value);

        } catch (e) {

            return '';

        }

    }



    function configuredPort() {

        return sanitizePort(cfg().port || '');

    }



    function polePortError(port) {

        port = normalizePort(port);

        if (isEsdPort(port)) {

            return 'ESDPRT is a receipt-printer port, not RS-232. Use the pole display COM port from Device Manager (e.g. COM3).';

        }

        if (/^LPT\d+/i.test(port)) {

            return 'LPT is a parallel port, not RS-232. Your pole display on an RS-232 cable needs a COM port — check Device Manager → Ports (COM & LPT).';

        }

        if (/^USB\d+/i.test(port)) {

            return port + ' is a Windows printer port. RS-232 pole displays use COM ports (e.g. COM3).';

        }

        if (port && port !== 'AUTO' && !isComPort(port)) {

            return 'Use a COM port for RS-232 (for example COM1, COM3, or COM4).';

        }

        return '';

    }



    function validatePolePort(port) {

        port = sanitizePort(port);

        var err = polePortError(port);

        if (err) {

            throw new Error(err);

        }

        if (!port || port === 'AUTO') {

            return port;

        }

        if (!isComPort(port)) {

            throw new Error('Port ' + port + ' is not a valid COM port. RS-232 pole displays use COM1, COM3, etc.');

        }

        return port;

    }



    function sortComPorts(a, b) {

        a = canonicalComPort(a);

        b = canonicalComPort(b);

        return (parseInt(a.replace(/\D+/g, ''), 10) || 0) - (parseInt(b.replace(/\D+/g, ''), 10) || 0);

    }



    function normalizeDiscoveredPorts(ports) {

        var seen = {};

        var out = [];

        (ports || []).forEach(function (port) {

            port = sanitizePort(port);

            if (!port || seen[port]) {

                return;

            }

            seen[port] = true;

            out.push(port);

        });

        return out.sort(sortComPorts);

    }



    function isPortBusyError(err) {

        var msg = String(err && err.message ? err.message : err).toLowerCase();

        return /busy|already open|in use|access is denied|portname/.test(msg);

    }



    function formatOpenPortError(port, err) {

        var raw = String(err && err.message ? err.message : err);

        port = canonicalComPort(port);

        if (isPortBusyError(err)) {

            return 'Port ' + port + ' is busy. Close other programs using that COM port, restart QZ Tray, then test again.';

        }

        if (/incorrect serial port|no such file|not found|invalid port|unknown port/i.test(raw)) {

            return 'Could not open ' + port + '. Check Device Manager → Ports (COM & LPT), confirm the RS-232 cable is plugged in, install the USB/serial driver if needed, then pick the correct COM port.';

        }

        return 'Could not open ' + port + ': ' + raw;

    }



    function windowsComVariants(port) {

        port = canonicalComPort(port);

        if (!isWindows() || !isComPort(port)) {

            return [port];

        }

        var num = parseInt(port.replace(/\D+/g, ''), 10) || 0;

        if (num >= 10) {

            return [port, '\\\\.\\' + port];

        }

        return [port];

    }



    function closeQzPort(port) {

        port = canonicalComPort(port || openPortName);

        if (!port || typeof qz === 'undefined' || !qz.serial || typeof qz.serial.closePort !== 'function') {

            return Promise.resolve();

        }

        var variants = windowsComVariants(port);

        var chain = Promise.resolve();

        variants.forEach(function (variant) {

            chain = chain.then(function () {

                return qz.serial.closePort(variant).catch(function () {

                    return null;

                });

            });

        });

        return chain.then(function () {

            if (canonicalComPort(openPortName) === port) {

                openPortName = '';

                openPortVariant = '';

            }

        });

    }



    function writeThisTillPort(port) {

        port = sanitizePort(port);

        try {

            if (!port) {

                localStorage.removeItem(PORT_KEY);

            } else {

                localStorage.setItem(PORT_KEY, port);

            }

        } catch (e) {

            // ignore

        }

        if (!window.POS_POLE_DISPLAY) {

            window.POS_POLE_DISPLAY = {};

        }

        window.POS_POLE_DISPLAY.port = port || 'AUTO';

        resetConnection();

    }



    function resetConnection() {

        if (openPortName) {

            closeQzPort(openPortName);

        }

        openPromise = null;

        openPortName = '';

        openPortVariant = '';

        lastFrame = '';

    }



    function isEnabled() {

        return cfg().enabled === true;

    }



    function mode() {

        return cfg().mode === 'pst' ? 'pst' : 'epson';

    }



    function portName() {

        return configuredPort() || readThisTillPort();

    }



    function baudRate() {

        var baud = parseInt(cfg().baud, 10);

        return [2400, 4800, 9600, 19200].indexOf(baud) >= 0 ? baud : 9600;

    }



    function asciiSafe(text) {

        return String(text || '')

            .replace(/[^\x20-\x7E]/g, ' ')

            .replace(/\s+/g, ' ')

            .trim();

    }



    function pad20(text) {

        var s = asciiSafe(text);

        if (s.length > WIDTH) {

            s = s.slice(0, WIDTH);

        }

        while (s.length < WIDTH) {

            s += ' ';

        }

        return s;

    }



    function center20(text) {

        var s = asciiSafe(text);

        if (s.length > WIDTH) {

            s = s.slice(0, WIDTH);

        }

        var left = Math.floor((WIDTH - s.length) / 2);

        var right = WIDTH - s.length - left;

        var out = '';

        var i;

        for (i = 0; i < left; i++) {

            out += ' ';

        }

        out += s;

        for (i = 0; i < right; i++) {

            out += ' ';

        }

        return out;

    }



    function money(amount) {

        var n = Number(amount);

        if (!isFinite(n)) {

            n = 0;

        }

        return 'N$' + n.toFixed(2);

    }



    function moneyLine(label, amount) {

        var left = asciiSafe(label).toUpperCase();

        var right = money(amount);

        if (left.length + 1 + right.length > WIDTH) {

            left = left.slice(0, Math.max(0, WIDTH - 1 - right.length));

        }

        var space = WIDTH - left.length - right.length;

        if (space < 1) {

            return (left + right).slice(0, WIDTH);

        }

        var mid = '';

        var i;

        for (i = 0; i < space; i++) {

            mid += ' ';

        }

        return left + mid + right;

    }



    function toHex(bytes) {

        var hex = '';

        var i;

        for (i = 0; i < bytes.length; i++) {

            var h = (bytes[i] & 0xFF).toString(16).toUpperCase();

            hex += (h.length < 2 ? '0' : '') + h;

        }

        return hex;

    }



    function strBytes(text) {

        var bytes = [];

        var i;

        for (i = 0; i < text.length; i++) {

            bytes.push(text.charCodeAt(i) & 0x7F);

        }

        return bytes;

    }



    function buildFrame(line1, line2) {

        var top = pad20(line1);

        var bottom = pad20(line2);

        var bytes;

        if (mode() === 'pst') {

            bytes = [0x14, 0x04, 0x14, 0x0E];

            return toHex(bytes.concat(strBytes(top)).concat([0x0A]).concat(strBytes(bottom)));

        }

        bytes = [0x1B, 0x40, 0x0C];

        return toHex(bytes.concat(strBytes(top)).concat([0x0A]).concat(strBytes(bottom)));

    }



    function receiptAssetBase() {

        var base = (typeof window !== 'undefined' && window.TERMINAL_API_BASE) ? window.TERMINAL_API_BASE : '';

        return base + 'receipt/';

    }



    function ensureQzSecurity() {

        if (qzSecurityConfigured || typeof qz === 'undefined' || !qz.security) {

            return;

        }

        var assetBase = receiptAssetBase();

        qz.security.setCertificatePromise(function (resolve, reject) {

            fetch(assetBase + 'digital-certificate.txt', {

                cache: 'no-store',

                headers: { 'Content-Type': 'text/plain' }

            })

                .then(function (data) {

                    data.ok ? resolve(data.text()) : reject(data.text());

                })

                .catch(reject);

        });

        qz.security.setSignatureAlgorithm('SHA512');

        qz.security.setSignaturePromise(function (toSign) {

            return function (resolve, reject) {

                fetch(assetBase + 'assets/signing/sign-message.php?request=' + encodeURIComponent(toSign), {

                    cache: 'no-store',

                    headers: { 'Content-Type': 'text/plain' }

                })

                    .then(function (data) {

                        data.ok ? resolve(data.text()) : reject(data.text());

                    })

                    .catch(reject);

            };

        });

        qzSecurityConfigured = true;

    }



    function connectQz() {

        if (typeof qz === 'undefined' || !qz.websocket) {

            return Promise.reject(new Error('QZ Tray is not loaded. Enable QZ Tray in settings and keep it running.'));

        }

        ensureQzSecurity();

        if (qz.websocket.isActive()) {

            return Promise.resolve();

        }

        return qz.websocket.connect({ retries: 3, delay: 1 });

    }



    function connectQzSerial() {

        return connectQz().then(function () {

            if (!qz.serial || typeof qz.serial.findPorts !== 'function') {

                return Promise.reject(new Error('This QZ Tray version does not support serial ports. Update QZ Tray to 2.1 or newer.'));

            }

        });

    }



    function delay(ms) {

        return new Promise(function (resolve) {

            window.setTimeout(resolve, ms);

        });

    }



    function resolvePort() {

        var named = portName();

        if (named) {

            return Promise.resolve(validatePolePort(named));

        }

        return qz.serial.findPorts().then(function (ports) {

            var usable = normalizeDiscoveredPorts(ports);

            if (!usable.length) {

                throw new Error('No COM ports found. Plug in the RS-232 cable, install the serial driver if needed, then type the COM port from Device Manager (e.g. COM3).');

            }

            if (usable.length > 1) {

                throw new Error('Multiple COM ports found (' + usable.join(', ') + '). Select the pole display port in Settings.');

            }

            return usable[0];

        });

    }



    function openPort(port) {

        port = validatePolePort(port);

        var options = {

            baudRate: baudRate(),

            dataBits: 8,

            stopBits: 1,

            parity: 'NONE',

            flowControl: 'NONE',

            encoding: 'ISO-8859-1'

        };

        var variants = windowsComVariants(port);

        var variantIndex = 0;



        function tryOpenVariant() {

            if (variantIndex >= variants.length) {

                return Promise.reject(new Error('Could not open ' + port));

            }

            var variant = variants[variantIndex++];

            return qz.serial.openPort(variant, options).then(function () {

                openPortName = canonicalComPort(port);

                openPortVariant = variant;

                return openPortName;

            }).catch(function (err) {

                if (variantIndex < variants.length) {

                    return tryOpenVariant();

                }

                throw err;

            });

        }



        return tryOpenVariant().catch(function (err) {

            if (!isPortBusyError(err)) {

                throw new Error(formatOpenPortError(port, err));

            }

            return closeQzPort(port)

                .then(function () { return delay(200); })

                .then(function () {

                    variantIndex = 0;

                    return tryOpenVariant();

                });

        }).catch(function (err) {

            if (!isPortBusyError(err)) {

                throw new Error(formatOpenPortError(port, err));

            }

            throw new Error('Port ' + port + ' is busy. Close other POS tabs, restart QZ Tray, then test again.');

        }).then(function (openedPort) {

            return delay(80).then(function () {

                return openedPort;

            });

        });

    }



    function ensureOpen() {

        if (!isEnabled()) {

            return Promise.reject(new Error('Customer display is disabled. Enable it in Settings and save.'));

        }

        if (openPromise) {

            return openPromise;

        }

        openPromise = connectQzSerial()

            .then(resolvePort)

            .then(function (port) {

                var prep = Promise.resolve();

                if (openPortName && openPortName !== port) {

                    prep = closeQzPort(openPortName).then(function () { return delay(150); });

                }

                return prep.then(function () {

                    return openPort(port).then(function () {

                        cfg()._resolvedPort = port;

                        return port;

                    });

                });

            })

            .catch(function (err) {

                openPromise = null;

                throw err;

            });

        return openPromise;

    }



    function sendHex(hex, options) {

        options = options || {};

        if (!options.force && hex === lastFrame) {

            return Promise.resolve();

        }

        lastFrame = hex;

        var task = ensureOpen().then(function () {

            var sendPort = openPortVariant || openPortName;

            return qz.serial.sendData(sendPort, { type: 'HEX', data: hex });

        }).catch(function (err) {

            if (!isPortBusyError(err)) {

                throw err;

            }

            openPromise = null;

            lastFrame = '';

            return closeQzPort(openPortName).then(function () {

                return delay(200);

            }).then(function () {

                return ensureOpen().then(function () {

                    var sendPort = openPortVariant || openPortName;

                    return qz.serial.sendData(sendPort, { type: 'HEX', data: hex });

                });

            });

        });

        if (options.throwOnError) {

            return task.catch(function (err) {

                lastFrame = '';

                openPromise = null;

                throw err;

            });

        }

        sendChain = sendChain.catch(function () {}).then(function () {

            return task;

        }).catch(function (err) {

            lastFrame = '';

            openPromise = null;

            console.warn('PD-320:', err && err.message ? err.message : err);

        });

        return sendChain;

    }



    function write(line1, line2, options) {

        if (!isEnabled()) {

            return Promise.resolve();

        }

        return sendHex(buildFrame(line1, line2), options);

    }



    function holding() {

        return Date.now() < holdUntil;

    }



    function shopName() {

        var info = window.businessInfo || {};

        return info.business_name || info.name || 'WELCOME';

    }



    function showWelcome() {

        if (holding()) {

            return Promise.resolve();

        }

        return write(center20('WELCOME TO'), center20(shopName()));

    }



    function showItem(name, qty, lineTotal, cartTotal) {

        if (holding()) {

            return Promise.resolve();

        }

        var top = asciiSafe(name);

        var bottom = moneyLine('TOTAL', cartTotal != null ? cartTotal : lineTotal);

        return write(top, bottom);

    }



    function syncCart(cart, payableTotal) {

        if (holding()) {

            return Promise.resolve();

        }

        cart = Array.isArray(cart) ? cart : [];

        if (!cart.length) {

            return showWelcome();

        }

        var last = cart[cart.length - 1];

        var lineTotal = last && typeof last.price === 'number' ? last.price : payableTotal;

        return write(asciiSafe(last && last.name ? last.name : 'ITEM'), moneyLine('TOTAL', payableTotal != null ? payableTotal : lineTotal));

    }



    function showTender(total, cash, change) {

        if (holding()) {

            return Promise.resolve();

        }

        var chg = change != null ? change : (Number(cash) - Number(total));

        if (chg < 0) {

            return write(moneyLine('TOTAL', total), moneyLine('DUE', Math.abs(chg)));

        }

        return write(moneyLine('TOTAL', total), moneyLine('CHANGE', chg));

    }



    function showPaid(total, change) {

        holdUntil = Date.now() + 4800;

        if (welcomeTimer) {

            clearTimeout(welcomeTimer);

        }

        var chg = Number(change);

        if (!isFinite(chg)) {

            chg = 0;

        }

        if (chg > 0.004) {

            write(moneyLine('TOTAL', total), moneyLine('CHANGE', chg));

        } else {

            write(moneyLine('TOTAL', total), center20('PAID'));

        }

        window.setTimeout(function () {

            write(center20('THANK YOU'), center20('PLEASE CALL AGAIN'));

        }, 1600);

        welcomeTimer = window.setTimeout(function () {

            holdUntil = 0;

            lastFrame = '';

            showWelcome();

        }, 4800);

        return Promise.resolve();

    }



    function showTest() {

        holdUntil = Date.now() + 2500;

        var port = portName();

        return closeQzPort(port || openPortName).then(function () {

            resetConnection();

            return write(center20('POSIFLEX PD-320'), center20('CONNECTED  OK'), {

                force: true,

                throwOnError: true

            });

        }).then(function () {

            window.setTimeout(function () {

                holdUntil = 0;

                lastFrame = '';

                showWelcome();

            }, 2500);

        });

    }



    function findPorts() {

        return connectQzSerial().then(function () {

            return qz.serial.findPorts();

        }).then(function (ports) {

            return normalizeDiscoveredPorts(ports);

        });

    }



    function applyRuntimeConfig(config) {

        config = config || {};

        if (!window.POS_POLE_DISPLAY) {

            window.POS_POLE_DISPLAY = {};

        }

        if (config.enabled != null) {

            window.POS_POLE_DISPLAY.enabled = !!config.enabled;

        }

        if (config.baud != null) {

            window.POS_POLE_DISPLAY.baud = config.baud;

        }

        if (config.mode != null) {

            window.POS_POLE_DISPLAY.mode = config.mode;

        }

        if (config.port != null) {

            writeThisTillPort(config.port);

        } else {

            resetConnection();

        }

    }



    window.PosPoleDisplay = {

        write: write,

        showWelcome: showWelcome,

        showItem: showItem,

        syncCart: syncCart,

        showTender: showTender,

        showPaid: showPaid,

        showTest: showTest,

        findPorts: findPorts,

        isEnabled: isEnabled,

        applyRuntimeConfig: applyRuntimeConfig,

        closePort: closeQzPort,

        getThisTillPort: function () {

            return portName() || configuredPort() || 'AUTO';

        },

        getResolvedPort: function () {

            return cfg()._resolvedPort || portName() || '';

        },

        polePortError: polePortError,

        validatePolePort: validatePolePort,

        setThisTillPort: writeThisTillPort,

        resetConnection: resetConnection

    };



    function loadTerminalPort() {

        if (configuredPort()) {

            return Promise.resolve(configuredPort());

        }

        var cached = readThisTillPort();

        if (cached) {

            return Promise.resolve(cached);

        }

        if (typeof getTerminalMac !== 'function') {

            return Promise.resolve('');

        }

        var base = (typeof window !== 'undefined' && window.TERMINAL_API_BASE) ? window.TERMINAL_API_BASE : '';

        return getTerminalMac().then(function (mac) {

            return fetch(base + 'get_terminal_settings.php?mac=' + encodeURIComponent(mac), {

                credentials: 'same-origin'

            })

                .then(function (response) { return response.json(); })

                .then(function (data) {

                    if (data && data.success && data.pole_display_port) {

                        var savedPort = sanitizePort(data.pole_display_port);

                        if (savedPort) {

                            writeThisTillPort(savedPort);

                            return savedPort;

                        }

                    }

                    return '';

                });

        }).catch(function () {

            return '';

        });

    }



    function boot() {

        if (!isEnabled()) {

            return;

        }

        loadTerminalPort().finally(function () {

            showWelcome();

        });

    }



    if (document.readyState === 'loading') {

        document.addEventListener('DOMContentLoaded', boot);

    } else {

        boot();

    }

})(window);



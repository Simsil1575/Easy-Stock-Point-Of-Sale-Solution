(function (global) {
    'use strict';

    var MAC_STORAGE_KEY = 'pos_terminal_mac';
    var NAME_STORAGE_KEY = 'pos_terminal_name';
    var DEVICE_ID_KEY = 'pos_device_id';
    var macPromise = null;

    function normalizeMac(mac) {
        if (!mac || typeof mac !== 'string') {
            return null;
        }
        var cleaned = mac.toUpperCase().replace(/[^0-9A-F]/g, '');
        if (cleaned.length !== 12) {
            return null;
        }
        return cleaned.match(/.{1,2}/g).join(':');
    }

    function getFallbackDeviceId() {
        try {
            var existing = localStorage.getItem(DEVICE_ID_KEY);
            if (existing) {
                return existing;
            }
            var uuid = 'UUID:' + (crypto.randomUUID ? crypto.randomUUID() : (
                'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                    var r = Math.random() * 16 | 0;
                    var v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                })
            ));
            localStorage.setItem(DEVICE_ID_KEY, uuid);
            return uuid;
        } catch (e) {
            return 'UUID:UNKNOWN-DEVICE';
        }
    }

    function connectQzIfNeeded() {
        if (typeof qz === 'undefined' || !qz.websocket) {
            return Promise.reject(new Error('QZ Tray not loaded'));
        }
        if (qz.websocket.isActive()) {
            return Promise.resolve();
        }
        return qz.websocket.connect({ retries: 2, delay: 1 });
    }

    function detectMacFromQz() {
        return connectQzIfNeeded().then(function () {
            if (qz.networking && typeof qz.networking.device === 'function') {
                return qz.networking.device();
            }
            if (qz.networking && typeof qz.networking.devices === 'function') {
                return qz.networking.devices().then(function (devices) {
                    return devices && devices.length ? devices[0] : null;
                });
            }
            return null;
        }).then(function (device) {
            if (!device) {
                return null;
            }
            var mac = device.mac || device.macAddress || null;
            return normalizeMac(mac);
        });
    }

    function detectMacFromAndroid() {
        try {
            if (typeof AndroidDevice !== 'undefined' && typeof AndroidDevice.getMacAddress === 'function') {
                var mac = AndroidDevice.getMacAddress();
                return Promise.resolve(normalizeMac(mac));
            }
        } catch (e) {
            // ignore
        }
        return Promise.resolve(null);
    }

    function getTerminalMac() {
        if (macPromise) {
            return macPromise;
        }

        macPromise = Promise.resolve()
            .then(function () {
                try {
                    var cached = sessionStorage.getItem(MAC_STORAGE_KEY) || localStorage.getItem(MAC_STORAGE_KEY);
                    if (cached) {
                        return cached;
                    }
                } catch (e) {
                    // ignore
                }
                return null;
            })
            .then(function (cached) {
                if (cached) {
                    return cached;
                }
                return detectMacFromAndroid();
            })
            .then(function (mac) {
                if (mac) {
                    return mac;
                }
                return detectMacFromQz();
            })
            .then(function (mac) {
                if (!mac) {
                    mac = getFallbackDeviceId();
                }
                try {
                    sessionStorage.setItem(MAC_STORAGE_KEY, mac);
                    localStorage.setItem(MAC_STORAGE_KEY, mac);
                } catch (e) {
                    // ignore
                }
                return mac;
            })
            .catch(function () {
                var fallback = getFallbackDeviceId();
                try {
                    sessionStorage.setItem(MAC_STORAGE_KEY, fallback);
                    localStorage.setItem(MAC_STORAGE_KEY, fallback);
                } catch (e) {
                    // ignore
                }
                return fallback;
            });

        return macPromise;
    }

    function getCachedTerminalName() {
        try {
            return localStorage.getItem(NAME_STORAGE_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function setCachedTerminalName(name) {
        try {
            localStorage.setItem(NAME_STORAGE_KEY, name || '');
        } catch (e) {
            // ignore
        }
    }

    function fetchTerminalNameFromServer(mac) {
        var base = (typeof window !== 'undefined' && window.TERMINAL_API_BASE) ? window.TERMINAL_API_BASE : '';
        return fetch(base + 'get_terminal_settings.php?mac=' + encodeURIComponent(mac), {
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.success && data.terminal_name) {
                    setCachedTerminalName(data.terminal_name);
                    return data.terminal_name;
                }
                return getCachedTerminalName();
            })
            .catch(function () {
                return getCachedTerminalName();
            });
    }

    function getTerminalInfo() {
        return getTerminalMac().then(function (mac) {
            var cachedName = getCachedTerminalName();
            if (cachedName) {
                return { mac: mac, name: cachedName };
            }
            return fetchTerminalNameFromServer(mac).then(function (name) {
                return { mac: mac, name: name || '' };
            });
        });
    }

    function attachTerminalToPayload(payload) {
        return getTerminalInfo().then(function (info) {
            payload.terminal_mac = info.mac;
            if (info.name) {
                payload.terminal_name = info.name;
            }
            return payload;
        });
    }

    function saveTerminalSettings(name) {
        return getTerminalMac().then(function (mac) {
            var base = (typeof window !== 'undefined' && window.TERMINAL_API_BASE) ? window.TERMINAL_API_BASE : '';
            return fetch(base + 'save_terminal_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    terminal_mac: mac,
                    terminal_name: name
                })
            }).then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        setCachedTerminalName(name);
                    }
                    return data;
                });
        });
    }

    global.getTerminalMac = getTerminalMac;
    global.getTerminalInfo = getTerminalInfo;
    global.attachTerminalToPayload = attachTerminalToPayload;
    global.saveTerminalSettings = saveTerminalSettings;
    global.setCachedTerminalName = setCachedTerminalName;
    global.getCachedTerminalName = getCachedTerminalName;
})(typeof window !== 'undefined' ? window : globalThis);

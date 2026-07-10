/**
 * Parse DigitalPersona Web SDK Raw capture into {width,height,dpi,data}.
 * Matches finger/app.js: outer Data is base64url → JSON BioSample → inner Data is pixel base64.
 */
(function (global) {
    'use strict';

    function b64UrlTo64(input) {
        if (typeof Fingerprint !== 'undefined' && Fingerprint.b64UrlTo64) {
            return Fingerprint.b64UrlTo64(input);
        }
        var s = String(input).replace(/-/g, '+').replace(/_/g, '/');
        var pad = s.length % 4;
        if (pad) s += '===='.substring(pad);
        return s;
    }

    function b64UrlToUtf8(input) {
        if (typeof Fingerprint !== 'undefined' && Fingerprint.b64UrlToUtf8) {
            return Fingerprint.b64UrlToUtf8(input);
        }
        var b64 = b64UrlTo64(input);
        try {
            return decodeURIComponent(escape(atob(b64)));
        } catch (e) {
            return atob(b64);
        }
    }

    function parseRawSample(sampleToken) {
        if (!sampleToken) return null;

        if (typeof sampleToken === 'object' && sampleToken.data && sampleToken.width) {
            return {
                width: sampleToken.width,
                height: sampleToken.height,
                dpi: sampleToken.dpi || 500,
                data: sampleToken.data
            };
        }

        var bio;
        try {
            var outer = b64UrlTo64(String(sampleToken));
            bio = JSON.parse(b64UrlToUtf8(outer));
        } catch (e) {
            try {
                bio = JSON.parse(b64UrlToUtf8(String(sampleToken)));
            } catch (e2) {
                return null;
            }
        }

        var fmt = bio.Format || bio.format || {};
        var dataField = bio.Data || bio.data || '';
        var pixels = b64UrlTo64(dataField);
        var width = fmt.iWidth || fmt.width || bio.width || 0;
        var height = fmt.iHeight || fmt.height || bio.height || 0;
        var dpi = fmt.iXdpi || fmt.iYdpi || fmt.dpi || bio.dpi || 500;
        if (!pixels || !width || !height) return null;
        return {
            width: width,
            height: height,
            dpi: dpi,
            data: pixels
        };
    }

    global.fpParseRawSample = parseRawSample;
})(typeof window !== 'undefined' ? window : this);

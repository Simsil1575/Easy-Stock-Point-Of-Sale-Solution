/**
 * Tailwind-styled SweetAlert2 confirmations and alerts (replaces native browser dialogs).
 */
(function (global) {
    'use strict';

    var POPUP_CLASS = 'rounded-2xl shadow-2xl border border-gray-200/90 px-5 py-4 max-w-md !bg-white';
    var TITLE_CLASS = 'text-xl font-semibold text-gray-900 tracking-tight pb-0';
    var HTML_CLASS = 'text-left !mt-3';
    var ACTIONS_CLASS = 'flex flex-row-reverse flex-wrap gap-2 justify-end w-full mt-6 !mb-0 pt-2 border-t border-gray-100';
    var CANCEL_CLASS = 'inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold px-5 py-2.5 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-1';
    var OK_CLASS = 'inline-flex items-center justify-center rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-1';

    var CONFIRM_VARIANTS = {
        danger: 'inline-flex items-center justify-center rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1',
        warning: 'inline-flex items-center justify-center rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1',
        primary: 'inline-flex items-center justify-center rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold px-5 py-2.5 shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-1'
    };

    var ICON_COLORS = {
        success: '#0d9488',
        error: '#dc2626',
        warning: '#d97706',
        info: '#0d9488',
        question: '#0d9488'
    };

    function sharedPopupClasses(confirmClass) {
        return {
            popup: POPUP_CLASS,
            title: TITLE_CLASS,
            htmlContainer: HTML_CLASS,
            actions: ACTIONS_CLASS,
            confirmButton: confirmClass || OK_CLASS,
            cancelButton: CANCEL_CLASS
        };
    }

    function posConfirm(options) {
        var opts = options || {};
        var variant = opts.variant || 'danger';
        var fallbackText = (opts.title || 'Are you sure?') + (opts.text ? '\n\n' + opts.text : '');

        if (typeof global.Swal === 'undefined') {
            return Promise.resolve({ isConfirmed: global.confirm(fallbackText) });
        }

        var config = {
            title: opts.title || 'Are you sure?',
            icon: opts.icon || 'warning',
            iconColor: opts.iconColor || (variant === 'primary' ? '#0d9488' : '#d97706'),
            showCancelButton: true,
            focusCancel: true,
            confirmButtonText: opts.confirmButtonText || 'Confirm',
            cancelButtonText: opts.cancelButtonText || 'Cancel',
            buttonsStyling: false,
            reverseButtons: true,
            customClass: sharedPopupClasses(CONFIRM_VARIANTS[variant] || CONFIRM_VARIANTS.danger)
        };

        if (opts.html) {
            config.html = opts.html;
        } else if (opts.text) {
            config.text = opts.text;
        }

        return global.Swal.fire(config);
    }

    function posAlert(options) {
        var opts = options || {};
        var icon = opts.icon || 'info';
        var fallbackText = (opts.title || '') + (opts.text ? '\n\n' + opts.text : '');

        if (typeof global.Swal === 'undefined') {
            global.alert(fallbackText || (opts.title || 'Notice'));
            return Promise.resolve({ isConfirmed: true });
        }

        var config = {
            title: opts.title || 'Notice',
            icon: icon,
            iconColor: opts.iconColor || ICON_COLORS[icon] || ICON_COLORS.info,
            showCancelButton: false,
            confirmButtonText: opts.confirmButtonText || 'OK',
            buttonsStyling: false,
            customClass: sharedPopupClasses(OK_CLASS)
        };

        if (opts.html) {
            config.html = opts.html;
        } else if (opts.text) {
            config.text = opts.text;
        }

        if (opts.timer) {
            config.timer = opts.timer;
            config.showConfirmButton = opts.showConfirmButton !== false;
        }

        return global.Swal.fire(config);
    }

    function confirmPosFormSubmit(event, options) {
        if (event) {
            event.preventDefault();
        }
        var form = event && event.target;
        if (!form) {
            return false;
        }
        posConfirm(options).then(function (result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
        return false;
    }

    global.posConfirm = posConfirm;
    global.posAlert = posAlert;
    global.confirmPosFormSubmit = confirmPosFormSubmit;
})(window);

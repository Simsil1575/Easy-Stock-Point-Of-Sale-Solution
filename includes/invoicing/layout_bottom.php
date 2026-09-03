            </main>
        </div>
    </div>
    <script>
        // ---- Toast ----
        function invToast(message, type = 'info') {
            document.querySelectorAll('.inv-toast').forEach(t => t.remove());
            const el = document.createElement('div');
            const bg = type === 'success' ? '#0d9488' : type === 'error' ? '#e11d48' : '#0d9488';
            el.className = 'inv-toast';
            el.style.background = bg;
            const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
            el.innerHTML = '<i class="fas ' + icon + '"></i><span></span>';
            el.querySelector('span').textContent = message;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3200);
        }

        // ---- API helper ----
        async function invApi(action, payload = {}) {
            const res = await fetch('<?= $invBase ?? '../' ?>invoicing_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ action }, payload))
            });
            let data;
            try { data = await res.json(); }
            catch (e) { throw new Error('Unexpected server response.'); }
            if (!data.success) { throw new Error(data.message || 'Request failed.'); }
            return data.data || {};
        }

        // ---- Confirm dialog (SweetAlert2 with fallback) ----
        function invConfirm(opts) {
            const o = Object.assign({ title: 'Are you sure?', text: '', confirmText: 'Confirm', icon: 'warning', danger: false }, opts);
            if (typeof Swal === 'undefined') {
                return Promise.resolve(window.confirm((o.title ? o.title + '\n' : '') + (o.text || '')));
            }
            return Swal.fire({
                title: o.title,
                text: o.text,
                icon: o.icon,
                showCancelButton: true,
                confirmButtonText: o.confirmText,
                confirmButtonColor: o.danger ? '#e11d48' : '#2563eb',
                cancelButtonColor: '#6b7280'
            }).then(r => r.isConfirmed);
        }

        function invMoneyFmt(n) {
            return (window.INV_CURRENCY || 'N$') + ' ' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function invEscapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function invEmailFromButton(btn) {
            return invEmailDocument({
                id: parseInt(btn.getAttribute('data-id') || '0', 10) || 0,
                type: btn.getAttribute('data-type') || window.INV_TYPE || 'invoice',
                email: btn.getAttribute('data-email') || '',
                name: btn.getAttribute('data-name') || '',
                number: btn.getAttribute('data-number') || '',
                reload: btn.getAttribute('data-reload') === '1'
            });
        }

        /**
         * Prompt for a recipient and send the professional PDF via existing SMTP.
         * Resolves true when sent, false when cancelled or failed.
         */
        async function invEmailDocument(opts) {
            const o = Object.assign({ id: 0, type: 'invoice', email: '', name: '', number: '', reload: false }, opts || {});
            const isQuote = o.type === 'quotation';
            const label = isQuote ? 'Quotation' : 'Invoice';
            const subtitle = o.number ? invEscapeHtml(o.number) + (o.name ? ' · ' + invEscapeHtml(o.name) : '') : '';
            if (typeof Swal === 'undefined') {
                const to = window.prompt('Recipient email for ' + label + (o.number ? ' ' + o.number : ''), o.email || '');
                if (!to) return false;
                try {
                    const data = await invApi('send_document', { type: o.type, id: o.id, to: to.trim(), message: '' });
                    invToast((label + ' sent to ' + (data.recipient || to) + '.'), 'success');
                    if (o.reload) setTimeout(() => location.reload(), 700);
                    return true;
                } catch (err) {
                    invToast(err.message || 'Email sending failed.', 'error');
                    return false;
                }
            }
            const result = await Swal.fire({
                title: 'Email ' + label,
                html:
                    (subtitle ? '<p style="margin:0 0 12px;font-size:13px;color:#64748b;text-align:left;">' + subtitle + '</p>' : '') +
                    '<label style="display:block;text-align:left;font-size:12px;color:#64748b;margin-bottom:4px;">Recipient email *</label>' +
                    '<input id="invEmailTo" class="swal2-input" type="email" placeholder="client@example.com" value="' + invEscapeHtml(o.email || '') + '" style="width:100%;margin:0 0 12px;box-sizing:border-box;">' +
                    '<label style="display:block;text-align:left;font-size:12px;color:#64748b;margin-bottom:4px;">Optional message to the client</label>' +
                    '<textarea id="invEmailMsg" class="swal2-textarea" placeholder="Add a short note (optional)" style="width:100%;margin:0;box-sizing:border-box;min-height:90px;"></textarea>' +
                    '<p style="margin:10px 0 0;font-size:12px;color:#94a3b8;text-align:left;">The professional PDF will be attached automatically.</p>',
                confirmButtonText: 'Send Email',
                confirmButtonColor: '#0d9488',
                showCancelButton: true,
                focusConfirm: false,
                preConfirm: () => {
                    const to = (document.getElementById('invEmailTo').value || '').trim();
                    if (!to) {
                        Swal.showValidationMessage('Enter a recipient email address.');
                        return false;
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to.split(/[,;]/)[0].trim())) {
                        Swal.showValidationMessage('Enter a valid email address.');
                        return false;
                    }
                    return { to, message: (document.getElementById('invEmailMsg').value || '').trim() };
                }
            });
            if (!result.isConfirmed || !result.value) return false;

            Swal.fire({
                title: 'Sending ' + label.toLowerCase() + '...',
                text: 'Please wait while the PDF is emailed.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading()
            });
            try {
                const data = await invApi('send_document', {
                    type: o.type,
                    id: o.id,
                    to: result.value.to,
                    message: result.value.message
                });
                Swal.close();
                invToast((label + ' sent to ' + (data.recipient || result.value.to) + '.'), 'success');
                if (o.reload) setTimeout(() => location.reload(), 700);
                return true;
            } catch (err) {
                Swal.close();
                invToast(err.message || 'Email sending failed.', 'error');
                return false;
            }
        }
    </script>
    <?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>

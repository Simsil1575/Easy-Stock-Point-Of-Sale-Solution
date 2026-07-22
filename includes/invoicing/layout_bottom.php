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
            const res = await fetch('../invoicing_ajax.php', {
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
    </script>
    <?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>

<?php if (!empty($maCanDeletePatient)): ?>
<script>
function medicalAidDeletePatient(patientId, patientName, redirectUrl) {
    const needsPin = <?= !empty($maRequiresVoidPinForDelete) ? 'true' : 'false' ?>;
    const opts = {
        title: 'Delete patient?',
        text: 'Remove "' + patientName + '" from Medical Aid?' + (needsPin ? ' Enter manager void PIN.' : ''),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc2626',
    };
    const runDelete = (pin) => {
        fetch('<?= htmlspecialchars($maBase ?? '') ?>process_medical_aid_delete_patient.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ patient_id: patientId, manager_pin: pin || '' }),
        })
            .then((r) => r.json())
            .then((d) => {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: d.mode === 'deactivated' ? 'Deactivated' : 'Deleted',
                        text: d.message,
                        timer: 1500,
                        showConfirmButton: false,
                    }).then(() => {
                        window.location.href = redirectUrl;
                    });
                } else {
                    Swal.fire('Error', d.message || 'Failed to delete patient', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Request failed', 'error'));
    };
    if (needsPin) {
        Swal.fire(Object.assign({}, opts, {
            input: 'password',
            inputLabel: 'Manager void PIN',
            inputAttributes: { autocapitalize: 'off', autocomplete: 'off', inputmode: 'numeric' },
        })).then((res) => {
            if (res.isConfirmed) {
                runDelete(res.value || '');
            }
        });
    } else {
        Swal.fire(opts).then((res) => {
            if (res.isConfirmed) {
                runDelete('');
            }
        });
    }
}
</script>
<?php endif; ?>

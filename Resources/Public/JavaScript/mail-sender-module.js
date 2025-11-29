/**
 * Mail Sender Module JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {
    const moduleContainer = document.getElementById('mail-sender-module');
    if (!moduleContainer) {
        return;
    }

    // Get URLs from data attributes
    const validateUrl = moduleContainer.dataset.validateUrl;
    const deleteUrl = moduleContainer.dataset.deleteUrl;

    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.sender-checkbox');
    const bulkActions = document.getElementById('bulk-actions');

    // Update bulk actions visibility
    function updateBulkActions() {
        const checked = document.querySelectorAll('.sender-checkbox:checked');
        if (bulkActions) {
            bulkActions.style.display = checked.length > 0 ? 'inline' : 'none';
        }
    }

    // Select all
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateBulkActions();
        });
    }

    // Individual checkboxes
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateBulkActions);
    });

    // Get selected UIDs
    function getSelectedUids() {
        return Array.from(document.querySelectorAll('.sender-checkbox:checked'))
            .map(function(cb) { return cb.value; });
    }

    // Single row actions - Validate
    document.querySelectorAll('.single-validate-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var uid = this.dataset.uid;
            var form = document.getElementById('validateForm');
            document.getElementById('validateUids').value = uid;
            form.action = validateUrl;
            form.submit();
        });
    });

    // Single row actions - Delete
    document.querySelectorAll('.single-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this sender address?')) {
                var uid = this.dataset.uid;
                var form = document.getElementById('deleteForm');
                document.getElementById('deleteUids').value = uid;
                form.action = deleteUrl;
                form.submit();
            }
        });
    });

    // Test email modal - single sender
    document.querySelectorAll('.single-test-email-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var uid = this.dataset.uid;
            var email = this.dataset.email;
            document.getElementById('testEmailUids').value = uid;
            document.getElementById('selectedSendersList').innerHTML = '<li>' + escapeHtml(email) + '</li>';
        });
    });

    // Bulk actions - Validate
    var bulkValidateBtn = document.getElementById('bulk-validate-btn');
    if (bulkValidateBtn) {
        bulkValidateBtn.addEventListener('click', function() {
            var uids = getSelectedUids();
            if (uids.length > 0) {
                var form = document.getElementById('validateForm');
                form.innerHTML = '';
                uids.forEach(function(uid) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'uids[]';
                    input.value = uid;
                    form.appendChild(input);
                });
                form.action = validateUrl;
                form.submit();
            }
        });
    }

    // Bulk actions - Delete
    var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            var uids = getSelectedUids();
            if (uids.length > 0 && confirm('Are you sure you want to delete ' + uids.length + ' sender address(es)?')) {
                var form = document.getElementById('deleteForm');
                form.innerHTML = '';
                uids.forEach(function(uid) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'uids[]';
                    input.value = uid;
                    form.appendChild(input);
                });
                form.action = deleteUrl;
                form.submit();
            }
        });
    }

    // Bulk test email
    var bulkTestEmailBtn = document.getElementById('bulk-test-email-btn');
    if (bulkTestEmailBtn) {
        bulkTestEmailBtn.addEventListener('click', function() {
            var uids = getSelectedUids();
            var form = document.getElementById('testEmailForm');
            // Remove old uid inputs
            form.querySelectorAll('input[name="uids[]"]').forEach(function(el) {
                el.remove();
            });
            // Add new ones
            uids.forEach(function(uid) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'uids[]';
                input.value = uid;
                form.appendChild(input);
            });
            // Update info display
            var senders = [];
            document.querySelectorAll('.sender-checkbox:checked').forEach(function(cb) {
                var row = cb.closest('tr');
                var email = row.querySelector('td:nth-child(2)').textContent.trim();
                senders.push('<li>' + escapeHtml(email) + '</li>');
            });
            document.getElementById('selectedSendersList').innerHTML = senders.join('');
        });
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});

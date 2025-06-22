jQuery(document).ready(function($) {
    'use strict';
    const reviewBox = $('#hs-admin-review-box');
    if (!reviewBox.length) { return; }
    
    // --- Main Review Actions ---
    const actionsWrapper = reviewBox.find('.hs-admin-actions');
    const approveBtn = $('#hs-approve-btn');
    const rejectPromptBtn = $('#hs-reject-btn-prompt');
    const rejectConfirmBtn = $('#hs-reject-btn-confirm');
    const rejectCancelBtn = $('#hs-reject-cancel-btn');
    const rejectionWrapper = $('#rejection-reason-wrapper');
    const rejectionTextarea = $('#rejection_reason');
    const messageDiv = $('#hs-action-message');
    const mainSpinner = actionsWrapper.find('.spinner').first();

    approveBtn.on('click', function() {
        if (!confirm('آیا از تأیید این کاربر اطمینان دارید؟ نقش او به «تأیید شده» تغییر خواهد کرد.')) { return; }
        performAction('hs_approve_user', $(this).data('user-id'), {}, mainSpinner, actionsWrapper.find('button'));
    });

    rejectPromptBtn.on('click', function() {
        rejectionWrapper.slideDown();
        approveBtn.hide();
        rejectPromptBtn.hide();
    });

    rejectCancelBtn.on('click', function() {
        rejectionWrapper.slideUp();
        rejectionTextarea.val('');
        approveBtn.show();
        rejectPromptBtn.show();
    });

    rejectConfirmBtn.on('click', function() {
        const reason = rejectionTextarea.val();
        if (!reason.trim()) {
            alert('لطفاً دلیل رد پروفایل را وارد کنید.');
            rejectionTextarea.focus();
            return;
        }
        performAction('hs_reject_user', $(this).data('user-id'), { reason: reason }, mainSpinner, actionsWrapper.find('button'));
    });

    // --- Admin Notes Action ---
    const notesWrapper = $('#hs-admin-notes-wrapper');
    const saveNoteBtn = $('#hs-save-note-btn');
    const notesSpinner = notesWrapper.find('.spinner');

    saveNoteBtn.on('click', function(){
        const note = $('#hs_admin_private_notes').val();
        performAction('hs_save_admin_note', $(this).data('user-id'), { note: note }, notesSpinner, saveNoteBtn, function(response) {
            // On success, just show a temporary message
            const originalText = saveNoteBtn.text();
            saveNoteBtn.text(response.data.message).css('background-color', '#46b450');
            setTimeout(function(){
                 saveNoteBtn.text(originalText).css('background-color', '');
            }, 2500);
        });
    });

    // --- Ban User Actions ---
    const banWrapper = $('#hs-ban-user-wrapper');
    const banBtn = $('#hs-confirm-ban-btn');
    const unbanBtn = $('#hs-unban-btn');
    const banDateInput = $('#hs_ban_until_date');
    const banSpinner = banWrapper.find('.spinner');
    const banMessageDiv = $('#hs-ban-message');

    banBtn.on('click', function() {
        const banUntil = banDateInput.val();
        if (!banUntil) {
            alert('لطفاً تاریخ و زمان پایان مسدودیت را مشخص کنید.');
            banDateInput.focus();
            return;
        }
        if (!confirm('آیا از مسدود کردن این کاربر تا تاریخ مشخص شده اطمینان دارید؟')) { return; }
        performAction('hs_ban_user', $(this).data('user-id'), { ban_until: banUntil }, banSpinner, banWrapper.find('button'), () => location.reload());
    });
    
    unbanBtn.on('click', function() {
        if (!confirm('آیا از رفع مسدودیت این کاربر اطمینان دارید؟')) { return; }
        performAction('hs_unban_user', $(this).data('user-id'), {}, banSpinner, banWrapper.find('button'), () => location.reload());
    });


    /**
     * Generic AJAX handler for admin actions.
     * @param {string} action - The WordPress AJAX action name.
     * @param {number} userId - The target user ID.
     * @param {object} additionalData - Extra data to send with the request.
     * @param {jQuery} spinner - The spinner element to show/hide.
     * @param {jQuery} buttons - The button elements to disable/enable.
     * @param {function} [customSuccessCallback] - Optional callback on success instead of default behavior.
     */
    function performAction(action, userId, additionalData = {}, spinner, buttons, customSuccessCallback) {
        spinner.addClass('is-active');
        buttons.prop('disabled', true);
        const data = { action: action, _ajax_nonce: hs_admin_data.nonce, user_id: userId, ...additionalData };

        $.post(ajaxurl, data)
            .done(function(response) {
                if (response.success) {
                    if (typeof customSuccessCallback === 'function') {
                        customSuccessCallback(response);
                    } else {
                        showMessage(response.data.message, 'success', spinner.parent().find('.notice'));
                        if (action === 'hs_approve_user' || action === 'hs_reject_user') {
                            actionsWrapper.find('button').hide();
                            rejectionWrapper.hide();
                        }
                    }
                } else {
                    showMessage(response.data.message || 'یک خطای ناشناخته رخ داد.', 'error', spinner.parent().find('.notice'));
                    buttons.prop('disabled', false);
                }
            })
            .fail(function() { 
                showMessage('خطای سرور. لطفاً دوباره تلاش کنید.', 'error', spinner.parent().find('.notice')); 
                buttons.prop('disabled', false); 
            })
            .always(function() { 
                spinner.removeClass('is-active'); 
            });
    }

    function showMessage(message, type, messageDiv) {
        messageDiv.text(message).removeClass('notice-success notice-error').addClass(type === 'success' ? 'notice-success' : 'notice-error').slideDown();
        setTimeout(() => messageDiv.slideUp(), 5000);
    }
});

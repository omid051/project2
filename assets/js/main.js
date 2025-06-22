(function($) {
    'use strict';

    /**
     * Helper function to convert Persian/Arabic numbers to English numbers.
     * @param {string} str The string to convert.
     * @returns {string} The converted string.
     */
    function convertPersianToEnglish(str) {
        if (typeof str !== 'string' || !str) return '';
        const p2e = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9'};
        const a2e = {'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};
        return str.replace(/[۰-۹]/g, c => p2e[c]).replace(/[٠-٩]/g, c => a2e[c]);
    }

    /**
     * Helper function to show non-blocking notifications (toasts).
     * @param {string} message The message to display.
     * @param {string} type 'success' or 'error'.
     * @param {number} duration Duration in milliseconds.
     */
    function showNotification(message, type = 'success', duration = 4000) {
        let container = $('#hs-notification-container');
        if (!container.length) {
            container = $('<div id="hs-notification-container" style="position: fixed; top: 20px; right: 20px; z-index: 10000;"></div>').appendTo('body');
        }
        const notification = $(`<div class="hs-notification" style="background-color: ${type === 'success' ? '#4CAF50' : '#f44336'}; color: white; padding: 15px; margin-bottom: 10px; border-radius: 5px;">${message}</div>`).appendTo(container).hide().fadeIn(300);
        setTimeout(() => {
            notification.fadeOut(400, function() {
                $(this).remove();
            });
        }, duration);
    }
    
    /**
     * Helper function to show a confirmation modal.
     * @param {string} message The confirmation message.
     * @param {function} onConfirm The callback function to execute on confirmation.
     */
    function showConfirm(message, onConfirm) {
        $('.hs-modal-overlay').remove();
        const content = `<div class="hs-modal-content"><p>${message}</p><div class="hs-modal-actions"><button class="hs-button" id="hs-confirm-yes">بله، مطمئنم</button><button class="hs-button secondary" id="hs-confirm-no">خیر</button></div></div>`;
        const modal = $(`<div class="hs-modal-overlay hs-confirm-modal">${content}</div>`).appendTo('body');
        
        setTimeout(() => modal.addClass('active'), 10);

        modal.on('click', '#hs-confirm-yes', function() {
            modal.remove();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });
        
        modal.on('click', function(e) {
            if (e.target === this || $(e.target).is('#hs-confirm-no')) {
                $(this).removeClass('active').fadeOut(200, function() { $(this).remove(); });
            }
        });
    }

    // --- Province/City Dropdown Logic (Global) ---
    const initProvinceCity = function() {
        const populateCities = (provinceSelect) => {
            const province = provinceSelect.val();
            const citySelect = $('#' + provinceSelect.data('city-target'));
            const currentCityVal = citySelect.data('saved-value');
            
            citySelect.empty().append('<option value="">' + (province ? 'شهر را انتخاب کنید' : 'ابتدا استان را انتخاب کنید') + '</option>');
            
            if (province && hs_ajax_data.provinces_cities[province]) {
                hs_ajax_data.provinces_cities[province].forEach(c => citySelect.append($('<option>', { value: c, text: c })));
                if(currentCityVal) {
                    citySelect.val(currentCityVal);
                    // Clear the saved value after using it to prevent conflicts
                    citySelect.data('saved-value', ''); 
                }
            }
        };

        $('.hs-province-select').each(function() {
            const provinceSelect = $(this);
            const savedProvince = provinceSelect.data('saved-value');
            const provinces = Object.keys(hs_ajax_data.provinces_cities);

            // Ensure we don't duplicate options on ajax reloads
            if(provinceSelect.find('option').length <= 1) {
                provinces.forEach(p => provinceSelect.append($('<option>', { value: p, text: p })));
            }

            if (savedProvince) { 
                provinceSelect.val(savedProvince); 
            }
            populateCities(provinceSelect); // Populate cities on initial load
        });
        
        // Use event delegation for dynamically added selects
        $(document).on('change', '.hs-province-select', function() {
            populateCities($(this));
        });
    };

    // --- Main Profile Form Logic ---
    const formWrapper = $('#hs-multistep-form-wrapper');
    if (formWrapper.length) {
        let currentStep = 1;
        const totalSteps = $('.hs-form-step').length;
        const form = $('#hs-profile-form');
        const prevBtn = $('#hs-prev-btn'), nextBtn = $('#hs-next-btn'), submitBtn = $('#hs-submit-btn'), loader = form.find('.hs-loader');

        const updateFormState = (isLocked) => {
            form.data('locked', isLocked);
            form.find('input, select, textarea').prop('disabled', isLocked);
            updateButtonVisibility();
        };
        
        const refreshUserStatus = () => {
             $.post(hs_ajax_data.ajax_url, { action: 'hs_get_user_status', nonce: hs_ajax_data.nonce })
             .done(response => {
                if(response.success){
                    const data = response.data;
                    updateFormState(!data.is_editable);
                    
                    formWrapper.find('.hs-message').hide();

                    if(data.roles.includes('hs_rejected')){
                        const rejectionMessageEl = $('#hs-message-rejected');
                        if (rejectionMessageEl.length) {
                             rejectionMessageEl.find('.rejection-reason-text').html(data.rejection_reason_html || 'نامشخص');
                             rejectionMessageEl.show();
                        }
                    } else if (data.roles.includes('hs_approved')){
                        $('#hs-message-approved').show();
                    } else if (data.roles.includes('hs_pending')){
                        $('#hs-message-pending').show();
                    }
                }
             });
        };
        
        const checkConditions = () => {
            $('[data-condition-field]').each(function() {
                const conditionalEl = $(this), fieldName = conditionalEl.data('condition-field'), requiredValue = String(conditionalEl.data('condition-value')).split(','), compare = conditionalEl.data('condition-compare') || '==', controller = $('[name="' + fieldName + '"]'), controllerValue = controller.is(':radio,:checkbox') ? $('[name="' + fieldName + '"]:checked').val() : controller.val();
                let show = (compare === '!=') ? (controllerValue && !requiredValue.includes(controllerValue)) : requiredValue.includes(controllerValue);
                const isCurrentlyVisible = conditionalEl.is(':visible');
                if(show && !isCurrentlyVisible) conditionalEl.slideDown(200);
                if(!show && isCurrentlyVisible) conditionalEl.slideUp(200);
                conditionalEl.find('input, select, textarea').prop('required', show);
            });
        };

        const showStep = (step) => { $('.hs-form-step').hide(); $('#hs-step-' + step).show(); updateButtonVisibility(); };
        
        const updateButtonVisibility = () => {
            let isLocked = form.data('locked');
            prevBtn.toggle(currentStep > 1 && !isLocked);
            nextBtn.toggle(currentStep < totalSteps && !isLocked);
            submitBtn.toggle(currentStep === totalSteps && !isLocked);
        };
        
        nextBtn.on('click', () => { 
            if (validateStep(currentStep)) {
                saveStepData(false, () => {
                    if (currentStep < totalSteps) { currentStep++; showStep(currentStep); }
                });
            }
        });
        prevBtn.on('click', () => { if (currentStep > 1) { currentStep--; showStep(currentStep); } });
        form.on('submit', (e) => { e.preventDefault(); if (validateAllSteps()) saveStepData(true); });
        
        form.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (nextBtn.is(':visible')) {
                    nextBtn.trigger('click');
                } else if (submitBtn.is(':visible')) {
                    form.trigger('submit');
                }
            }
        });

        $(document).on('input', 'input[type="tel"], input[type="number"]', function() {
            this.value = convertPersianToEnglish(this.value);
        });

        function saveStepData(isFinal, callback) {
            loader.show(); prevBtn.add(nextBtn).add(submitBtn).prop('disabled', true);
            
            const formData = new FormData(form[0]);
            formData.append('action', 'hs_save_profile_form'); 
            formData.append('nonce', hs_ajax_data.nonce);
            formData.append('form_data', form.serialize());
            
            if (isFinal) {
                formData.append('final_submission', 'true');
            }

            $.ajax({ url: hs_ajax_data.ajax_url, type: 'POST', data: formData, processData: false, contentType: false,
                success: (res) => {
                    if (res.success) {
                        if (isFinal) {
                            $('#hs-form-messages').html(hs_ajax_data.messages.final_submission_success).removeClass('error').addClass('notice success').slideDown();
                            updateFormState(true);
                        } else if (typeof callback === 'function') {
                            callback();
                        }
                    } else {
                         showNotification(res.data?.message || hs_ajax_data.messages.error_saving, 'error');
                    }
                },
                error: () => { showNotification(hs_ajax_data.messages.error_saving, 'error'); },
                complete: () => { loader.hide(); prevBtn.add(nextBtn).add(submitBtn).prop('disabled', false); }
            });
        }
        
        const validationPatterns = {
            'mobile_phone': /^09[0-9]{9}$/,
            'national_code': /^[0-9]{10}$/,
            'landline_phone': /^0[0-9]{10}$/,
            'postal_code': /^[0-9]{10}$/,
        };

        function validateStep(stepNumber) {
            let isValid = true; const scope = $('#hs-step-' + stepNumber);
            scope.find('.hs-field-error').text(''); scope.find('.has-error').removeClass('has-error');
            scope.find('input, select, textarea').not(':disabled').each(function() {
                const field = $(this);
                if (!field.is(':visible') || !field.prop('required')) return;
                
                let hasError = false;
                let value = field.val();
                let errorMessage = hs_ajax_data.messages.field_required;

                if (field.is(':radio')) { if (!$('input[name="' + field.attr('name') + '"]:checked').length) hasError = true;
                } else if (field.is(':file')) { if (field[0].files.length === 0 && !field.data('existing-file')) hasError = true;
                } else if (!value) { hasError = true; }
                
                if (!hasError && value) {
                    const pattern = field.attr('pattern');
                    if(pattern && !new RegExp(pattern).test(value)) {
                        hasError = true;
                        errorMessage = field.data('validation-message') || 'ورودی معتبر نیست.';
                    }
                }

                if (hasError) {
                    field.closest('.hs-form-group').find('.hs-field-error').text(errorMessage);
                    field.addClass('has-error');
                    isValid = false;
                }
            });
            return isValid;
        }

        function validateAllSteps() {
            let allValid = true;
            for(let i = 1; i <= totalSteps; i++) {
                if(!validateStep(i)) {
                    if(allValid) { 
                        showStep(i); showNotification(hs_ajax_data.messages.final_submission_error, 'error');
                        $('html, body').animate({ scrollTop: $('.has-error').first().offset().top - 100 }, 500);
                    }
                    allValid = false;
                }
            }
            return allValid;
        }
        
        refreshUserStatus();
        initProvinceCity();
        checkConditions();
        form.on('change', 'select, input[type=radio], input[type=checkbox]', checkConditions);
        showStep(currentStep);
    } else {
        // Run this for other pages like user-listing
        initProvinceCity();
    }
    
    // --- Matchmaking System Logic ---
    $(document).on('click', '#hs-toggle-search', () => $('#hs-search-form').slideToggle());
    
    $(document).on('click', '#hs-send-request-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        showConfirm('آیا از ارسال درخواست آشنایی برای این کاربر اطمینان دارید؟', () => {
            button.prop('disabled', true).text('در حال ارسال...');
            $.post(hs_ajax_data.ajax_url, { action: 'hs_send_request', nonce: hs_ajax_data.nonce, receiver_id: button.data('receiver-id') })
            .done((res) => {
                if (res.success) { showNotification(res.data.message, 'success'); setTimeout(() => window.location.reload(), 2000);
                } else { showNotification('خطا: ' + (res.data.message || 'مشکلی رخ داد.'), 'error'); button.prop('disabled', false).text('درخواست آشنایی'); }
            }).fail(() => { showNotification('خطای سرور.', 'error'); button.prop('disabled', false).text('درخواست آشنایی'); });
        });
    });
    
    $(document).on('click', '.hs-profile-actions button[data-action], .hs-requests-list button[data-action]', function(e) {
        e.preventDefault();
        const button = $(this);
        const action = button.data('action');
        const requestId = button.data('request-id');
        const originalText = button.text();
        button.data('original-text', originalText);
        showConfirm(action === 'accept' ? 'آیا این درخواست را تایید می‌کنید؟' : 'آیا این درخواست را رد می‌کنید؟', () => {
            button.closest('li, .hs-profile-actions').find('button').prop('disabled', true);
            button.text('صبر کنید...');
            $.post(hs_ajax_data.ajax_url, { action: 'hs_handle_request_action', nonce: hs_ajax_data.nonce, request_id: requestId, request_action: action })
            .done((res) => {
                if (res.success) { showNotification(res.data.message, 'success'); setTimeout(() => window.location.reload(), 2000);
                } else { showNotification('خطا: ' + (res.data.message || 'مشکلی رخ داد.'), 'error'); button.closest('li, .hs-profile-actions').find('button').prop('disabled', false).text(originalText); }
            }).fail(() => { showNotification('خطای سرور.', 'error'); button.closest('li, .hs-profile-actions').find('button').prop('disabled', false).text(originalText); });
        });
    });

    $(document).on('click', '#hs-cancel-request-btn', function() {
        const button = $(this);
        const requestId = button.data('request-id');
        const isMale = button.data('is-male');
        const warningHtml = isMale ? '<p class="hs-message warning"><b>هشدار:</b> پس از لغو، حساب شما به مدت ۲۴ ساعت قفل خواهد شد.</p>' : '';
        const content = `<div class="hs-modal-content"><span class="hs-close-button">&times;</span><h3>لغو درخواست</h3><p>لطفاً دلیل لغو درخواست خود را وارد کنید.</p>${warningHtml}<textarea id="hs-cancellation-reason" rows="5" placeholder="دلیل لغو..." required></textarea><button id="hs-confirm-cancel-btn" class="hs-button danger" data-request-id="${requestId}">تایید و لغو</button></div>`;
        const modal = $(`<div id="hs-cancellation-modal" class="hs-modal-overlay">${content}</div>`).appendTo('body');
        setTimeout(() => modal.addClass('active'), 10);
    });
    
    // Close modal by clicking overlay or close button
    $(document).on('click', '.hs-modal-overlay', function(e) {
        if (e.target === this || $(e.target).hasClass('hs-close-button')) {
            $(this).removeClass('active').fadeOut(200, function() { $(this).remove(); });
        }
    });
    // Prevent modal from closing when clicking inside content
    $(document).on('click', '.hs-modal-content', function(e) {
        e.stopPropagation();
    });


    $(document).on('click', '#hs-confirm-cancel-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const reason = $('#hs-cancellation-reason').val();
        if (reason.trim() === '') { showNotification('لطفاً دلیل لغو درخواست را وارد کنید.', 'error'); return; }
        button.prop('disabled', true).text('در حال لغو...');
        $.post(hs_ajax_data.ajax_url, { action: 'hs_cancel_request', nonce: hs_ajax_data.nonce, request_id: button.data('request-id'), reason: reason })
        .done((res) => {
            if (res.success) {
                $('.hs-modal-overlay').remove();
                showNotification(res.data.message, 'success');
                setTimeout(() => window.location.reload(), 3000);
            } else { showNotification('خطا: ' + (res.data.message || 'ناشناخته'), 'error'); button.prop('disabled', false).text('تایید و لغو'); }
        }).fail(() => { showNotification('خطای سرور.', 'error'); button.prop('disabled', false).text('تایید و لغو'); });
    });

})(jQuery);

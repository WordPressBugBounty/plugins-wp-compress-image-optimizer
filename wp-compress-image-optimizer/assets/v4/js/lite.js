jQuery(document).ready(function ($) {


    $('.wpc-lite-toggle-advanced').on('click', function (e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpsChangeGui',
                view: 'advanced',
                nonce: ajaxVar.nonce,
            },
            success: function (response) {
                window.location.reload();
            }
        });

        return false;
    });


    $('.wpc-custom-btn.wpc-custom-btn-locked').on('click', function (e) {
        e.preventDefault();
        lockedPopup();
        return false;
    });

    $('.wpc-box-for-checkbox-lite').on('click', function (e) {
        e.preventDefault();

        if ($(this).hasClass('wpc-locked')) {
            lockedPopup();
            return false;
        }

        var parent = $(this);
        var checkbox = $('.wpc-ic-settings-v4-checkbox', parent);
        var beforeValue = $(checkbox).is(':checked');
        var value = 0;
        var option = $(checkbox).attr('name');

        if (beforeValue) {
            value = 0;
            $('.wpc-ic-settings-v4-checkbox', parent).removeAttr('checked').prop('checked', false);
        } else {
            value = 1;
            $('.wpc-ic-settings-v4-checkbox', parent).attr('checked', 'checked').prop('checked', true);
        }

        $('.save-button').fadeIn(500);

        return false;
    });


    $('.save-button-lite').on('click', function(e){
        e.preventDefault();

        $('.save-button').fadeOut(500, function(){
            $('.wpc-loading-spinner').show();
            $('.wpc-lite-form').submit();
        });

        return false;
    });


    $('.wps-ic-initial-retest').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_resetTest',
                nonce: ajaxVar.nonce,
            },
            success: function (response) {
                setTimeout(function () {
                    window.location.reload();
                }, 1000);
            }
        });
        return false;
    });

    function initializeCircleProgressBars() {
        // Find all elements with the class 'page-stats-circle'
        const circles = document.querySelectorAll('.page-stats-circle');

        // Loop through each circle
        circles.forEach(circle => {
            const progressBar = circle.querySelector('.circle-progress-bar-lite');
            const textElement = circle.querySelector('.stats-circle-text h5');

            // Get the data-value attribute
            const value = parseFloat(progressBar.getAttribute('data-value'));

            // Determine the gradient based on the value
            let gradient;
            let bgFill;
            if (value <= 0.55) {
                gradient = ['#FF0000', '#FF6347']; // Red gradient
                bgFill = '#FFE6E6';
            } else if (value <= 0.89) {
                gradient = ['#FFD700', '#FFA500'];
                bgFill = '#FEF7ED';
            } else if (value <= 0.89) {
                gradient = ['#FFD700', '#FFA500'];
                bgFill = '#FEF7ED';
            } else {
                gradient = ['#61CB70', '#3caa4b']; // Green gradient
                bgFill = '#E9FBEE';
            }

            // Initialize the circle progress bar with the determined gradient
            $(progressBar).circleProgress({
                value: value, // Use the data-value attribute
                size: 100,
                thickness: '10',
                startAngle: -Math.PI / 2,
                fill: {gradient: gradient},
                emptyFill: bgFill
            });

            // Update the text element
            textElement.textContent = Math.round(value * 100); // Convert to whole number
        });
    }

    initializeCircleProgressBars();


});
var integrationActive = 'true';

document.addEventListener('DOMContentLoaded', function () {
    var delayedSections = document.querySelectorAll('.wpc-delay-elementor');

    delayedSections.forEach(function (section) {
        section.classList.remove('wpc-delay-elementor');
    });

    document.dispatchEvent(new Event('WPCContentLoaded'))
});

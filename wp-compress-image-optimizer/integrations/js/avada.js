var integrationActive = 'true';

document.addEventListener('DOMContentLoaded', function () {
    var delayedSections = document.querySelectorAll('.wpc-delay-avada');

    delayedSections.forEach(function (section) {
        section.classList.remove('wpc-delay-avada');
    });

    document.dispatchEvent(new Event('WPCContentLoaded'))
});
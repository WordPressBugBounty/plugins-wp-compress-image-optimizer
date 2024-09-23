var integrationActive = 'true';

document.addEventListener('DOMContentLoaded', function () {
    var delayedSections = document.querySelectorAll('.wpc-delay-divi');

    delayedSections.forEach(function (section) {
        section.classList.remove('wpc-delay-divi');
    });

    document.dispatchEvent(new Event('WPCContentLoaded'))
});

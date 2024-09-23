const
    range = document.getElementById('optimizationLevel'),
    setValue = () => {
        const
            newValue = Number((range.value - range.min) * 100 / (range.max - range.min)),
            newPosition = 16 - (newValue * 0.32);
        document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);
    };

document.addEventListener("DOMContentLoaded", setValue);
range.addEventListener('input', setValue);
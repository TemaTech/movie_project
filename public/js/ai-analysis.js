class AiAnalysis {
    static toggleDrawer(event, element) {
        event.stopPropagation();
        event.preventDefault();

        // Unified Overlay Logic for both Card and List Item
        const card = element.closest('.top-card, .list-item');
        if (card) {
            const overlay = card.querySelector('.ai-overlay');
            if (overlay) {
                if (overlay.classList.contains('visible')) {
                    overlay.classList.remove('visible');
                    element.classList.remove('active');
                } else {
                    overlay.classList.add('visible');
                    element.classList.add('active');
                }
            }
        }
    }
}

// Global expose
window.toggleAi = AiAnalysis.toggleDrawer;

// Initialize Overlay Listeners
document.addEventListener('DOMContentLoaded', () => {
    // Attach click listeners to all existing overlays to handle "close on click"
    // and prevent event bubbling to the card (which would open the modal).
    const overlays = document.querySelectorAll('.ai-overlay');
    overlays.forEach(overlay => {
        overlay.addEventListener('click', (event) => {
            // Stop the click from reaching the parent .list-item or .top-card
            event.stopPropagation();
            event.preventDefault();

            // Close the overlay
            overlay.classList.remove('visible');

            // Find and deactivate the trigger button
            const card = overlay.closest('.top-card, .list-item');
            if (card) {
                const btn = card.querySelector('.ai-trigger-btn');
                if (btn) {
                    btn.classList.remove('active');
                }
            }
        });
    });
});

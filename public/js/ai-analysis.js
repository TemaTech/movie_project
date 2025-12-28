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

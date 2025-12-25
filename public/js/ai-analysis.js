class AiAnalysis {
    static toggleDrawer(event, element) {
        event.stopPropagation();
        event.preventDefault();

        // Specific logic for List Item (Drawer)
        const wrapper = element.closest('.list-item');
        if (wrapper) {
            const drawer = wrapper.querySelector('.ai-drawer');
            const icon = element.querySelector('.ai-sparkle-icon');
            
            if (drawer.style.maxHeight) {
                drawer.style.maxHeight = null;
                drawer.classList.remove('open');
                element.classList.remove('active');
            } else {
                // Close other drawers if needed (optional, keeping it simple for now)
                drawer.classList.add('open');
                drawer.style.maxHeight = drawer.scrollHeight + "px";
                element.classList.add('active');
            }
            return;
        }

        // Specific logic for Card (Overlay)
        const card = element.closest('.top-card');
        if (card) {
            const overlay = card.querySelector('.ai-overlay');
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

// Global expose
window.toggleAi = AiAnalysis.toggleDrawer;

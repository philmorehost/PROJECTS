/**
 * DGV6.90 AI Edition — Zero-Latency Page Guides
 * Checks localStorage to show page-specific guides once per day.
 */
document.addEventListener('DOMContentLoaded', function() {
    const page = window.location.pathname.split('/').pop() || 'index.php';
    const storageKey = `ai_guide_last_seen_${page}`;
    const today = new Date().toISOString().slice(0, 10);

    // Only show once per day
    if (localStorage.getItem(storageKey) === today) return;

    fetch('/web/data/ai-guides.json')
        .then(res => res.json())
        .then(data => {
            const guide = data[page];
            if (!guide) return;

            showGuideModal(guide.title, guide.steps);
            localStorage.setItem(storageKey, today);
        })
        .catch(err => console.error('AI Guide Error:', err));
});

function showGuideModal(title, steps) {
    // Create modal elements dynamically
    const modalHtml = `
    <div class="modal fade" id="aiGuideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary">${title}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="d-flex flex-column gap-3">
                        ${steps.map((step, i) => `
                            <div class="d-flex align-items-start gap-3">
                                <span class="badge bg-primary rounded-circle d-flex align-items-center justify-content-center" style="width:24px;height:24px;flex-shrink:0;">${i+1}</span>
                                <p class="mb-0 small text-muted">${step}</p>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 small" data-bs-dismiss="modal">Got it!</button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('aiGuideModal'));
    modal.show();

    // Clean up after hide
    document.getElementById('aiGuideModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}


function startUserGuide() {
    const steps = [
        {
            title: "Welcome to " + (window.siteTitle || "Philmore Codes") + "!",
            content: "We're glad to have you here. This quick guide will show you how to navigate and use our features.",
            element: null
        },
        {
            title: "Your Wallet Balance",
            content: "This is your primary NGN wallet. You can also view multi-currency wallets (USD, GBP, etc.) in the pills below.",
            element: ".balance-hero"
        },
        {
            title: "Quick Funding",
            content: "Need to top up? Click 'Fund' to pay with your ATM card or view your automated bank accounts.",
            element: ".quick-action-btn:first-child"
        },
        {
            title: "Our Services",
            content: "From here, you can buy Data, Airtime, Cable TV, and more. Simply click an icon to begin.",
            element: ".row-cols-3"
        },
        {
            title: "Automated Funding",
            content: "Transfer money to any of these accounts to fund your wallet automatically and instantly.",
            element: ".card:has(h5:contains('Auto Funding'))"
        }
    ];
    runGuide(steps);
}

function startAdminGuide() {
    const steps = [
        {
            title: "Admin Dashboard",
            content: "Welcome, Admin! This is your control center for managing your users and services.",
            element: null
        },
        {
            title: "System Function",
            content: "Configure your site settings, payment gateways, and security from this menu.",
            element: "#system-func-nav"
        },
        {
            title: "API Manager",
            content: "Connect to third-party APIs and set your profit margins for all services.",
            element: "#api-manager-nav"
        },
        {
            title: "Service Control",
            content: "Use the Service Control Center to toggle visibility of services for your users.",
            element: "a[href*='ServiceControl.php']"
        }
    ];
    runGuide(steps);
}

function startSpadminGuide() {
    const steps = [
        {
            title: "Super Admin Hub",
            content: "Welcome back! This is where you manage the entire multi-tenant platform and all vendors.",
            element: null
        },
        {
            title: "Manage Vendors",
            content: "Create new vendor instances, approve registrations, and manage domain settings here.",
            element: "#manage-vendor-nav"
        },
        {
            title: "Global Billing",
            content: "Set up billing packages and track subscriptions across all vendors.",
            element: "#manage-billing-nav"
        }
    ];
    runGuide(steps);
}

let currentStep = 0;
let guideSteps = [];

function runGuide(steps) {
    guideSteps = steps;
    currentStep = 0;
    showStep();
}

function showStep() {
    const step = guideSteps[currentStep];
    const overlay = document.getElementById('guide-overlay') || createOverlay();
    const card = document.getElementById('guide-card') || createCard();

    overlay.style.display = 'block';
    card.style.display = 'block';

    card.innerHTML = `
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden" style="width: 320px;">
            <div class="card-header bg-primary text-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">${step.title}</h6>
                <button type="button" class="btn-close btn-close-white small" onclick="closeGuide()"></button>
            </div>
            <div class="card-body p-4">
                <p class="text-muted mb-4" style="font-size: 14px;">${step.content}</p>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${currentStep + 1} of ${guideSteps.length}</span>
                    <div>
                        ${currentStep > 0 ? '<button class="btn btn-sm btn-light me-2" onclick="prevStep()">Back</button>' : ''}
                        <button class="btn btn-sm btn-primary px-3" onclick="${currentStep === guideSteps.length - 1 ? 'closeGuide()' : 'nextStep()'}">
                            ${currentStep === guideSteps.length - 1 ? 'Finish' : 'Next'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    if (step.element) {
        const el = document.querySelector(step.element);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const rect = el.getBoundingClientRect();
            // Optional: highlight logic
        }
    }
}

function nextStep() {
    if (currentStep < guideSteps.length - 1) {
        currentStep++;
        showStep();
    }
}

function prevStep() {
    if (currentStep > 0) {
        currentStep--;
        showStep();
    }
}

function closeGuide() {
    document.getElementById('guide-overlay').style.display = 'none';
    document.getElementById('guide-card').style.display = 'none';
    localStorage.setItem('guide_completed', 'true');
}

function createOverlay() {
    const div = document.createElement('div');
    div.id = 'guide-overlay';
    div.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 10000; display: none;';
    document.body.appendChild(div);
    return div;
}

function createCard() {
    const div = document.createElement('div');
    div.id = 'guide-card';
    div.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10001; display: none;';
    document.body.appendChild(div);
    return div;
}

// Auto-start for new users
window.addEventListener('DOMContentLoaded', () => {
    if (!localStorage.getItem('guide_completed')) {
        setTimeout(() => {
            const path = window.location.pathname;
            if (path.includes('/web/Dashboard.php')) startUserGuide();
            else if (path.includes('/bc-admin/Dashboard.php')) startAdminGuide();
            else if (path.includes('/bc-spadmin/Dashboard.php')) startSpadminGuide();
        }, 2000);
    }
});

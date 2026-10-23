<?php session_start();
include("../func/bc-config.php");

if(!isset($_SESSION["user_session"])){
    header("Location: Login.php");
    exit();
}

// Ensure the user has AI enabled - notice instead of redirect
$ai_enabled = true; // Always unlocked for standalone

$vendor_id = $get_logged_user_details['vendor_id'];
$get_all_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vendor_id'"));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>AI Assistant Terminal | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">

    <style>
        :root {
            --ai-bg: #0f0716;
            --ai-card: #1a1025;
            --ai-accent: #9333ea;
            --ai-accent-hover: #a855f7;
            --ai-text: #f8fafc;
            --ai-text-muted: #94a3b8;
            --ai-bubble-bot: #251835;
            --ai-bubble-user: #9333ea;
            --ai-danger: #ef4444;
            --ai-success: #22c55e;
        }

        body {
            background-color: var(--ai-bg);
            color: var(--ai-text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
        }

        .terminal-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .terminal-header {
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(15, 7, 22, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(147, 51, 234, 0.2);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .terminal-title {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin: 0;
        }

        .wallet-pill {
            background: rgba(147, 51, 234, 0.1);
            border: 1px solid rgba(147, 51, 234, 0.3);
            padding: 0.5rem 1.25rem;
            border-radius: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .wallet-pill i { color: var(--ai-accent); }
        .wallet-amount { color: var(--ai-accent); font-weight: 700; }

        .terminal-body {
            flex: 1;
            display: flex;
            overflow: hidden;
            padding: 1.5rem;
            gap: 1.5rem;
        }

        /* Sidebar Styling */
        .ai-sidebar {
            width: 320px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .sidebar-card {
            background: var(--ai-card);
            border-radius: 1.25rem;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar-card h6 {
            color: #ff2d55;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        .sidebar-card p {
            font-size: 0.8rem;
            color: var(--ai-text-muted);
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .prompt-btn {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--ai-text);
            width: 100%;
            text-align: center;
            padding: 0.75rem 1rem;
            border-radius: 2rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .prompt-btn:hover {
            background: rgba(147, 51, 234, 0.1);
            border-color: var(--ai-accent);
            transform: translateX(5px);
        }

        .legend-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .legend-btn {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--ai-text);
            padding: 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s;
        }

        .legend-btn:hover {
            border-color: var(--ai-accent);
            background: rgba(147, 51, 234, 0.05);
        }

        /* Chat Terminal Styling */
        .chat-main {
            flex: 1;
            background: var(--ai-card);
            border-radius: 1.5rem;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        #chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .chat-bubble {
            max-width: 80%;
            padding: 1rem 1.5rem;
            border-radius: 1.25rem;
            font-size: 0.95rem;
            line-height: 1.6;
            position: relative;
        }

        .bubble-bot {
            align-self: flex-start;
            background: var(--ai-bubble-bot);
            border-bottom-left-radius: 0.25rem;
            color: #e2e8f0;
        }

        .bubble-user {
            align-self: flex-end;
            background: var(--ai-bubble-user);
            border-bottom-right-radius: 0.25rem;
            color: white;
            box-shadow: 0 10px 15px -3px rgba(147, 51, 234, 0.3);
        }

        /* Transaction Card Styling */
        .tx-confirm-card {
            background: #11091a;
            border: 1px solid rgba(147, 51, 234, 0.3);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-top: 1rem;
            width: 100%;
            max-width: 400px;
        }

        .tx-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ff2d55;
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            text-transform: uppercase;
        }

        .tx-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .tx-label { color: var(--ai-text-muted); }
        .tx-value { font-weight: 700; }

        .tx-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .btn-confirm {
            background: var(--ai-accent);
            color: white;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 0.5rem;
            font-weight: 700;
            font-size: 0.85rem;
            flex: 1;
            transition: all 0.2s;
        }

        .btn-confirm:hover { background: var(--ai-accent-hover); transform: scale(1.02); }

        .btn-cancel {
            background: #1a1025;
            color: var(--ai-text-muted);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 0.6rem 1rem;
            border-radius: 0.5rem;
            font-weight: 700;
            font-size: 0.85rem;
            flex: 1;
            transition: all 0.2s;
        }

        .btn-cancel:hover { background: rgba(255, 255, 255, 0.05); color: white; }

        /* Input Area Styling */
        .terminal-input-area {
            padding: 1.5rem 2rem;
            background: rgba(26, 16, 37, 0.5);
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .input-wrapper {
            flex: 1;
            position: relative;
        }

        #terminal-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(147, 51, 234, 0.3);
            border-radius: 3rem;
            padding: 0.85rem 1.5rem;
            color: white;
            font-size: 1rem;
            outline: none;
            transition: all 0.2s;
        }

        #terminal-input:focus {
            border-color: var(--ai-accent);
            box-shadow: 0 0 0 4px rgba(147, 51, 234, 0.1);
        }

        .send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9333ea, #db2777);
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 15px -3px rgba(147, 51, 234, 0.4);
        }

        .send-btn:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 20px 25px -5px rgba(147, 51, 234, 0.5);
        }

        /* Responsive */
        @media (max-width: 991px) {
            .ai-sidebar { display: none; }
            .terminal-body { padding: 1rem; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(147, 51, 234, 0.2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(147, 51, 234, 0.4); }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chat-bubble { animation: fadeIn 0.3s ease forwards; }
    </style>
</head>
<body>

<div class="terminal-container">
    <header class="terminal-header">
        <div class="d-flex align-items-center">
            <a href="javascript:history.back()" class="btn btn-outline-light btn-sm rounded-pill me-3" style="border-color: rgba(255,255,255,0.2);"><i class="bi bi-arrow-left"></i> Back</a>
            <h1 class="terminal-title">AI Assistant Terminal</h1>
        </div>
        <div class="wallet-pill">
            <i class="bi bi-wallet2"></i>
            <span class="small text-muted">Wallet Balance:</span>
            <span class="wallet-amount">₦<?php echo number_format($get_logged_user_details['balance'], 2); ?></span>
        </div>
    </header>

    <div class="terminal-body">
        <!-- Sidebar -->
        <aside class="ai-sidebar">
            <div class="sidebar-card">
                <h6><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
                <p class="mb-3">Select an action below. Edit the Network, Amount, Data Type, and Phone Number in the chat box, then press send. After the AI responds, type 'Yes' to confirm.</p>

                <div class="mb-3">
                    <p class="x-small mb-2 opacity-50 fw-bold">DATA TYPES: SME, Shared Data, CG Data, DD (Direct Data)</p>
                    <div class="legend-grid mb-3">
                        <button class="legend-btn" onclick="replaceDataType('SME')">SME</button>
                        <button class="legend-btn" onclick="replaceDataType('Shared Data')">Shared</button>
                        <button class="legend-btn" onclick="replaceDataType('CG Data')">CG</button>
                        <button class="legend-btn" onclick="replaceDataType('DD (Direct Data)')">DD</button>
                    </div>
                </div>

                <button class="prompt-btn" onclick="injectPrompt('Buy MTN 500 airtime for 08012345678')">Buy Airtime</button>
                <button class="prompt-btn" onclick="injectPrompt('Buy MTN 1GB SME data for 08123456789')">Buy Data</button>

                <div class="mb-2">
                    <button class="prompt-btn mb-1" onclick="toggleUtility()">Pay Utility <i class="bi bi-chevron-down ms-1"></i></button>
                    <div id="utility-btns" style="display: none; padding-left: 1rem;">
                        <button class="prompt-btn small py-1" onclick="injectPrompt('Pay IKEDC 1000 for meter 11223344556')">PHCN (IKEDC)</button>
                        <button class="prompt-btn small py-1" onclick="injectPrompt('Pay DSTV 5000 for smartcard 1020304050')">Cable (DSTV)</button>
                        <button class="prompt-btn small py-1" onclick="injectPrompt('Topup Bet9ja 1000 for ID 12345678')">Betting (Bet9ja)</button>
                    </div>
                </div>

                <button class="prompt-btn" onclick="injectPrompt('Buy 1 WAEC pin')">Buy Exam Pin</button>
            </div>

            <div class="sidebar-card" style="margin-top: auto;">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 p-2 rounded">
                        <i class="bi bi-cpu text-primary fs-4"></i>
                    </div>
                    <div>
                        <div class="small fw-bold">AI Status</div>
                        <div class="x-small text-success">Online & Learning</div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Chat Area -->
        <main class="chat-main">

            <div id="chat-history">
                <div class="chat-bubble bubble-bot">
                    Hello! I'm your AI Assistant. I can help you buy airtime, data, pay bills and more.
                    Select an action from the sidebar or type what you want to buy.
                </div>
            </div>

            <div class="terminal-input-area">
                <div class="input-wrapper">
                    <input type="text" id="terminal-input" placeholder="Type prompt: e.g. Buy MTN 500 airtime for 08031234567" autocomplete="off">
                </div>
                <button class="send-btn" id="send-trigger">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </main>
    </div>
</div>

<script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const chatHistory = document.getElementById('chat-history');
    const terminalInput = document.getElementById('terminal-input');
    const sendTrigger = document.getElementById('send-trigger');

    function injectPrompt(text) {
        terminalInput.value = text;
        terminalInput.focus();

        // Smart highlight for phone numbers, amounts or meters
        const phoneMatch = text.match(/0[789][01]\d{8}/) || text.match(/\d{11}/);
        if (phoneMatch) {
            const start = text.indexOf(phoneMatch[0]);
            terminalInput.setSelectionRange(start, start + phoneMatch[0].length);
        } else {
            const amountMatch = text.match(/\d{3,5}/);
            if (amountMatch) {
                const start = text.indexOf(amountMatch[0]);
                terminalInput.setSelectionRange(start, start + amountMatch[0].length);
            }
        }
    }

    function toggleUtility() {
        const div = document.getElementById('utility-btns');
        div.style.display = div.style.display === 'none' ? 'block' : 'none';
    }

    function replaceDataType(type) {
        let val = terminalInput.value;
        if (!val.toLowerCase().includes('data')) {
            injectPrompt(`Buy MTN 1GB ${type} data for 08123456789`);
            return;
        }
        // Try to replace existing data type (SME, CG, Shared, DD)
        const types = ['SME', 'Shared Data', 'CG Data', 'Direct Data', 'DD'];
        let replaced = false;
        types.forEach(t => {
            if (val.includes(t)) {
                terminalInput.value = val.replace(t, type);
                replaced = true;
            }
        });
        if (!replaced) {
            // Just insert before 'data'
            terminalInput.value = val.replace(/data/i, type + ' data');
        }
        terminalInput.focus();
    }

    async function sendMessage() {
        const prompt = terminalInput.value.trim();
        if (!prompt) return;

        appendMessage('user', prompt);
        terminalInput.value = '';

        try {
            const response = await fetch('ai-handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt: prompt, action: 'chat' })
            });
            const data = await response.json();

            if (data.status === 'success') {
                appendMessage('bot', data.response);
                if (data.pending_vtu) {
                    appendTransactionCard(data.pending_vtu);
                }
            } else {
                appendMessage('bot', '❌ Error: ' + data.message);
            }
        } catch (error) {
            appendMessage('bot', '❌ Connection error. Please try again.');
        }
    }

    function appendMessage(role, text) {
        const bubble = document.createElement('div');
        bubble.className = `chat-bubble bubble-${role}`;
        bubble.innerText = text;
        chatHistory.appendChild(bubble);
        chatHistory.scrollTop = chatHistory.scrollHeight;
    }

    function appendTransactionCard(vtu) {
        const botBubble = document.createElement('div');
        botBubble.className = 'chat-bubble bubble-bot';

        const card = `
            <div class="tx-confirm-card">
                <div class="tx-header">
                    <i class="bi bi-shield-lock-fill"></i> Transaction Confirmation
                </div>
                <div class="tx-row">
                    <span class="tx-label">Type:</span>
                    <span class="tx-value">${vtu.service.toUpperCase()}</span>
                </div>
                <div class="tx-row">
                    <span class="tx-label">Provider:</span>
                    <span class="tx-value">${vtu.network}</span>
                </div>
                <div class="tx-row">
                    <span class="tx-label">Recipient:</span>
                    <span class="tx-value">${vtu.phone}</span>
                </div>
                <div class="tx-row">
                    <span class="tx-label">Cost:</span>
                    <span class="tx-value">₦${vtu.amount}</span>
                </div>
                <div class="tx-actions">
                    <button class="btn-confirm" onclick="confirmTx(this)">Yes, Process Now</button>
                    <button class="btn-cancel" onclick="this.closest('.chat-bubble').remove()">No, Cancel</button>
                </div>
            </div>
        `;
        botBubble.innerHTML = `I detected you want to buy ${vtu.service} for ${vtu.phone}. Let's confirm.` + card;
        chatHistory.appendChild(botBubble);
        chatHistory.scrollTop = chatHistory.scrollHeight;
    }

    async function confirmTx(btn) {
        btn.disabled = true;
        btn.innerText = 'Processing...';

        try {
            const response = await fetch('ai-handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt: 'yes', action: 'execute_vtu' })
            });
            const data = await response.json();

            if (data.status === 'success') {
                appendMessage('bot', data.response);
                btn.closest('.tx-confirm-card').innerHTML = '<div class="text-success fw-bold p-2"><i class="bi bi-check-circle-fill"></i> Transaction Completed</div>';
            } else {
                appendMessage('bot', '❌ Failed: ' + data.message);
                btn.disabled = false;
                btn.innerText = 'Retry';
            }
        } catch (error) {
            appendMessage('bot', '❌ Error connecting to server.');
            btn.disabled = false;
            btn.innerText = 'Retry';
        }
    }

    sendTrigger.addEventListener('click', sendMessage);
    terminalInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });
</script>

</body>
</html>

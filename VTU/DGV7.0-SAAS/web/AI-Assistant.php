<?php session_start();
include("../func/bc-config.php");

if(!isset($_SESSION["user_session"])){
    header("Location: Login.php");
    exit();
}

?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("AI Assistant | " . ($get_all_site_details["site_title"] ?? 'Platform'));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "AI ASSISTANT TERMINAL", "crumb" => "Home / AI Assistant")); ?>

    <section class="section dashboard">
        <div class="ai-terminal-container">
            <div class="card ai-terminal-card">
                <div class="ai-status-indicator">
                    <div class="status-dot"></div>
                    AI ENGINE ACTIVE
                </div>

                <div id="chat-window">
                    <div class="bubble bot">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="bg-indigo bg-opacity-10 p-2 rounded-circle">
                                <i class="bi bi-robot text-indigo"></i>
                            </div>
                            <span class="fw-bold text-indigo">AI Systems Ready</span>
                        </div>
                        Hello! I am your VTU Service Intelligence. You can command me using natural language.<br><br>
                        <strong>Example:</strong> "Buy 1GB MTN SME for 08012345678" or "Pay 2000 for IKEDC prepaid 11223344556".
                    </div>
                </div>

                <div class="ai-input-wrapper">
                    <div class="quick-actions-bar">
                        <div class="action-pill" onclick="quickPrompt('airtime')"><i class="bi bi-telephone"></i> Airtime</div>
                        <div class="action-pill" onclick="quickPrompt('data')"><i class="bi bi-wifi"></i> Data Bundle</div>
                        <div class="action-pill" onclick="quickPrompt('electric')"><i class="bi bi-lightbulb"></i> Electricity</div>
                        <div class="action-pill" onclick="quickPrompt('cable')"><i class="bi bi-tv"></i> Cable TV</div>
                        <div class="action-pill" onclick="quickPrompt('betting')"><i class="bi bi-tag"></i> Betting</div>
                        <div class="action-pill" onclick="quickPrompt('exam')"><i class="bi bi-card-list"></i> Exam Pins</div>
                    </div>
                    <div class="terminal-input-group">
                        <input type="text" id="user-input" placeholder="Enter command or ask a question..." autocomplete="off">
                        <button class="send-button shadow-sm" onclick="sendMessage()">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        const chatWindow = document.getElementById('chat-window');
        const userInput = document.getElementById('user-input');

        window.addEventListener('load', () => {
            const params = new URLSearchParams(window.location.search);
            const initialPrompt = params.get('prompt');
            if (initialPrompt) {
                userInput.value = initialPrompt;
                sendMessage();
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        function quickPrompt(type) {
            let text = "";
            switch(type) {
                case 'airtime': text = "Buy MTN airtime N100 for 07064436690"; break;
                case 'data':    text = "Buy MTN 1GB SME data for 08123456789"; break;
                case 'electric': text = "Pay IKEDC 1000 for meter 11223344556"; break;
                case 'cable':   text = "Renew DSTV Compact for 1234567890"; break;
                case 'betting': text = "Fund Sportybet 1000 for ID 7012345678"; break;
                case 'exam':    text = "Buy 1 WAEC pin"; break;
            }
            userInput.value = text;
            userInput.focus();
        }

        function appendMessage(text, side, isHtml = false) {
            const div = document.createElement('div');
            div.className = `bubble ${side}`;
            if (isHtml) div.innerHTML = text;
            else div.textContent = text;
            chatWindow.appendChild(div);
            chatWindow.scrollTop = chatWindow.scrollHeight;
        }

        async function sendMessage() {
            const text = userInput.value.trim();
            if(!text) return;

            const isConfirmation = /\b(yes|confirm|proceed|go ahead|yep|sure|ok|do it|okay|process)\b/i.test(text);
            const pendingVtu = sessionStorage.getItem('pending_vtu');
            let action = 'chat';

            if (isConfirmation && pendingVtu) {
                action = 'execute_vtu';
            } else if (/send|buy|recharge|topup|data|airtime|pay|pin|exam|meter|decoder|id|bet|phcn/i.test(text)) {
                action = 'voice_vtu';
            }

            appendMessage(text, 'user');
            userInput.value = "";

            const loading = document.createElement('div');
            loading.className = 'bubble bot';
            loading.innerHTML = '<div class="spinner-border spinner-border-sm text-indigo" role="status"></div><span class="ms-2 small fw-bold">AI Processing...</span>';
            chatWindow.appendChild(loading);
            chatWindow.scrollTop = chatWindow.scrollHeight;

            try {
                const payload = new URLSearchParams();
                payload.append('prompt', text);
                payload.append('action', action);
                if (action === 'execute_vtu' && pendingVtu) {
                    payload.append('intent', pendingVtu);
                    sessionStorage.removeItem('pending_vtu');
                }

                const response = await fetch('ai-handler.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: payload.toString()
                });
                
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error("Malformed JSON response:", responseText);
                    throw new Error("Server returned a malformed response. Please try again.");
                }

                chatWindow.removeChild(loading);

                if (data.status === 'success') {
                    appendMessage(data.response.replace(/\\n/g, '<br>'), 'bot', true);
                    if (data.pending_vtu) {
                        sessionStorage.setItem('pending_vtu', JSON.stringify(data.pending_vtu));
                    }
                } else {
                    appendMessage(data.message || "I encountered an error processing that.", 'bot');
                }
            } catch(e) {
                if(loading.parentNode) chatWindow.removeChild(loading);
                appendMessage(e.message || "Connection failed. Please check your internet and try again.", 'bot');
            }
        }

        userInput.addEventListener('keypress', (e) => {
            if(e.key === 'Enter') sendMessage();
        });
    </script>

	<?php bc_app_foot(); ?>

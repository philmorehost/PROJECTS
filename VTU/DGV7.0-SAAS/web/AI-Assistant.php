<?php session_start();
include("../func/bc-config.php");

if(!isset($_SESSION["user_session"])){
    header("Location: Login.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>AI Assistant | <?php echo $get_all_site_details["site_title"] ?? 'Platform'; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="../assets-2/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets-2/css/style.css">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <style>
        :root {
            --ai-indigo: #4f46e5;
            --ai-indigo-light: #818cf8;
            --ai-indigo-dark: #3730a3;
            --ai-bg: #fdfdfd;
            --ai-chat-bg: #f8fafc;
            --ai-glass: rgba(255, 255, 255, 0.8);
        }

        .ai-terminal-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .ai-terminal-card {
            height: calc(100vh - 220px);
            min-height: 500px;
            display: flex;
            flex-direction: column;
            border-radius: 1.5rem;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 20px 50px -12px rgba(0,0,0,0.1);
            position: relative;
            transition: transform 0.3s ease;
        }

        #chat-window {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            scrollbar-width: thin;
            scroll-behavior: smooth;
        }

        .bubble {
            max-width: 80%;
            padding: 1rem 1.25rem;
            border-radius: 1.25rem;
            font-size: 0.95rem;
            line-height: 1.6;
            position: relative;
            animation: aiFadeIn 0.3s ease-out;
        }

        @keyframes aiFadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .bubble.bot {
            align-self: flex-start;
            background: #fff;
            color: #334155;
            border-bottom-left-radius: 0.25rem;
            box-shadow: 0 4px 15px -3px rgba(0, 0, 0, 0.08);
            border: 1px solid #edf2f7;
            border-left: 4px solid var(--ai-indigo);
        }

        .bubble.user {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--ai-indigo) 0%, var(--ai-indigo-dark) 100%);
            color: #fff;
            border-bottom-right-radius: 0.25rem;
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.4);
        }

        .ai-input-wrapper {
            padding: 1.5rem 2rem;
            background: #fff;
            border-top: 1px solid #f1f5f9;
        }

        .quick-actions-bar {
            display: flex;
            gap: 0.75rem;
            overflow-x: auto;
            padding-bottom: 1.25rem;
            scrollbar-width: none;
        }
        .quick-actions-bar::-webkit-scrollbar { display: none; }

        .action-pill {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            padding: 0.6rem 1.25rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        .action-pill:hover {
            border-color: var(--ai-indigo);
            color: var(--ai-indigo);
            background: #fff;
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }

        .terminal-input-group {
            background: #f8fafc;
            border-radius: 1.25rem;
            padding: 0.6rem 0.6rem 0.6rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
        }

        .terminal-input-group:focus-within {
            background: #fff;
            border-color: var(--ai-indigo);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        #user-input {
            border: none;
            background: transparent;
            padding: 0.75rem 0;
            font-size: 1rem;
            width: 100%;
            outline: none;
            color: #1e293b;
        }

        .send-button {
            background: var(--ai-indigo);
            color: #fff;
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
            flex-shrink: 0;
        }

        .send-button:hover {
            background: var(--ai-indigo-dark);
            transform: scale(1.05);
        }

        .ai-status-indicator {
            position: absolute;
            top: 1.25rem;
            right: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #10b981;
            background: rgba(16, 185, 129, 0.1);
            padding: 0.4rem 0.75rem;
            border-radius: 50px;
            z-index: 10;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        @media (max-width: 768px) {
            .ai-terminal-card { height: calc(100vh - 180px); }
            .bubble { max-width: 90%; }
            #chat-window { padding: 1.25rem; }
        }
    </style>
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
      <h1>AI ASSISTANT TERMINAL</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">AI Assistant</li>
        </ol>
      </nav>
    </div>

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
<?php include("../func/bc-footer.php"); ?>
</body>
</html>

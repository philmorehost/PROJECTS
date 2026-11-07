<?php
// AI Educator Copilot View (Comment generator, Lesson planner, Study Buddy chatbot, Dropout warning regression charts)
if (!defined('PORTAL_PATH')) exit;

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$ai_result = '';

// Handle AI Generations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once PORTAL_PATH . '/include/ai_helper.php';

    if (isset($_POST['generate_comment'])) {
        $student = trim($_POST['student_name']);
        $grades = trim($_POST['grades_summary']);
        $behaviour = trim($_POST['behaviour_summary']);
        
        $prompt = "Generate a professional, descriptive, and actionable teacher's report card remark for a student named {$student}. Their grade performance is: {$grades}. General classroom behaviour: {$behaviour}. Keep it encouraging yet constructive.";
        $ai_result = AIHelper::generateText($prompt);
    } elseif (isset($_POST['generate_lesson'])) {
        $subject = trim($_POST['subject']);
        $topic = trim($_POST['topic']);
        $class = trim($_POST['class_level']);
        
        $prompt = "Create a detailed structured weekly lesson plan for subject: {$subject}, topic: {$topic}, class level: {$class}. Include objectives, learning modules, brief lecture notes summary, and a weekly classroom assignment.";
        $ai_result = AIHelper::generateText($prompt);
    }
}

// Fetch warnings/regression alerts
$stmtAlerts = $pdo->prepare("SELECT * FROM sm_ai_warnings WHERE school_id_number = ? ORDER BY id DESC");
$stmtAlerts->execute([$school_id]);
$warnings = $stmtAlerts->fetchAll();
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🤖 AI Educator Copilot</h2>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=ai_educator&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition text-sm">Dashboard Overview</a>
            <a href="?page=ai_educator&action=comments" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-semibold">Comment Generator</a>
            <a href="?page=ai_educator&action=planner" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition text-sm font-semibold">Lesson Planner</a>
        </div>
    </div>

    <?php if ($action === 'list') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Dropout Early Warning regression warnings panel -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-2 text-red-400">⚠️ Dropout Warning Regression System</h3>
                <p class="text-xs text-gray-400 mb-4">AI-driven predictive regression analyzing attendance declines and grade deviations to flag students at drop risk.</p>
                <?php if (empty($warnings)) : ?>
                    <p class="text-gray-400 text-sm italic">All student indicators are healthy. No dropout warnings triggered.</p>
                <?php else : ?>
                    <div class="space-y-3">
                        <?php foreach ($warnings as $w) : ?>
                            <div class="p-4 bg-white/5 border border-red-500/20 rounded-xl flex justify-between items-center">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <h4 class="font-bold text-white text-sm">Student ID: <?php echo htmlspecialchars($w['student_id']); ?></h4>
                                        <span class="px-1.5 py-0.5 bg-red-600/20 text-red-400 border border-red-500/20 rounded text-[9px] font-bold uppercase"><?php echo htmlspecialchars($w['severity']); ?> Risk</span>
                                    </div>
                                    <p class="text-xs text-gray-300 mt-2">Triggered by: <?php echo htmlspecialchars($w['triggers']); ?></p>
                                </div>
                                <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars($w['detected_at']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Study Buddy Widget -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between">
                <div>
                    <h3 class="text-lg font-semibold mb-2 text-blue-300">🎓 Interactive AI Tutor (Study Buddy)</h3>
                    <p class="text-xs text-gray-400 mb-4">Restricted strictly to school curriculum resources. Try the chatbot instantly.</p>
                    
                    <!-- Quick Prompt Suggestions -->
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        <button onclick="useQuickPrompt('Generate a 3-question practice quiz on Algebra')" class="text-[10px] bg-white/5 hover:bg-white/10 text-gray-300 border border-white/10 rounded-full px-2.5 py-1 transition">📝 Practice Quiz</button>
                        <button onclick="useQuickPrompt('Explain the quadratic formula with a simple example')" class="text-[10px] bg-white/5 hover:bg-white/10 text-gray-300 border border-white/10 rounded-full px-2.5 py-1 transition">📐 Math Formula</button>
                        <button onclick="useQuickPrompt('Suggest a study schedule for my exams next week')" class="text-[10px] bg-white/5 hover:bg-white/10 text-gray-300 border border-white/10 rounded-full px-2.5 py-1 transition">📅 Study Plan</button>
                        <button onclick="useQuickPrompt('Explain photosynthesis in simple terms')" class="text-[10px] bg-white/5 hover:bg-white/10 text-gray-300 border border-white/10 rounded-full px-2.5 py-1 transition">🌱 Photosynthesis</button>
                    </div>

                    <div class="bg-white/5 border border-white/10 rounded-xl p-4 h-48 overflow-y-auto space-y-3 text-xs text-gray-300" id="chatbox">
                        <div class="bg-blue-600/20 border border-blue-500/20 p-2 rounded-lg max-w-[85%] whitespace-pre-wrap">
                            Hello! I am your AI Study Buddy. Ask me anything about your classroom module materials!
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex space-x-2">
                    <input type="text" id="chat_input" placeholder="Type topic question..." class="flex-1 bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                    <button onclick="sendChatMessage()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition">Send</button>
                </div>
            </div>
        </div>

        <script>
            function useQuickPrompt(promptText) {
                const input = document.getElementById('chat_input');
                if (input) {
                    input.value = promptText;
                    sendChatMessage();
                }
            }

            function sendChatMessage() {
                const input = document.getElementById('chat_input');
                const chatbox = document.getElementById('chatbox');
                const msg = input.value.trim();
                if (!msg) return;

                // Display user message
                chatbox.innerHTML += `<div class="bg-white/5 border border-white/5 p-2 rounded-lg max-w-[85%] ml-auto text-right text-white text-xs whitespace-pre-wrap">${msg}</div>`;
                input.value = '';
                chatbox.scrollTop = chatbox.scrollHeight;

                // Typing indicator
                const typingId = 'typing_' + Date.now();
                chatbox.innerHTML += `<div id="${typingId}" class="bg-blue-600/20 border border-blue-500/20 p-2 rounded-lg max-w-[85%] text-xs text-gray-400 italic animate-pulse">AI is thinking...</div>`;
                chatbox.scrollTop = chatbox.scrollHeight;

                const formData = new FormData();
                formData.append('chat_question', msg);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP status ' + res.status);
                    return res.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        const reply = data.answer || 'I could not understand that. Please try again.';
                        const el = document.getElementById(typingId);
                        if (el) el.outerHTML = `<div class="bg-blue-600/20 border border-blue-500/20 p-2 rounded-lg max-w-[85%] text-xs text-gray-200 whitespace-pre-wrap">${reply}</div>`;
                    } catch (err) {
                        console.error('JSON Parse error:', err, 'Response text:', text);
                        throw new Error('Invalid JSON response');
                    }
                    chatbox.scrollTop = chatbox.scrollHeight;
                })
                .catch((err) => {
                    console.error('Fetch error:', err);
                    const el = document.getElementById(typingId);
                    if (el) el.outerHTML = `<div class="bg-red-500/10 border border-red-500/10 p-2 rounded-lg text-red-400 text-xs">Failed to connect to Study Buddy. Error: ${err.message}</div>`;
                });
            }

            // Enter key support
            document.addEventListener('DOMContentLoaded', function() {
                const inp = document.getElementById('chat_input');
                if (inp) {
                    inp.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            sendChatMessage();
                        }
                    });
                }
            });
        </script>

    <?php elseif ($action === 'comments' || $action === 'planner') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Form Card -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl">
                <?php if ($action === 'comments') : ?>
                    <h3 class="text-lg font-semibold mb-6 text-blue-300">Generate Report Card remarks</h3>
                    <form onsubmit="generateAIContent(event, 'comments')" class="space-y-4">
                        <input type="hidden" name="ajax_generate_comment" value="1" />
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Student Name</label>
                            <input type="text" id="student_name" required placeholder="e.g. John Doe" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Grades Summary</label>
                            <input type="text" id="grades_summary" required placeholder="e.g. Math: A, Physics: B+, English: D" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Behaviour Details</label>
                            <input type="text" id="behaviour_summary" required placeholder="e.g. Attentive but needs focus during calculations" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                        </div>
                        <button type="submit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                            Generate remark
                        </button>
                    </form>
                <?php else : ?>
                    <h3 class="text-lg font-semibold mb-6 text-indigo-300">Generate Weekly Lesson Plan</h3>
                    <form onsubmit="generateAIContent(event, 'planner')" class="space-y-4">
                        <input type="hidden" name="ajax_generate_lesson" value="1" />
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-1">Class Level</label>
                                <select id="class_level" required onchange="loadOfficialTopics()" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <option value="" class="bg-gray-900 text-gray-400">Select Class...</option>
                                    <option value="JS1" class="bg-gray-900 text-white">JS1 (Junior Secondary 1)</option>
                                    <option value="JS2" class="bg-gray-900 text-white">JS2 (Junior Secondary 2)</option>
                                    <option value="JS3" class="bg-gray-900 text-white">JS3 (Junior Secondary 3)</option>
                                    <option value="SS1" class="bg-gray-900 text-white">SS1 (Senior Secondary 1)</option>
                                    <option value="SS2" class="bg-gray-900 text-white">SS2 (Senior Secondary 2)</option>
                                    <option value="SS3" class="bg-gray-900 text-white">SS3 (Senior Secondary 3)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-400 mb-1">Subject</label>
                                <select id="subject" required onchange="loadOfficialTopics()" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <option value="" class="bg-gray-900 text-gray-400">Select Subject...</option>
                                    <option value="General Mathematics" class="bg-gray-900 text-white">General Mathematics</option>
                                    <option value="Biology" class="bg-gray-900 text-white">Biology</option>
                                    <option value="English Language" class="bg-gray-900 text-white">English Language</option>
                                    <option value="Chemistry" class="bg-gray-900 text-white">Chemistry</option>
                                    <option value="Physics" class="bg-gray-900 text-white">Physics</option>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Official Syllabus Topic</label>
                            <select id="topic_select" required onchange="toggleCustomTopicInput()" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="" class="bg-gray-900 text-gray-400">Select class and subject first...</option>
                            </select>
                        </div>
                        
                        <div id="custom_topic_wrapper" class="hidden">
                            <label class="block text-sm font-medium text-gray-400 mb-1">Type Custom Topic Name</label>
                            <input type="text" id="custom_topic_input" placeholder="e.g. Trigonometric Ratios" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                        </div>
                        
                        <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                            Generate Curriculum Plan
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Result Card -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between">
                <div>
                    <h3 class="text-lg font-semibold mb-4 text-blue-300">AI Response Output</h3>
                    <div id="ai_output_box" class="bg-white/5 border border-white/5 p-4 rounded-xl text-sm leading-relaxed text-gray-300 font-sans h-96 overflow-y-auto whitespace-pre-wrap">Submit the form to generate content details.</div>
                </div>
            </div>
        </div>

        <script>
            // Store fetched topics globally for objective extraction
            let fetchedTopicsList = [];

            function loadOfficialTopics() {
                const classVal = document.getElementById('class_level').value;
                const subjectVal = document.getElementById('subject').value;
                const topicSelect = document.getElementById('topic_select');
                const customWrapper = document.getElementById('custom_topic_wrapper');
                
                if (!classVal || !subjectVal) {
                    topicSelect.innerHTML = '<option value="">Select class and subject first...</option>';
                    customWrapper.classList.add('hidden');
                    return;
                }

                topicSelect.innerHTML = '<option value="">Loading official curriculum...</option>';

                fetch(`/api/v1/curriculum/get-topics.php?class_level=${encodeURIComponent(classVal)}&subject=${encodeURIComponent(subjectVal)}`)
                .then(res => res.json())
                .then(data => {
                    topicSelect.innerHTML = '';
                    fetchedTopicsList = data.topics || [];
                    
                    if (fetchedTopicsList.length === 0) {
                        // Empty - force custom input
                        const opt = document.createElement('option');
                        opt.value = '__custom__';
                        opt.textContent = 'No database curriculum found - Enter custom topic';
                        topicSelect.appendChild(opt);
                        customWrapper.classList.remove('hidden');
                        document.getElementById('custom_topic_input').required = true;
                    } else {
                        // Populate options
                        const optDefault = document.createElement('option');
                        optDefault.value = '';
                        optDefault.textContent = 'Select standard topic...';
                        topicSelect.appendChild(optDefault);

                        fetchedTopicsList.forEach(t => {
                            const opt = document.createElement('option');
                            opt.value = t.id;
                            opt.textContent = `Term ${t.term.charAt(0)} - Week ${t.week_number}: ${t.topic}`;
                            topicSelect.appendChild(opt);
                        });

                        const optCustom = document.createElement('option');
                        optCustom.value = '__custom__';
                        optCustom.textContent = 'Other (Type custom topic)';
                        topicSelect.appendChild(optCustom);
                        customWrapper.classList.add('hidden');
                        document.getElementById('custom_topic_input').required = false;
                    }
                })
                .catch(err => {
                    console.error('Error fetching curriculum:', err);
                    topicSelect.innerHTML = '<option value="__custom__">Error loading standards - Enter custom topic</option>';
                    customWrapper.classList.remove('hidden');
                    document.getElementById('custom_topic_input').required = true;
                });
            }

            function toggleCustomTopicInput() {
                const topicSelect = document.getElementById('topic_select');
                const customWrapper = document.getElementById('custom_topic_wrapper');
                const customInput = document.getElementById('custom_topic_input');
                
                if (topicSelect.value === '__custom__') {
                    customWrapper.classList.remove('hidden');
                    customInput.required = true;
                } else {
                    customWrapper.classList.add('hidden');
                    customInput.required = false;
                }
            }

            function generateAIContent(event, type) {
                event.preventDefault();
                const outputBox = document.getElementById('ai_output_box');
                outputBox.innerHTML = '<span class="text-blue-300 animate-pulse italic">🤖 AI is compiling and writing response. Please wait...</span>';
                
                const formData = new FormData();
                if (type === 'comments') {
                    formData.append('ajax_generate_comment', '1');
                    formData.append('student_name', document.getElementById('student_name').value);
                    formData.append('grades_summary', document.getElementById('grades_summary').value);
                    formData.append('behaviour_summary', document.getElementById('behaviour_summary').value);
                } else {
                    formData.append('ajax_generate_lesson', '1');
                    formData.append('class_level', document.getElementById('class_level').value);
                    formData.append('subject', document.getElementById('subject').value);
                    
                    const topicSelect = document.getElementById('topic_select');
                    let topicVal = '';
                    let objectivesVal = '';
                    
                    if (topicSelect.value === '__custom__') {
                        topicVal = document.getElementById('custom_topic_input').value;
                    } else {
                        // Find matching topic object
                        const selectedId = parseInt(topicSelect.value);
                        const matched = fetchedTopicsList.find(t => parseInt(t.id) === selectedId);
                        if (matched) {
                            topicVal = matched.topic;
                            objectivesVal = matched.official_objectives;
                        }
                    }
                    
                    formData.append('topic', topicVal);
                    formData.append('official_objectives', objectivesVal);
                }

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP status ' + res.status);
                    return res.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        outputBox.textContent = data.answer || 'An error occurred. No content was generated.';
                    } catch (err) {
                        console.error('JSON Parse error:', err, 'Response:', text);
                        outputBox.textContent = 'Invalid response format from server.';
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    outputBox.innerHTML = `<span class="text-red-400">Failed to connect to AI server. Error: ${err.message}</span>`;
                });
            }
        </script>
    <?php endif; ?>
</div>

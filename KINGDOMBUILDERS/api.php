<?php
// KBQ backend v2 — settings, question bank, and server-side session scoring
// (answers are never sent to the browser, so view-source can't reveal them)

$ADMIN_PASSWORD = "kingdom2026"; // CHANGE THIS before going live

header('Content-Type: application/json');

$DIR = __DIR__;
$QUESTIONS_FILE = $DIR . '/questions.json';
$SETTINGS_FILE  = $DIR . '/settings.json';
$SESSIONS_FILE  = $DIR . '/sessions.json';
$SCORES_FILE    = $DIR . '/scores.json';

$DEFAULT_SETTINGS = [
    'questionsPerTest'       => 40,
    'timePerQuestionSeconds' => 30,
    'timerEnabled'           => true,
    'allowBack'              => false,
    'randomizeQuestions'     => true,
    'randomizeAnswers'       => true,
    'showAnswerFeedback'     => false, // anti-cheat: hide correct/wrong during test
    'oneAttemptPerName'      => true,
    'testOpen'               => true,
    'passMarkPercent'        => 50,
    'maxTabSwitches'         => 3,     // 0 = do not monitor
];

$DEFAULT_QUESTIONS = [
["q"=>"In Matthew's genealogy of Jesus, who is named as Joseph's father?","o"=>["Jacob","Eli","Heli","Matthan"],"a"=>0,"r"=>"Matthew 1"],
["q"=>"What name did the angel tell Joseph to give Mary's child?","o"=>["John","Jesus","Emmanuel","Joshua"],"a"=>1,"r"=>"Matthew 1"],
["q"=>"Who came from the East to worship the infant Jesus, guided by a star?","o"=>["Shepherds","Roman soldiers","Wise men (Magi)","Pharisees"],"a"=>2,"r"=>"Matthew 2"],
["q"=>"What gifts did the visitors bring to the infant Jesus?","o"=>["Bread, wine, oil","Gold, frankincense, myrrh","Silver, wheat, honey","Linen, spices, coins"],"a"=>1,"r"=>"Matthew 2"],
["q"=>"Which king, troubled by news of a newborn king, ordered the killing of infants in Bethlehem?","o"=>["Herod","Pilate","Caesar Augustus","Archelaus"],"a"=>0,"r"=>"Matthew 2"],
["q"=>"Where did Joseph take Mary and the child to escape danger?","o"=>["Egypt","Babylon","Rome","Samaria"],"a"=>0,"r"=>"Matthew 2"],
["q"=>"Who baptized Jesus in the Jordan River?","o"=>["Peter","John the Baptist","Andrew","Philip"],"a"=>1,"r"=>"Matthew 3"],
["q"=>"How many days did Jesus fast in the wilderness before being tempted?","o"=>["7","12","40","100"],"a"=>2,"r"=>"Matthew 4"],
["q"=>"Where did Jesus deliver the famous teaching known as the Beatitudes?","o"=>["In the temple","On a mountainside","In a boat","In a synagogue"],"a"=>1,"r"=>"Matthew 5"],
["q"=>"According to the Beatitudes, who did Jesus say are 'blessed' first?","o"=>["The rich","The poor in spirit","The powerful","The wise"],"a"=>1,"r"=>"Matthew 5"],
["q"=>"In the Sermon on the Mount, Jesus called His followers the salt of the earth and what else?","o"=>["Bread of life","Light of the world","Living water","Vine of heaven"],"a"=>1,"r"=>"Matthew 5"],
["q"=>"About how many men did Jesus feed with five loaves and two fish?","o"=>["500","1,000","5,000","12,000"],"a"=>2,"r"=>"Matthew 14"],
["q"=>"Which disciple attempted to walk on water toward Jesus?","o"=>["John","Peter","Thomas","James"],"a"=>1,"r"=>"Matthew 14"],
["q"=>"According to Jesus, what is the greatest commandment?","o"=>["Honor your parents","Keep the Sabbath","Love the Lord your God","Give to the poor"],"a"=>2,"r"=>"Matthew 22"],
["q"=>"Which parable describes seed falling on different kinds of soil?","o"=>["Parable of the Sower","Parable of the Lost Sheep","Parable of the Vineyard","Parable of the Wedding Feast"],"a"=>0,"r"=>"Matthew 13"],
["q"=>"In the Parable of the Hidden Treasure, what does the man do after finding treasure in a field?","o"=>["Reports it to the owner","Sells all he has to buy the field","Leaves it where it is","Shares it with his neighbors"],"a"=>1,"r"=>"Matthew 13"],
["q"=>"In the Parable of the Talents, how many talents did the unfaithful servant receive before burying it?","o"=>["One","Two","Five","Ten"],"a"=>0,"r"=>"Matthew 25"],
["q"=>"How did Jesus enter Jerusalem shortly before His crucifixion?","o"=>["On a chariot","On foot alone","Riding a donkey","Carried on a throne"],"a"=>2,"r"=>"Matthew 21"],
["q"=>"Who betrayed Jesus for thirty pieces of silver?","o"=>["Thomas","Judas Iscariot","Peter","Matthew"],"a"=>1,"r"=>"Matthew 26"],
["q"=>"What command did Jesus give His disciples at the end of Matthew's Gospel, often called the Great Commission?","o"=>["Return to Galilee and wait","Go and make disciples of all nations","Build a temple in Jerusalem","Fast for forty days"],"a"=>1,"r"=>"Matthew 28"],
["q"=>"How does the Gospel of Mark open, unlike Matthew?","o"=>["With a genealogy","With the birth of Jesus","With John the Baptist preaching","With the resurrection"],"a"=>2,"r"=>"Mark 1"],
["q"=>"Which two brothers did Jesus call first as He walked by the Sea of Galilee?","o"=>["James and John","Simon (Peter) and Andrew","Philip and Bartholomew","Matthew and Thomas"],"a"=>1,"r"=>"Mark 1"],
["q"=>"What illness was Simon Peter's mother-in-law suffering from when Jesus healed her?","o"=>["Blindness","Fever","Paralysis","Leprosy"],"a"=>1,"r"=>"Mark 1"],
["q"=>"What did Jesus do when a man with leprosy asked to be made clean?","o"=>["Sent him to the priest only","Touched him and healed him","Refused to touch him","Told him to wash seven times"],"a"=>1,"r"=>"Mark 1"],
["q"=>"How did the friends of a paralyzed man bring him to Jesus through a crowded house?","o"=>["Through the front door","Through a window","Through the roof","Through the back wall"],"a"=>2,"r"=>"Mark 2"],
["q"=>"The religious leaders questioned Jesus for doing what with tax collectors and sinners?","o"=>["Teaching them in secret","Eating with them","Avoiding them completely","Arguing with them publicly"],"a"=>1,"r"=>"Mark 2"],
["q"=>"What did Jesus calm while crossing the Sea of Galilee with His disciples?","o"=>["A famine","A storm","An earthquake","A fire"],"a"=>1,"r"=>"Mark 4"],
["q"=>"Into what animals did Jesus send the legion of demons from the possessed man?","o"=>["Sheep","Pigs","Goats","Oxen"],"a"=>1,"r"=>"Mark 5"],
["q"=>"How many years had the woman suffered from bleeding before touching Jesus' garment?","o"=>["Three","Seven","Twelve","Twenty"],"a"=>2,"r"=>"Mark 5"],
["q"=>"What miracle did Jesus perform for Jairus's daughter?","o"=>["Healed her blindness","Raised her from the dead","Cast out a demon","Cured her fever"],"a"=>1,"r"=>"Mark 5"],
["q"=>"What tragic request, made through a dance, led to John the Baptist's death?","o"=>["His exile","His head on a platter","His imprisonment for life","His banishment from Galilee"],"a"=>1,"r"=>"Mark 6"],
["q"=>"On the Mount of Transfiguration, which two Old Testament figures appeared with Jesus?","o"=>["Abraham and Isaac","Moses and Elijah","David and Solomon","Isaiah and Jeremiah"],"a"=>1,"r"=>"Mark 9"],
["q"=>"Jesus said it is easier for a camel to pass through what than for a rich man to enter the kingdom of God?","o"=>["A narrow gate","The eye of a needle","A locked door","A mountain pass"],"a"=>1,"r"=>"Mark 10"],
["q"=>"What did the blind man Bartimaeus ask Jesus for?","o"=>["Money","Food","His sight","Forgiveness"],"a"=>2,"r"=>"Mark 10"],
["q"=>"What happened to the fig tree Jesus cursed for having no fruit?","o"=>["It grew more fruit","It withered","It was cut down","Nothing happened"],"a"=>1,"r"=>"Mark 11"],
["q"=>"The Pharisees tried to trap Jesus with a question about paying taxes to whom?","o"=>["The high priest","Caesar","Herod","The Sanhedrin"],"a"=>1,"r"=>"Mark 12"],
["q"=>"In Mark, what did Jesus say is the second greatest commandment, after loving God?","o"=>["Honor the Sabbath","Love your neighbor as yourself","Give generously to the temple","Fast regularly"],"a"=>1,"r"=>"Mark 12"],
["q"=>"What small offering did the poor widow give at the temple that Jesus praised?","o"=>["A loaf of bread","Two small copper coins","A gold ring","A lamb for sacrifice"],"a"=>1,"r"=>"Mark 12"],
["q"=>"At the Last Supper, what did Jesus say the bread represented?","o"=>["His teaching","His body","His kingdom","His glory"],"a"=>1,"r"=>"Mark 14"],
["q"=>"What happened to the temple curtain at the moment Jesus died?","o"=>["It caught fire","It was torn in two","It remained unchanged","It fell to the ground"],"a"=>1,"r"=>"Mark 15"],
];

function read_json($file, $default) {
    if (!file_exists($file)) return $default;
    $fp = fopen($file, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return ($data === null) ? $default : $data;
}

function write_json($file, $data) {
    $fp = fopen($file, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function get_settings() {
    global $SETTINGS_FILE, $DEFAULT_SETTINGS;
    $s = read_json($SETTINGS_FILE, null);
    if ($s === null) { write_json($SETTINGS_FILE, $DEFAULT_SETTINGS); return $DEFAULT_SETTINGS; }
    return array_merge($DEFAULT_SETTINGS, $s);
}

function get_questions() {
    global $QUESTIONS_FILE, $DEFAULT_QUESTIONS;
    $q = read_json($QUESTIONS_FILE, null);
    if ($q === null) {
        $withIds = [];
        foreach ($DEFAULT_QUESTIONS as $item) { $item['id'] = uniqid('q_', true); $withIds[] = $item; }
        write_json($QUESTIONS_FILE, $withIds);
        return $withIds;
    }
    return $q;
}

function require_admin($password) {
    global $ADMIN_PASSWORD;
    if ($password !== $ADMIN_PASSWORD) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

function body_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ---------- ADMIN AUTH ----------
if ($action === 'check_admin') {
    $password = isset($_GET['password']) ? $_GET['password'] : '';
    echo json_encode(['ok' => $password === $ADMIN_PASSWORD]);
    exit;
}

// ---------- SETTINGS ----------
if ($action === 'get_settings') {
    echo json_encode(['ok' => true, 'settings' => get_settings()]);
    exit;
}

if ($action === 'save_settings') {
    $input = body_json();
    require_admin(isset($input['password']) ? $input['password'] : '');
    $current = get_settings();
    $incoming = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];
    $merged = array_merge($current, $incoming);
    $merged['questionsPerTest']       = max(1, (int)$merged['questionsPerTest']);
    $merged['timePerQuestionSeconds'] = max(5, (int)$merged['timePerQuestionSeconds']);
    $merged['passMarkPercent']        = max(0, min(100, (int)$merged['passMarkPercent']));
    $merged['maxTabSwitches']         = max(0, (int)$merged['maxTabSwitches']);
    write_json($GLOBALS['SETTINGS_FILE'], $merged);
    echo json_encode(['ok' => true, 'settings' => $merged]);
    exit;
}

// ---------- QUESTION BANK (admin) ----------
if ($action === 'get_questions_admin') {
    $password = isset($_GET['password']) ? $_GET['password'] : '';
    require_admin($password);
    echo json_encode(['ok' => true, 'questions' => get_questions()]);
    exit;
}

if ($action === 'save_question') {
    $input = body_json();
    require_admin(isset($input['password']) ? $input['password'] : '');
    $q = isset($input['question']) ? $input['question'] : null;
    if (!$q || empty($q['q']) || empty($q['o']) || count($q['o']) !== 4) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid question']);
        exit;
    }
    $questions = get_questions();
    $entry = [
        'id' => !empty($q['id']) ? $q['id'] : uniqid('q_', true),
        'q'  => trim($q['q']),
        'o'  => array_map('trim', $q['o']),
        'a'  => max(0, min(3, (int)$q['a'])),
        'r'  => isset($q['r']) ? trim($q['r']) : '',
    ];
    $found = false;
    foreach ($questions as $i => $existing) {
        if ($existing['id'] === $entry['id']) { $questions[$i] = $entry; $found = true; break; }
    }
    if (!$found) $questions[] = $entry;
    write_json($QUESTIONS_FILE, $questions);
    echo json_encode(['ok' => true, 'questions' => $questions]);
    exit;
}

if ($action === 'delete_question') {
    $input = body_json();
    require_admin(isset($input['password']) ? $input['password'] : '');
    $id = isset($input['id']) ? $input['id'] : '';
    $questions = array_values(array_filter(get_questions(), function($x) use ($id) { return $x['id'] !== $id; }));
    write_json($QUESTIONS_FILE, $questions);
    echo json_encode(['ok' => true, 'questions' => $questions]);
    exit;
}

// ---------- TEST SESSION (student) ----------
if ($action === 'start_session') {
    $input = body_json();
    $name = isset($input['name']) ? trim(substr($input['name'], 0, 120)) : '';
    if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }

    $settings = get_settings();
    if (!$settings['testOpen']) {
        echo json_encode(['ok' => false, 'error' => 'closed', 'message' => 'The test is currently closed by the administrator.']);
        exit;
    }

    if ($settings['oneAttemptPerName']) {
        $scores = read_json($SCORES_FILE, []);
        foreach ($scores as $s) {
            if (isset($s['name']) && strtolower(trim($s['name'])) === strtolower($name)) {
                echo json_encode(['ok' => false, 'error' => 'already_taken', 'message' => 'You have already taken this test.']);
                exit;
            }
        }
    }

    $bank = get_questions();
    if (count($bank) === 0) { echo json_encode(['ok'=>false,'error'=>'No questions available.']); exit; }

    $count = min($settings['questionsPerTest'], count($bank));
    $pool = $bank;
    if ($settings['randomizeQuestions']) shuffle($pool);
    $chosen = array_slice($pool, 0, $count);

    $sessionQuestions = []; // returned to client, no answer key
    $correctAnswers = [];   // kept server-side only

    foreach ($chosen as $item) {
        $options = $item['o'];
        $correctIdx = (int)$item['a'];
        if ($settings['randomizeAnswers']) {
            $order = [0,1,2,3];
            shuffle($order);
            $newOptions = [];
            $newCorrect = 0;
            foreach ($order as $newPos => $oldPos) {
                $newOptions[$newPos] = $options[$oldPos];
                if ($oldPos === $correctIdx) $newCorrect = $newPos;
            }
            $options = $newOptions;
            $correctIdx = $newCorrect;
        }
        $sessionQuestions[] = ['q' => $item['q'], 'o' => $options, 'r' => isset($item['r']) ? $item['r'] : ''];
        $correctAnswers[] = $correctIdx;
    }

    $sessionId = bin2hex(random_bytes(16));
    $sessions = read_json($SESSIONS_FILE, []);
    $sessions[$sessionId] = [
        'name' => $name,
        'createdAt' => time(),
        'questions' => $sessionQuestions,
        'correct' => $correctAnswers,
        'answers' => array_fill(0, $count, null),
        'tabSwitches' => 0,
        'finished' => false,
    ];
    write_json($SESSIONS_FILE, $sessions);

    echo json_encode([
        'ok' => true,
        'sessionId' => $sessionId,
        'total' => $count,
        'questions' => $sessionQuestions,
        'config' => [
            'timerEnabled' => $settings['timerEnabled'],
            'timePerQuestionSeconds' => $settings['timePerQuestionSeconds'],
            'allowBack' => $settings['allowBack'],
            'showAnswerFeedback' => $settings['showAnswerFeedback'],
            'maxTabSwitches' => $settings['maxTabSwitches'],
        ],
    ]);
    exit;
}

if ($action === 'answer_question') {
    $input = body_json();
    $sessionId = isset($input['sessionId']) ? $input['sessionId'] : '';
    $index = isset($input['index']) ? (int)$input['index'] : -1;
    $choice = isset($input['choice']) ? (int)$input['choice'] : -1;

    $sessions = read_json($SESSIONS_FILE, []);
    if (!isset($sessions[$sessionId]) || $sessions[$sessionId]['finished']) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid session']); exit;
    }
    $session = $sessions[$sessionId];
    if ($index < 0 || $index >= count($session['questions'])) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid index']); exit;
    }
    $session['answers'][$index] = $choice; // -1 = timed out / unanswered
    $sessions[$sessionId] = $session;
    write_json($SESSIONS_FILE, $sessions);

    $settings = get_settings();
    $resp = ['ok' => true];
    if ($settings['showAnswerFeedback']) {
        $resp['correct'] = ($choice === $session['correct'][$index]);
        $resp['correctIndex'] = $session['correct'][$index];
    }
    echo json_encode($resp);
    exit;
}

if ($action === 'flag_tab_switch') {
    $input = body_json();
    $sessionId = isset($input['sessionId']) ? $input['sessionId'] : '';
    $sessions = read_json($SESSIONS_FILE, []);
    if (!isset($sessions[$sessionId]) || $sessions[$sessionId]['finished']) {
        echo json_encode(['ok'=>false]); exit;
    }
    $sessions[$sessionId]['tabSwitches'] = (isset($sessions[$sessionId]['tabSwitches']) ? $sessions[$sessionId]['tabSwitches'] : 0) + 1;
    $count = $sessions[$sessionId]['tabSwitches'];
    write_json($SESSIONS_FILE, $sessions);
    $settings = get_settings();
    $max = $settings['maxTabSwitches'];
    $autoSubmit = ($max > 0 && $count >= $max);
    echo json_encode(['ok' => true, 'count' => $count, 'autoSubmit' => $autoSubmit]);
    exit;
}

if ($action === 'finish_session') {
    $input = body_json();
    $sessionId = isset($input['sessionId']) ? $input['sessionId'] : '';
    $sessions = read_json($SESSIONS_FILE, []);
    if (!isset($sessions[$sessionId])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid session']); exit; }
    $session = $sessions[$sessionId];
    if ($session['finished']) {
        echo json_encode(['ok'=>true,'score'=> isset($session['_cachedScore']) ? $session['_cachedScore'] : 0,'total'=>count($session['questions'])]);
        exit;
    }

    $score = 0;
    foreach ($session['correct'] as $i => $correctIdx) {
        if (isset($session['answers'][$i]) && $session['answers'][$i] === $correctIdx) $score++;
    }
    $total = count($session['questions']);
    $timeSeconds = time() - $session['createdAt'];

    $session['finished'] = true;
    $session['_cachedScore'] = $score;
    $sessions[$sessionId] = $session;
    write_json($SESSIONS_FILE, $sessions);

    $scores = read_json($SCORES_FILE, []);
    $scores[] = [
        'name' => $session['name'],
        'score' => $score,
        'total' => $total,
        'timeSeconds' => $timeSeconds,
        'tabSwitches' => $session['tabSwitches'],
        'ts' => time(),
    ];
    write_json($SCORES_FILE, $scores);

    echo json_encode(['ok' => true, 'score' => $score, 'total' => $total]);
    exit;
}

// ---------- RESULTS (admin) ----------
if ($action === 'get_scores') {
    $password = isset($_GET['password']) ? $_GET['password'] : '';
    require_admin($password);
    $settings = get_settings();
    echo json_encode(['ok' => true, 'scores' => read_json($SCORES_FILE, []), 'passMarkPercent' => $settings['passMarkPercent']]);
    exit;
}

if ($action === 'clear_scores') {
    $input = body_json();
    require_admin(isset($input['password']) ? $input['password'] : '');
    write_json($SCORES_FILE, []);
    write_json($SESSIONS_FILE, []);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

<?php
$file = 'c:/Users/User/Downloads/SCHOOLAPP/NEW-SCHOOLAPP/views/evaluation.php';
$content = file_get_contents($file);
$lines = explode("\n", $content);
foreach ($lines as $num => $line) {
    if (stripos($line, 'action') !== false && (stripos($line, 'cbt') !== false || stripos($line, 'quiz') !== false)) {
        echo ($num + 1) . ": " . trim($line) . "\n";
    }
}
?>

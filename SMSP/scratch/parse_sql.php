<?php
$sqlFile = 'C:/Users/User/Downloads/SCHOOLAPP/NEW-SCHOOLAPP/sms_school.sql';
if (!file_exists($sqlFile)) {
    echo "File not found: $sqlFile\n";
    exit;
}

$content = file_get_contents($sqlFile);

function countInsertRows($content, $tableName) {
    // Matches INSERT INTO `tableName` VALUES (rows...)
    // SQL dumps can have multi-row insert: INSERT INTO `table` VALUES (...), (...), (...);
    // Let's count how many opening parentheses '(' are there in the VALUES block,
    // or parse it more robustly.
    $pattern = "/INSERT INTO `?" . $tableName . "`? VALUES\s*\((.*?)\);/is";
    if (preg_match_all($pattern, $content, $matches)) {
        $totalRows = 0;
        foreach ($matches[1] as $valuesBlock) {
            // Count rows. A naive split by '),(' might work if data doesn't contain it.
            // A more robust way is to count unescaped parentheses.
            // Let's count occurrences of '), (' or count total commas, or parse character-by-character.
            // Let's do a character-by-character parser for matching parentheses.
            $inString = false;
            $escape = false;
            $parenDepth = 0;
            $rowCount = 0;
            for ($i = 0; $i < strlen($valuesBlock); $i++) {
                $char = $valuesBlock[$i];
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === "'") {
                    $inString = !$inString;
                    continue;
                }
                if (!$inString) {
                    if ($char === '(') {
                        if ($parenDepth === 0) {
                            $rowCount++;
                        }
                        $parenDepth++;
                    } elseif ($char === ')') {
                        $parenDepth--;
                    }
                }
            }
            $totalRows += $rowCount;
        }
        return $totalRows;
    }
    
    // Fallback: search for single row inserts: INSERT INTO `table` VALUES (...);
    $patternSingle = "/INSERT INTO `?" . $tableName . "`?\s*\([^)]*\)\s*VALUES\s*\(/i";
    return preg_match_all($patternSingle, $content);
}

echo "SQL File Analysis:\n";
echo "Students count in SQL: " . countInsertRows($content, 'sm_students') . "\n";
echo "Teachers count in SQL: " . countInsertRows($content, 'sm_teachers') . "\n";
echo "Parents count in SQL: " . countInsertRows($content, 'sm_parents') . "\n";
echo "Schools count in SQL: " . countInsertRows($content, 'sm_school_details') . "\n";

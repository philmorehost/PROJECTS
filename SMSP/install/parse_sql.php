<?php
$sqlFile = dirname(__DIR__) . '/sms_school.sql';
if (!file_exists($sqlFile)) {
    echo "File not found: $sqlFile\n";
    exit;
}

$content = file_get_contents($sqlFile);

function countInsertRows($content, $tableName) {
    // Matches: INSERT INTO `tableName` VALUES (...) or INSERT INTO `tableName` (cols) VALUES (...)
    // We want to handle multi-row inserts: VALUES (row1), (row2), ...
    // Let's find where "INSERT INTO `tableName`" starts, and then search for values.
    $offset = 0;
    $totalRows = 0;
    while (($pos = stripos($content, "INSERT INTO `{$tableName}`", $offset)) !== false) {
        // Find the next semi-colon after the INSERT INTO statement
        $semiColonPos = strpos($content, ";", $pos);
        if ($semiColonPos === false) {
            break;
        }
        
        $insertStatement = substr($content, $pos, $semiColonPos - $pos);
        
        // Find the start of VALUES keyword
        $valuesKeywordPos = stripos($insertStatement, "VALUES");
        if ($valuesKeywordPos !== false) {
            $valuesBlock = substr($insertStatement, $valuesKeywordPos + 6);
            
            // Count opening parentheses that are not inside strings
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
        } else {
            // Count simple INSERT occurrences
            $totalRows++;
        }
        $offset = $semiColonPos + 1;
    }
    return $totalRows;
}

echo "<pre>=== SQL FILE ANALYSIS ===\n";
echo "File path: $sqlFile\n";
echo "File size: " . number_format(filesize($sqlFile)) . " bytes\n";
echo "Students in SQL file: " . countInsertRows($content, 'sm_students') . "\n";
echo "Teachers in SQL file: " . countInsertRows($content, 'sm_teachers') . "\n";
echo "Parents in SQL file: " . countInsertRows($content, 'sm_parents') . "\n";
echo "Schools in SQL file: " . countInsertRows($content, 'sm_school_details') . "\n";
echo "</pre>";

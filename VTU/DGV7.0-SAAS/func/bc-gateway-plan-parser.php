<?php
/**
 * Reads a service's real plan-code map straight out of the func/api-gateway/*.php file that
 * actually fulfills purchases for it, instead of relying on a hand-maintained catalog that can
 * (and does) drift out of sync with the gateway file's own hardcoded codes.
 *
 * Uses token_get_all() to walk the gateway file's tokens and extract the embedded
 * `$web_..._array = array(...)` literal for a given network — never eval(), since the file
 * content is untrusted-shape from this function's point of view even though it ships with the app.
 */

// Resolve the same gateway file real purchases use. The host is normalised first: the stored
// api_base_url is admin-typed and may carry a scheme, a "www." prefix, mixed case or a trailing
// slash, and the raw value used to be pasted straight into the filename - so "www.x.com" and
// "X.com" both silently missed and resolved to the wrong (local-server) gateway. The same
// normalisation is mirrored in web/func/exam.php and func/bc-epin-fulfillment.php; keep the three
// in step if this ever changes.
function bc_gateway_normalize_host($api_base_url) {
    $host = strtolower(trim($api_base_url));
    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#^www\.#i', '', $host);
    return rtrim($host, "/");
}

// Resolve the same gateway file real purchases use.
function bc_gateway_resolve_file($api_type_for_file, $api_base_url) {
    $gateway_dir = __DIR__ . "/api-gateway/";

    $candidates = array();
    $candidates[] = $api_type_for_file . "-" . str_replace(".", "-", bc_gateway_normalize_host($api_base_url)) . ".php";
    // Keep the verbatim spelling as a second chance, so an unusual-but-working stored value that
    // happens to have its own file is not broken by the normalisation above.
    $raw_candidate = $api_type_for_file . "-" . str_replace(".", "-", $api_base_url) . ".php";
    if (!in_array($raw_candidate, $candidates, true)) {
        $candidates[] = $raw_candidate;
    }

    foreach ($candidates as $candidate) {
        if (file_exists($gateway_dir . $candidate)) {
            return $gateway_dir . $candidate;
        }
    }

    $fallback_file = $api_type_for_file . "-localserver.php";
    if (file_exists($gateway_dir . $fallback_file)) {
        return $gateway_dir . $fallback_file;
    }
    return false;
}

// Returns the token's type constant (or the raw punctuation string for single-char tokens).
function _bc_gw_token_type($token) {
    return is_array($token) ? $token[0] : $token;
}

// Returns the token's literal text.
function _bc_gw_token_text($token) {
    return is_array($token) ? $token[1] : $token;
}

function _bc_gw_is_whitespace_or_comment($token) {
    $type = _bc_gw_token_type($token);
    return in_array($type, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
}

// Strips quotes from a simple, non-interpolated string literal token's text.
function _bc_gw_unquote($literal_text) {
    $literal_text = trim($literal_text);
    $quote = substr($literal_text, 0, 1);
    if ($quote === '"' || $quote === "'") {
        $inner = substr($literal_text, 1, -1);
        return str_replace(array("\\\\", "\\" . $quote), array("\\", $quote), $inner);
    }
    return $literal_text;
}

// Parses a flat `array(...)`/`[...]` token literal (as emitted between the open/close indexes,
// exclusive of the delimiters themselves) into a PHP associative or list array. Nested
// arrays are not expected in these gateway files and are skipped as opaque values if encountered.
function _bc_gw_parse_array_body($tokens, $start, $end) {
    $result = array();
    $pending_key = null;
    $have_key = false;
    $pending_value = null;
    $have_value = false;
    $depth = 0;

    $flush = function () use (&$result, &$pending_key, &$have_key, &$pending_value, &$have_value) {
        if ($have_value) {
            if ($have_key) {
                $result[$pending_key] = $pending_value;
            } else {
                $result[] = $pending_value;
            }
        }
        $pending_key = null;
        $have_key = false;
        $pending_value = null;
        $have_value = false;
    };

    for ($i = $start; $i < $end; $i++) {
        $token = $tokens[$i];
        if (_bc_gw_is_whitespace_or_comment($token)) {
            continue;
        }
        $type = _bc_gw_token_type($token);
        $text = _bc_gw_token_text($token);

        if ($type === '(' || $type === '[') {
            $depth++;
            continue;
        }
        if ($type === ')' || $type === ']') {
            $depth--;
            continue;
        }
        if ($depth > 0) {
            continue; // Skip nested-array contents; unused by any file seen so far.
        }

        if ($type === ',') {
            $flush();
            continue;
        }
        if ($type === T_DOUBLE_ARROW) {
            // What we've collected so far was the key, not the value.
            $pending_key = $pending_value;
            $have_key = true;
            $pending_value = null;
            $have_value = false;
            continue;
        }
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            $pending_value = _bc_gw_unquote($text);
            $have_value = true;
            continue;
        }
        if ($type === T_LNUMBER || $type === T_DNUMBER) {
            $pending_value = $text;
            $have_value = true;
            continue;
        }
        // Anything else (T_ARRAY keyword opening a nested array, stray identifiers) is ignored;
        // its balanced ()/[] content is skipped via the depth counter above.
    }
    $flush();

    return $result;
}

// Given the full token stream, finds the first `$anyVarName = array(...)` or `$anyVarName = [...]`
// literal at or after $fromIndex and strictly before $beforeIndex (pass null for no upper bound).
// Returns [$parsedArray, $indexAfterClose] or null if none found in range.
function _bc_gw_find_array_assignment($tokens, $fromIndex, $beforeIndex) {
    $count = count($tokens);
    $limit = ($beforeIndex === null) ? $count : min($beforeIndex, $count);

    for ($i = $fromIndex; $i < $limit; $i++) {
        $token = $tokens[$i];
        if (_bc_gw_token_type($token) !== T_VARIABLE) {
            continue;
        }
        // Look ahead for '=' (skip whitespace), then 'array(' or '['.
        $j = $i + 1;
        while ($j < $limit && _bc_gw_is_whitespace_or_comment($tokens[$j])) $j++;
        if ($j >= $limit || _bc_gw_token_type($tokens[$j]) !== '=') continue;
        $j++;
        while ($j < $limit && _bc_gw_is_whitespace_or_comment($tokens[$j])) $j++;
        if ($j >= $limit) continue;

        $open_index = null;
        if (_bc_gw_token_type($tokens[$j]) === T_ARRAY) {
            $j++;
            while ($j < $limit && _bc_gw_is_whitespace_or_comment($tokens[$j])) $j++;
            if ($j < $limit && _bc_gw_token_type($tokens[$j]) === '(') {
                $open_index = $j;
                $close_char = ')';
            }
        } elseif (_bc_gw_token_type($tokens[$j]) === '[') {
            $open_index = $j;
            $close_char = ']';
        }
        if ($open_index === null) continue;

        // Find the matching close, tracking nested depth of the same bracket family.
        $depth = 0;
        $close_index = null;
        for ($k = $open_index; $k < $limit; $k++) {
            $ttype = _bc_gw_token_type($tokens[$k]);
            if ($ttype === '(' || $ttype === '[') $depth++;
            if ($ttype === ')' || $ttype === ']') {
                $depth--;
                if ($depth === 0) { $close_index = $k; break; }
            }
        }
        if ($close_index === null) continue; // Unbalanced — skip, keep scanning.

        $parsed = _bc_gw_parse_array_body($tokens, $open_index + 1, $close_index);
        return array($parsed, $close_index + 1);
    }
    return null;
}

// Finds every `$product_name == "value"` (or `$product_name === "value"`) comparison in the
// token stream, in source order, returning [["network" => value, "index" => tokenIndexOfString], ...].
function _bc_gw_find_product_name_markers($tokens) {
    $markers = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (_bc_gw_token_type($token) !== T_VARIABLE || _bc_gw_token_text($token) !== '$product_name') {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && _bc_gw_is_whitespace_or_comment($tokens[$j])) $j++;
        if ($j >= $count) continue;
        $optype = _bc_gw_token_type($tokens[$j]);
        if ($optype !== T_IS_EQUAL && $optype !== T_IS_IDENTICAL) continue;
        $j++;
        while ($j < $count && _bc_gw_is_whitespace_or_comment($tokens[$j])) $j++;
        if ($j >= $count || _bc_gw_token_type($tokens[$j]) !== T_CONSTANT_ENCAPSED_STRING) continue;

        $markers[] = array(
            "network" => _bc_gw_unquote(_bc_gw_token_text($tokens[$j])),
            "index" => $j,
        );
    }
    return $markers;
}

/**
 * Extracts the real plan-code => internal-id map embedded in a gateway file for one network.
 * Returns the array on success (array_keys() of it are the plan codes admins should be able to
 * price), or false if the file/network/array literal couldn't be resolved.
 */
function bc_gateway_parse_plan_codes($gateway_file_path, $product_name) {
    if (!$gateway_file_path || !file_exists($gateway_file_path)) return false;
    $source = file_get_contents($gateway_file_path);
    if ($source === false) return false;

    $tokens = token_get_all($source);
    $markers = _bc_gw_find_product_name_markers($tokens);

    $match_index = null;
    $next_index = null;
    foreach ($markers as $pos => $marker) {
        if ($marker["network"] === $product_name) {
            $match_index = $marker["index"];
            $next_index = isset($markers[$pos + 1]) ? $markers[$pos + 1]["index"] : null;
            break;
        }
    }
    if ($match_index === null) return false;

    $found = _bc_gw_find_array_assignment($tokens, $match_index, $next_index);
    if ($found === null) return false;

    return $found[0];
}

/**
 * Extracts every network's plan-code map from a gateway file in one pass — used for the
 * bulk dry-run triage and for "Apply All" style bulk syncs. Returns [network => [code => id, ...], ...].
 */
function bc_gateway_parse_all_networks($gateway_file_path) {
    if (!$gateway_file_path || !file_exists($gateway_file_path)) return array();
    $source = file_get_contents($gateway_file_path);
    if ($source === false) return array();

    $tokens = token_get_all($source);
    $markers = _bc_gw_find_product_name_markers($tokens);

    $results = array();
    foreach ($markers as $pos => $marker) {
        $network = $marker["network"];
        if (isset($results[$network])) continue; // First match wins if a network name repeats.
        $next_index = isset($markers[$pos + 1]) ? $markers[$pos + 1]["index"] : null;
        $found = _bc_gw_find_array_assignment($tokens, $marker["index"], $next_index);
        if ($found !== null) {
            $results[$network] = $found[0];
        }
    }
    return $results;
}

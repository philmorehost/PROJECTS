<?php
/** Strict nonnegative money input: zero is valid, malformed/negative values are not. */
function bc_price_input($value) {
    if (!is_string($value) && !is_int($value)) return null;
    $value = trim((string)$value);
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/D', $value)) return null;
    return $value;
}

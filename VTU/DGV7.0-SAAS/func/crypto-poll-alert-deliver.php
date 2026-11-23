<?php
/** Retired provider launcher. Never bootstrap application DB, secrets or transports. */
if(PHP_SAPI!=='cli'){
    http_response_code(410);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Retired crypto integration.');
}
fwrite(STDERR,"Retired crypto integration. Remove this legacy scheduler entry. No work performed.\n");
exit(2);

<?php
/** Single-owner edition must never expose public vendor account creation. */
http_response_code(410);header('Cache-Control: no-store');
exit('Vendor registration is not available on this single-owner installation.');

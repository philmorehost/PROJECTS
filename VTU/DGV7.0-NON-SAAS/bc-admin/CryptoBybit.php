<?php
http_response_code(410);
header('Cache-Control: no-store');
exit('This provider integration has been retired.');

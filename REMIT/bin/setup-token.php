<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Operator setup tokens have been retired. Open REMIT in your browser and enter your License Manager key at installation stage 1. Existing installations can activate under Portal settings.\n");
exit(1);

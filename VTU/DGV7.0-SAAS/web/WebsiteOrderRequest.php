<?php
/** All vendor creation must use verified intake and the platform review queue. */
header('Cache-Control: no-store, private');
header('Location: /bc-spadmin/VendorReg.php', true, 303);
exit;

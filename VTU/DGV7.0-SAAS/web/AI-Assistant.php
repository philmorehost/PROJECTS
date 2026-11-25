<?php
/**
 * Support has moved to /web/Hub.php?g=settings-help.
 * Keep this route as a clean redirect for older links and bookmarks.
 */
header("Location: Hub.php?g=settings-help", true, 302);
exit();

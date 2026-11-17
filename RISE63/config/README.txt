Private configuration directory.

Files written here by the installer (never commit them):

  installed.lock      present once setup is complete; the installer refuses to run again
  database.php        PDO credentials, mode 0600
  activation-key.php  licence key from manager.pmhserver.name.ng, mode 0600
  activation-cache.json  last successful licence check (6-hour cache, 24-hour grace window)
  smtp.php            SMTP credentials for bin/mail-worker.php
  trusted_proxies.php CIDR list of proxies whose X-Forwarded-* headers may be trusted
  geo.php             country resolution mode
  firewall.php        firewall adapter used by bin/security-worker.php

This directory is denied over HTTP by .htaccess. On the host, also make it
non-listable and unwritable after setup (see docs/DEPLOYMENT.md §2).

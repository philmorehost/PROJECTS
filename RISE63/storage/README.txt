Private writable storage. Denied over HTTP.

  sessions/    PHP session files
  documents/   applicant uploads, random 32-hex names, served only through
               index.php?action=document-download after a scope check
  logs/        optional worker logs
  updates/     release archives and the backup taken before an update was applied
               (backup/<UTC stamp>/). Written by bin/update.php and the admin Updates page.

Never serve this directory. Never place PHP that the web server will execute here.

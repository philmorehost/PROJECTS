Private writable storage. Denied over HTTP.

  sessions/    PHP session files
  documents/   applicant uploads, random 32-hex names, served only through
               index.php?action=document-download after a scope check
  logs/        optional worker logs

Never serve this directory. Never place PHP that the web server will execute here.

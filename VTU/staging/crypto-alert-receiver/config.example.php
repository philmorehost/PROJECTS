<?php
// Copy to private config.php OUTSIDE public_html. Do not commit real values or send them in chat.
return [
    'enabled'=>false,
    'hostname'=>'', // Exact lowercase staging hostname, no scheme, path or port.
    'key'=>'', // Generate 32 random bytes, represented as 64 lowercase hex characters.
    'database'=>['host'=>'localhost','name'=>'','user'=>'','password'=>''],
];

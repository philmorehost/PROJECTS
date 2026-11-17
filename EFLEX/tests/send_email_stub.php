<?php
$GLOBALS['SENT_EMAILS'] = array();
function send_email($to, $subject, $body) {
    $GLOBALS['SENT_EMAILS'][] = array('to' => $to, 'subject' => $subject, 'body' => $body);
    return true;
}

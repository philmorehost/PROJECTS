<?php
/** Internal encrypted narrative-evidence store. No uploads, URLs, provider calls or plaintext fallback. */
require_once __DIR__.'/bc-crypto-custody.php';
function bc_crypto_custody_evidence_key($id) {
    if (!is_string($id)||!preg_match('/^V[1-9][0-9]{0,2}$/D',$id) || !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) throw new RuntimeException('crypto_evidence_key_unavailable');
    $encoded=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_'.$id);
    $key=is_string($encoded)?base64_decode($encoded,true):false;
    if ($key===false || strlen($key)!==32) throw new RuntimeException('crypto_evidence_key_unavailable');
    return $key;
}
function bc_crypto_custody_evidence_text($text) {
    if (!is_string($text)||strlen($text)<20||strlen($text)>16384||!preg_match('//u',$text)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) throw new InvalidArgumentException('crypto_evidence_text_invalid');
    // This is not a DLP guarantee. Operators must never submit secrets or customer identity documents.
    if (preg_match('/private[ _-]?key|seed[ _-]?phrase|mnemonic|api[ _-]?key\s*[:=]|secret\s*[:=]|password\s*[:=]/i',$text)) throw new InvalidArgumentException('crypto_evidence_sensitive_material');
    return $text;
}
function bc_crypto_custody_evidence_seal($identity,$env,$text) {
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);bc_crypto_custody_evidence_text($text);
    $hash=hash('sha256',$text);$id=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY')?:'V1';
    $key=bc_crypto_custody_evidence_key($id);$nonce=random_bytes(12);$tag='';
    $aad=bc_crypto_gov_json([$identity,$env,$hash]);
    $cipher=openssl_encrypt($text,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if ($cipher===false) throw new RuntimeException('crypto_evidence_seal_failed');
    return ['hash'=>$hash,'sealed'=>bc_crypto_gov_json(['key'=>$id,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)])];
}
function bc_crypto_custody_evidence_open($identity,$env,$hash,$sealed) {
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);bc_crypto_custody_digest($hash);
    if (!is_string($sealed)||strlen($sealed)>32768) throw new RuntimeException('crypto_evidence_unreadable');
    $d=json_decode($sealed,true,8);
    if (!is_array($d)) throw new RuntimeException('crypto_evidence_unreadable');
    $key=bc_crypto_custody_evidence_key($d['key']??null);
    foreach(['nonce','tag','cipher'] as $field) {
        if (!is_string($d[$field]??null)||($d[$field]=base64_decode($d[$field],true))===false) throw new RuntimeException('crypto_evidence_unreadable');
    }
    if (strlen($d['nonce'])!==12||strlen($d['tag'])!==16) throw new RuntimeException('crypto_evidence_unreadable');
    $plain=openssl_decrypt($d['cipher'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$d['nonce'],$d['tag'],bc_crypto_gov_json([$identity,$env,$hash]));
    if ($plain===false||!hash_equals($hash,hash('sha256',$plain))) throw new RuntimeException('crypto_evidence_unreadable');
    return bc_crypto_custody_evidence_text($plain);
}
function bc_crypto_custody_evidence_engines() {
    bc_crypto_custody_engines();
    foreach(['sas_crypto_v2_custody_evidence','sas_crypto_v2_custody_evidence_events'] as $table) {
        $r=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$r||strtoupper($r['engine']??'')!=='INNODB') throw new RuntimeException('crypto_evidence_transactional_schema_required');
    }
}
/** Internal callers must authenticate the actor before invoking. Records are immutable via this API. */
function bc_crypto_custody_evidence_store($authority,$identity,$env,$text) {
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);$sealed=bc_crypto_custody_evidence_seal($identity,$env,$text);
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_custody_evidence_engines();
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_custody_evidence(custody_account,evidence_hash,environment,sealed_document,stored_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE evidence_hash=evidence_hash',[$identity,$sealed['hash'],$env,$sealed['sealed'],$actor]);
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_evidence_events(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch) VALUES(?,?,?,0,'stored',?)",[$identity,$sealed['hash'],$actor,time()]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_evidence_commit_failed');
        return $sealed['hash'];
    } catch(Throwable $e) {mysqli_rollback($connection_server);throw $e;}
}
/** Successful decryption is not returned until a view audit/receipt has committed. */
function bc_crypto_custody_evidence_view($authority,$identity,$env,$hash,$revision) {
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_custody_digest($identity);bc_crypto_custody_digest($hash);bc_crypto_gov_environment($env);
    if (!is_int($revision)||$revision<0) throw new InvalidArgumentException('crypto_custody_revision_invalid');
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_custody_evidence_engines();
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        $account=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if (!$account||$account['environment']!==$env||(int)$account['revision']!==$revision) throw new RuntimeException('crypto_custody_edit_conflict');
        $row=bc_q_row('SELECT * FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND evidence_hash=? AND environment=?',[$identity,$hash,$env]);
        if (!$row) throw new RuntimeException('crypto_evidence_missing');
        $text=bc_crypto_custody_evidence_open($identity,$env,$hash,$row['sealed_document']);
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_evidence_events(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch) VALUES(?,?,?,?,'viewed',?)",[$identity,$hash,$actor,$revision,time()]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_evidence_commit_failed');
        return $text;
    } catch(Throwable $e) {mysqli_rollback($connection_server);throw $e;}
}
function bc_crypto_custody_evidence_require($identity,$env,$hash,$actor,$revision,$review) {
    bc_crypto_custody_evidence_engines();
    $row=bc_q_row('SELECT evidence_hash FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND environment=? AND evidence_hash=?',[$identity,$env,$hash]);
    if (!$row) throw new RuntimeException('crypto_evidence_missing');
    if ($review) {
        $receipt=bc_q_row("SELECT id FROM sas_crypto_v2_custody_evidence_events WHERE custody_account=? AND evidence_hash=? AND actor_id=? AND revision=? AND action='viewed' AND occurred_at_epoch>=? AND occurred_at_epoch<=? LIMIT 1",[$identity,$hash,$actor,$revision,time()-900,time()]);
        if (!$receipt) throw new RuntimeException('crypto_evidence_fresh_view_required');
    }
}

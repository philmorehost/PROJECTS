<?php
/** Account-lock serialized metadata, not global credit deduplication or risk clearance. */
function bc_crypto_poll_overlap_classify($pair_exists,$conflict,$snapshot_exists) {
    if ($conflict) return 'conflict';
    if (!$pair_exists) return 'first_seen';
    return $snapshot_exists?'repeat':'changed';
}
/** Owner checkpoint transaction holds custody lock, then stream; never call as an intake API. */
function bc_crypto_poll_overlap_store($row,$revision,$records,$evidence_hash) {
    foreach($records as $record) {
        $rh=hash('sha256',$record['provider_record_key']);$th=$record['transfer_fingerprint'];
        $snapshot=hash('sha256',bc_crypto_gov_json($record));
        $scope=[$row['custody_account'],$row['environment']];
        $pair=bc_q_row('SELECT record_hash FROM sas_crypto_v2_poll_identities WHERE custody_account=? AND environment=? AND record_hash=? AND transfer_hash=?',array_merge($scope,[$rh,$th]));
        $conflict=bc_q_row('SELECT record_hash FROM sas_crypto_v2_poll_identities WHERE custody_account=? AND environment=? AND ((record_hash=? AND transfer_hash<>?) OR (transfer_hash=? AND record_hash<>?)) LIMIT 1',array_merge($scope,[$rh,$th,$th,$rh]));
        $previous=bc_q_row('SELECT p.record_hash FROM sas_crypto_v2_poll_sightings p JOIN sas_crypto_v2_poll_streams s ON s.stream_id=p.stream_id WHERE s.custody_account=? AND s.environment=? AND p.record_hash=? AND p.transfer_hash=? AND p.snapshot_hash=? LIMIT 1',array_merge($scope,[$rh,$th,$snapshot]));
        $classification=bc_crypto_poll_overlap_classify((bool)$pair,(bool)$conflict,(bool)$previous);
        if (!$pair)bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_identities(custody_account,environment,record_hash,transfer_hash) VALUES(?,?,?,?)',array_merge($scope,[$rh,$th]));
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_sightings(stream_id,revision,record_hash,transfer_hash,snapshot_hash,evidence_hash,classification) VALUES(?,?,?,?,?,?,?)',[$row['stream_id'],$revision,$rh,$th,$snapshot,$evidence_hash,$classification]);
    }
}

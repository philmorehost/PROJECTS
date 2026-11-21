<?php
/** Fail closed before BEGIN: never implicitly commit an outer caller's transaction. */
function bc_crypto_transaction_ready() {
    try {
        $state=bc_q_row('SELECT @@session.autocommit AS auto_commit');
        if (!$state || (int)$state['auto_commit']!==1) return false;
        // MySQL lacks MariaDB's @@in_transaction. SET TRANSACTION (not SESSION)
        // is rejected during an active transaction, including one without any reads.
        // It does not commit/roll back caller work. Also fixes the isolation of our next transaction.
        return bc_q('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===true;
    } catch(Throwable $e) { return false; }
}

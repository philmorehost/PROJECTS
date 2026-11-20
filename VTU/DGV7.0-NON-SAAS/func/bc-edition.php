<?php
/**
 * DGV7 §28 — script edition marker (NON-SAAS tree).
 *
 * The two edition trees share almost every file byte-for-byte, so behaviour
 * that must differ by edition (marketplace defaults, commercialised API
 * access) needs one tiny per-tree source of truth. This file is deliberately
 * NOT edition-identical: the SAAS tree ships the same function returning
 * 'saas'. Never add it to the cross-edition parity lists.
 */

if (!function_exists('bc_script_edition')) {
    /** @return string 'saas' on the multi-vendor tree, 'non-saas' on the single-owner tree. */
    function bc_script_edition() {
        return 'non-saas';
    }
}

if (!function_exists('bc_is_saas_edition')) {
    function bc_is_saas_edition() {
        return bc_script_edition() === 'saas';
    }
}

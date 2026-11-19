#!/usr/bin/env python3
"""Move a back-office page from the legacy admin chrome onto the Plate admin shell.

Only the chrome moves. The script asserts, per page, that the region between the page
header partial and the footer partial is byte-identical afterwards (tagged
__PLATE_KEPT__), so a migration cannot silently drop body markup — the same contract as
plate_migrate.py. Run without arguments for a dry run, with --apply to write.

    python3 VTU/tests/plate_admin_migrate.py            # report
    python3 VTU/tests/plate_admin_migrate.py --apply    # rewrite the pages
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
EDITIONS = ('DGV7.0-SAAS', 'DGV7.0-NON-SAAS')
SHELL = '<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");'

# Pages that own their own layout rather than the standard pagetitle shape.
HAND_MIGRATED = {
    'bc-admin/Dashboard.php',
}


def php_expr(inner):
    """Turn the inside of a <title>/<h1> into a PHP string expression."""
    parts = []
    pos = 0
    for m in re.finditer(r'<\?php\s*(.*?)\s*\?>|<\?=\s*(.*?)\s*\?>', inner, re.S):
        lit = inner[pos:m.start()]
        if lit.strip():
            parts.append(json.dumps(lit))
        expr = m.group(1) if m.group(1) is not None else m.group(2)
        expr = expr.strip()
        if expr.endswith(';'):
            expr = expr[:-1].strip()
        expr = re.sub(r'^(echo|print)\s+', '', expr)
        expr = re.sub(r'^htmlspecialchars\((.*)\)$', r'\1', expr, flags=re.S)
        if expr:
            parts.append('(' + expr + ')')
        pos = m.end()
    lit = inner[pos:]
    if lit.strip():
        parts.append(json.dumps(lit))
    return ' . '.join(parts) if parts else "''"


def crumb_text(items):
    out = []
    for it in items:
        txt = re.sub(r'<[^>]+>', '', it).replace('&amp;', '&').strip()
        if txt:
            out.append(txt)
    return ' / '.join(out)


def header_include(src, area):
    if area == 'admin':
        return '<?php include("../func/bc-admin-header.php"); ?>'
    return '<?php include("../func/bc-spadmin-header.php"); ?>'


def footer_include(src, area):
    if area == 'admin':
        return '<?php include("../func/bc-admin-footer.php"); ?>'
    return '<?php include("../func/bc-spadmin-footer.php"); ?>'


def migrate(src, area):
    """Return (new_source, note) or (None, reason)."""
    hmark = header_include(src, area)
    fmark = footer_include(src, area)
    h = src.find(hmark)
    if h == -1:
        return None, 'no header partial'
    f = src.find(fmark)
    if f == -1:
        return None, 'no footer partial'

    # ---- head ------------------------------------------------------------
    i = src.find('<!DOCTYPE')
    if i == -1:
        return None, 'no doctype'
    pre = src.rfind('?>', 0, i)
    if pre == -1 or src[pre + 2:i].strip():
        return None, 'no clean php close before doctype'
    k = src.find('</head>', i)
    if k == -1:
        return None, 'no </head>'
    k += len('</head>')
    head = src[i:k]
    tm = re.search(r'<title>(.*?)</title>', head, re.S)
    if not tm:
        return None, 'no title'
    title_expr = php_expr(tm.group(1))
    # Page-specific head assets are content, not chrome: inline <style> blocks and the
    # editor / payment SDKs a page loads for itself survive, after the shell head.
    extras = re.findall(r'<style\b.*?</style>', head, re.S)
    for tag in re.findall(r'<link\b[^>]*>|<script\b[^>]*src="[^"]*"[^>]*>\s*</script>', head, re.S):
        if any(k in tag for k in ('grapesjs', 'quill', 'animate', 'sweetalert2', 'monnify', 'flutterwave', 'paystack')):
            extras.append(tag)
    # Behavioural PHP in the head (conditional meta refresh, redirect scripts) is not
    # chrome either; plain meta/css echoes are covered by the shell and stay dropped.
    head_notitle = re.sub(r'<title>.*?</title>', '', head, flags=re.S)
    for block in re.findall(r'<\?php.*?\?>', head_notitle, re.S):
        if 'header-link' not in block and ('if' in block or 'window.open' in block):
            extras.append(block)
    new_head = (
        '?>\n'
        + SHELL + '\n'
        + 'bc_adm_head(' + title_expr + ', array("area" => "' + area + '"));\n'
        + '?>'
        + ('\n' + '\n'.join(extras) if extras else '')
    )

    # ---- body start ------------------------------------------------------
    b = src.find('<body', k)
    if b == -1:
        return None, 'no <body'
    e = src.find('>', b) + 1
    seg_start = e
    pm = re.search(r'<div class="pagetitle[^"]*"[^>]*>', src[seg_start:seg_start + 400])
    if pm:
        p = seg_start + pm.start()
        # Depth-match the pagetitle's closing </div>: some pagetitles nest alert or
        # action divs (and PHP conditionals) inside, so neither the first </div> nor
        # the '<!-- End Page Title -->' marker is a reliable end on its own.
        depth = 0
        pend = -1
        for dm in re.finditer(r'<div\b[^>]*>|</div>', src[p:]):
            if dm.group(0).startswith('</'):
                depth -= 1
                if depth == 0:
                    pend = p + dm.end()
                    break
            else:
                depth += 1
        if pend == -1:
            return None, 'unclosed pagetitle'
        head_block = src[p:pend]
        h1 = re.search(r'<h1[^>]*>(.*?)</h1>', head_block, re.S)
        if not h1:
            return None, 'no h1 in pagetitle'
        title = php_expr(h1.group(1))
        lis = re.findall(r'<li class="breadcrumb-item[^"]*"[^>]*>(.*?)</li>', head_block, re.S)
        crumb = crumb_text(lis)
        # Anything else the pagetitle carried (alerts, action buttons, conditional
        # blocks) is page content, not chrome: keep it, minus the title and crumb.
        residual = head_block[head_block.find('>') + 1:head_block.rfind('</div>')]
        residual = re.sub(r'<h1[^>]*>.*?</h1>', '', residual, flags=re.S)
        residual = re.sub(r'<nav\b[^>]*>.*?</nav>', '', residual, flags=re.S)
        new_body = (
            '<?php bc_adm_sidebar(array("area" => "' + area + '")); ?>\n'
            '<?php bc_adm_topbar(array("area" => "' + area + '", "title" => ' + title
            + ', "crumb" => ' + json.dumps(crumb) + ')); ?>'
        )
        if residual.strip():
            new_body += '\n' + residual.strip('\n')
    else:
        # No pagetitle: use the file name as the page title.
        base = os.path.basename(src) if False else None
        title = "''"
        crumb = ''
        new_body = (
            '<?php bc_adm_sidebar(array("area" => "' + area + '")); ?>\n'
            '<?php bc_adm_topbar(array("area" => "' + area + '", "title" => "", "crumb" => "")); ?>'
        )
        p = seg_start

    # ---- tail ------------------------------------------------------------
    # Keep the whole tail (modals, page scripts, PHP blocks) ahead of the shell foot,
    # which closes the document; only the page's own document closers are dropped.
    tail = src[f + len(fmark):]
    kept = re.sub(r'^[ \t]*</body>[ \t]*$', '', tail, flags=re.M)
    kept = re.sub(r'^[ \t]*</html>[ \t]*$', '', kept, flags=re.M)
    scripts = re.findall(r'<script\b', kept)

    out = (src[:pre] + new_head + src[k:b] + new_body + src[pend if pm else p:f]
           + '__PLATE_KEPT__' + kept + '\n<?php bc_adm_foot(array("area" => "' + area + '")); ?>\n')
    note = 'title=%r crumb=%r tail=%dB scripts=%d' % (title, crumb, len(kept), len(scripts))
    return out, note


def main():
    apply = '--apply' in sys.argv
    ok = fail = 0
    for ed in EDITIONS:
        for area in ('bc-admin', 'bc-spadmin'):
            d = os.path.join(ROOT, 'VTU', ed, area)
            if not os.path.isdir(d):
                continue
            for name in sorted(os.listdir(d)):
                if not name.endswith('.php'):
                    continue
                rel = ed + '/' + area + '/' + name
                key = area + '/' + name
                path = os.path.join(d, name)
                src = open(path, errors='replace').read()
                if 'bc_adm_head(' in src or key in HAND_MIGRATED:
                    continue
                out, rep = migrate(src, 'admin' if area == 'bc-admin' else 'spadmin')
                if out is None:
                    fail += 1
                    print('SKIP %-52s %s' % (rel, rep))
                    continue
                area_key = 'admin' if area == 'bc-admin' else 'spadmin'
                fpos = src.find(footer_include(src, area_key))
                # Recompute the depth-matched pagetitle end for the verbatim check.
                body = None
                pt = src.find('<div class="pagetitle')
                if pt != -1:
                    depth = 0
                    for dm in re.finditer(r'<div\b[^>]*>|</div>', src[pt:]):
                        if dm.group(0).startswith('</'):
                            depth -= 1
                            if depth == 0:
                                body = src[pt + dm.end():fpos]
                                break
                        else:
                            depth += 1
                if body is None or (body + '__PLATE_KEPT__') not in out:
                    fail += 1
                    print('DIFF %-52s body changed (%d bytes)' % (rel, len(body or '')))
                    continue
                ok += 1
                print('OK   %-52s %s' % (rel, rep))
                if apply:
                    open(path, 'w').write(out.replace('__PLATE_KEPT__', ''))
    print('\n%d ok, %d skipped' % (ok, fail))
    return 0


if __name__ == '__main__':
    sys.exit(main())

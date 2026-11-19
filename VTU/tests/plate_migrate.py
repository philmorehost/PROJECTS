#!/usr/bin/env python3
"""Move a console page from the retired template chrome onto the Plate app shell.

Only the chrome moves. The script asserts, per page, that the region between the page
header block and the footer partial is byte-identical afterwards — so a migration cannot
silently drop body markup. Run without arguments for a dry run, with --apply to write.

    python3 VTU/tests/plate_migrate.py            # report
    python3 VTU/tests/plate_migrate.py --apply    # rewrite the pages
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
EDITIONS = ('DGV7.0-SAAS', 'DGV7.0-NON-SAAS')

HEADER_INCLUDE = '<?php include("../func/bc-header.php"); ?>'
FOOTER_INCLUDE = '<?php include("../func/bc-footer.php"); ?>'
BUNDLE = '    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>\n'

# Pages that own their own layout rather than the standard pagetitle shape.
HAND_MIGRATED = {'DGV7.0-NON-SAAS/web/AI-Assistant.php', 'DGV7.0-NON-SAAS/web/Pricing.php'}


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
        # bc_app_head() escapes the title itself, so an explicit htmlspecialchars here
        # would escape twice. Unwrap it.
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


def migrate(src):
    """Return (new_source, note) or (None, reason)."""
    s = src

    # ---- head ------------------------------------------------------------
    i = s.find('<!DOCTYPE')
    if i == -1:
        return None, 'no doctype'
    pre = s.rfind('?>', 0, i)
    if pre == -1 or s[pre + 2:i].strip():
        return None, 'no clean php close before doctype'
    k = s.find('</head>', i)
    if k == -1:
        return None, 'no </head>'
    k += len('</head>')
    head = s[i:k]
    tm = re.search(r'<title>(.*?)</title>', head, re.S)
    if not tm:
        return None, 'no title'
    title_expr = php_expr(tm.group(1))
    new_head = (
        '?>\n'
        '<?php\n'
        '// Plate app shell: one shared chrome for the signed-in console.\n'
        'include_once(__DIR__ . "/../func/bc-plate-app.php");\n'
        'bc_app_head(' + title_expr + ');\n'
        '?>'
    )

    # ---- body start ------------------------------------------------------
    b = s.find('<body', k)
    if b == -1:
        return None, 'no <body'
    e = s.find('>', b) + 1
    if not s[e:e + 200].lstrip().startswith(HEADER_INCLUDE):
        return None, 'header include not first in body'
    seg_start = e + len(HEADER_INCLUDE)
    pm = re.search(r'<div class="pagetitle[^"]*"[^>]*>', s[seg_start:seg_start + 400])
    if not pm:
        return None, 'no pagetitle div'
    p = seg_start + pm.start()
    pend = s.find('</div>', p)
    if pend == -1:
        return None, 'unclosed pagetitle'
    pend += len('</div>')
    head_block = s[p:pend]
    h1 = re.search(r'<h1>(.*?)</h1>', head_block, re.S)
    if not h1:
        return None, 'no h1 in pagetitle'
    title = php_expr(h1.group(1))
    lis = re.findall(r'<li class="breadcrumb-item[^"]*"[^>]*>(.*?)</li>', head_block, re.S)
    crumb = crumb_text(lis)
    new_body = (
        '<?php bc_app_sidebar(); ?>\n'
        '<?php bc_app_topbar(array("title" => ' + title
        + ', "crumb" => ' + json.dumps(crumb) + ')); ?>'
    )

    # ---- tail ------------------------------------------------------------
    f = s.find(FOOTER_INCLUDE)
    if f == -1:
        return None, 'no footer include'
    tail = s[f + len(FOOTER_INCLUDE):]
    scripts = re.findall(r'<script\b.*?</script>', tail, re.S)
    short = re.findall(
        r'<div class="d-none d-md-block">\s*<\?php include\("\.\./func/short-trans\.php"\); \?>\s*</div>',
        tail, re.S)
    kept = '\n'.join(short + scripts)

    # The wrapper is tagged so the caller can assert the body survived verbatim.
    out = (s[:pre] + new_head + s[k:b] + new_body + s[pend:f]
           + '__PLATE_KEPT__' + kept + '\n\t<?php bc_app_foot(); ?>\n')
    note = 'title=%r crumb=%r scripts=%d short=%d' % (title_expr, crumb, len(scripts), len(short))
    return out, note


def main():
    apply = '--apply' in sys.argv
    ok = fail = 0
    for ed in EDITIONS:
        d = os.path.join(ROOT, 'VTU', ed, 'web')
        for name in sorted(os.listdir(d)):
            if not name.endswith('.php'):
                continue
            rel = ed + '/web/' + name
            path = os.path.join(d, name)
            src = open(path, errors='replace').read()
            if 'bc_app_head(' in src or rel in HAND_MIGRATED:
                continue
            out, rep = migrate(src)
            if out is None:
                fail += 1
                print('SKIP %-46s %s' % (rel, rep))
                continue
            body = src[src.index('</div>', src.index('<div class="pagetitle')) + 6:
                        src.find(FOOTER_INCLUDE)]
            if (body + '__PLATE_KEPT__') not in out:
                fail += 1
                print('DIFF %-46s body changed (%d bytes)' % (rel, len(body)))
                continue
            ok += 1
            print('OK   %-46s %s' % (rel, rep))
            if apply:
                open(path, 'w').write(out.replace('__PLATE_KEPT__', ''))
    print('\n%d ok, %d skipped' % (ok, fail))
    return 0


if __name__ == '__main__':
    sys.exit(main())

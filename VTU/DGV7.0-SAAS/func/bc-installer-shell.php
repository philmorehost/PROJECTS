<?php
/**
 * Installer shell — one shared layout for both editions' installation wizards.
 *
 * The installers are the first thing a customer sees, so they carry the same design tokens as
 * the landing page (template 6) and the auth shell. They are the only entry point to the
 * platform that runs before any tenant settings exist, so the brand colour is a fixed
 * default rather than a stored value.
 *
 * The shell is presentation only. It reads no credential, decides no entitlement and moves no
 * money; each installer keeps its own PHP logic verbatim and calls these helpers around its
 * existing markup.
 *
 * Helpers:
 *   bc_installer_css()       — the design-system stylesheet (one definition, both editions)
 *   bc_installer_head($title)— the <head> block (meta, fonts, stylesheet, inline CSS)
 *   bc_installer_step($n, $label) — one stepper dot + its caption
 *   bc_installer_stepper($step)    — the whole stepper row
 *   bc_installer_foot()      — the closing footer + document end
 */

if (!function_exists('bc_installer_css')) {
    /**
     * The installer stylesheet. Printed by bc_installer_head(); shared so the two wizards
     * cannot drift apart visually.
     */
    function bc_installer_css() {
        ?>
<style>
        :root {
            --brand: #0f766e;
            --brand-dark: #0d5f59;
            --ink: #0b1220;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #ffffff;
            --wash: #f6f8fb;
            --ok: #059669;
            --bad: #dc2626;
            --warn: #d97706;
            --radius: 16px;
            --shadow: 0 18px 44px rgba(16, 24, 40, .08);
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--wash); min-height: 100vh;
            display: flex; flex-direction: column; line-height: 1.6; overflow-x: hidden;
        }
        /* A soft brand wash instead of the old flat grey background. */
        .bc-installer__bg {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(760px 420px at 10% -8%, rgba(15, 118, 110, .14), transparent 70%),
                radial-gradient(700px 460px at 92% 10%, rgba(15, 118, 110, .10), transparent 70%),
                var(--wash);
        }
        .bc-installer {
            flex: 1; width: 100%; max-width: 880px; margin: 0 auto;
            padding: 28px 20px 20px; display: flex; flex-direction: column;
        }
        .bc-installer__brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .bc-installer__mark {
            width: 44px; height: 44px; border-radius: 13px; background: var(--brand); color: #fff;
            display: grid; place-items: center; font-weight: 800; font-size: 1.15rem;
            letter-spacing: -.02em; flex: none;
        }
        .bc-installer__brandtxt { line-height: 1.25; }
        .bc-installer__brandtxt strong { display: block; font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; }
        .bc-installer__brandtxt span { color: var(--muted); font-size: .82rem; }
        .bc-installer__card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); box-shadow: var(--shadow);
            padding: 30px 28px;
        }
        .bc-installer__title { font-size: 1.4rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 6px; }
        .bc-installer__sub { color: var(--muted); font-size: .95rem; margin-bottom: 22px; }

        /* Stepper: dots joined by a rule, with the caption under each dot. */
        .bc-installer__steps { display: flex; align-items: flex-start; margin: 4px 0 26px; }
        .bc-installer__step { display: flex; flex-direction: column; align-items: center; gap: 8px; flex: none; width: 74px; }
        .bc-installer__dot {
            width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center;
            font-size: .82rem; font-weight: 800; font-variant-numeric: tabular-nums;
            background: #eef2f7; color: var(--muted); border: 1px solid var(--line);
        }
        .bc-installer__steplabel { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); text-align: center; }
        .bc-installer__bar { flex: 1; height: 2px; background: var(--line); margin-top: 16px; }
        .bc-installer__step--done .bc-installer__dot, .bc-installer__step--now .bc-installer__dot { background: var(--brand); border-color: var(--brand); color: #fff; }
        .bc-installer__step--done .bc-installer__steplabel, .bc-installer__step--now .bc-installer__steplabel { color: var(--ink); }
        .bc-installer__step--done .bc-installer__dot i { display: none; }
        .bc-installer__step--done .bc-installer__dot b { display: block; }
        .bc-installer__dot b { display: none; }
        .bc-installer__bar--done { background: var(--brand); }

        .bc-installer__label { display: block; font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 7px; }
        .bc-installer__input, .bc-installer__select {
            width: 100%; font: inherit; font-size: .95rem; color: var(--ink);
            background: var(--wash); border: 1px solid var(--line); border-radius: 10px;
            padding: 12px 14px; outline: none;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .bc-installer__input:focus, .bc-installer__select:focus {
            border-color: var(--brand); box-shadow: 0 0 0 3px rgba(15, 118, 110, .14); background: #fff;
        }
        .bc-installer__field { margin-bottom: 16px; }
        .bc-installer__row { display: flex; gap: 14px; }
        .bc-installer__row > * { flex: 1; }
        .bc-installer__btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; font: inherit; font-size: .95rem; font-weight: 700; letter-spacing: .01em;
            color: #fff; background: var(--brand); border: 1px solid transparent;
            border-radius: 10px; padding: 13px 18px; cursor: pointer; text-decoration: none;
            transition: background .15s ease, opacity .15s ease;
        }
        .bc-installer__btn:hover { background: var(--brand-dark); color: #fff; }
        .bc-installer__btn[disabled] { opacity: .55; cursor: not-allowed; }
        .bc-installer__alert {
            border-radius: 10px; padding: 12px 14px; font-size: .9rem; margin-bottom: 18px;
            border: 1px solid; display: flex; gap: 10px; align-items: flex-start;
        }
        .bc-installer__alert--error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .bc-installer__alert--ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .bc-installer__alert--warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        /* Requirement checklist: name left, pill right. */
        .bc-installer__req { list-style: none; display: grid; gap: 8px; margin-bottom: 20px; }
        .bc-installer__req li {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: var(--wash); border: 1px solid var(--line); border-radius: 10px;
            padding: 10px 14px; font-size: .9rem;
        }
        .bc-installer__pill { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; border-radius: 999px; padding: 3px 10px; }
        .bc-installer__pill--ok { background: #ecfdf5; color: #065f46; }
        .bc-installer__pill--bad { background: #fef2f2; color: #991b1b; }
        .bc-installer__panel { display: none; }
        .bc-installer__panel.active { display: block; }
        .bc-installer__panel > h2:first-child { margin-top: 0; }
        .bc-installer__grid { display: grid; gap: 14px; }
        .bc-installer__grid--2 { grid-template-columns: 1fr 1fr; }
        .bc-installer__actions { display: flex; gap: 12px; }
        .bc-installer__actions > * { flex: 1; }
        .bc-installer__btn--ghost { background: transparent; color: var(--ink); border-color: var(--line); }
        .bc-installer__btn--ghost:hover { background: var(--wash); color: var(--ink); border-color: var(--muted); }
        .bc-installer__btn--link { background: transparent; color: var(--muted); border-color: var(--line); }
        .bc-installer__btn--link:hover { color: var(--ink); border-color: var(--muted); }
        .bc-installer__success { text-align: center; padding: 12px 0 4px; }
        .bc-installer__log {
            background: #0b1220; border-radius: 10px; padding: 14px; color: #94a3b8;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem;
            min-height: 84px; text-align: left;
        }
        .bc-installer__progress { background: #e2e8f0; border-radius: 6px; overflow: hidden; height: 6px; margin-top: 12px; }
        .bc-installer__progressbar { height: 6px; background: var(--brand); border-radius: 6px; width: 0; transition: width .5s ease; }
        .bc-installer__strength { height: 4px; border-radius: 2px; margin-top: 6px; transition: background .2s ease; }
        .bc-installer__spinner {
            width: 54px; height: 54px; margin: 0 auto 18px; border-radius: 50%;
            border: 4px solid var(--line); border-top-color: var(--brand);
            animation: bc-installer__spin 1s linear infinite;
        }
        @keyframes bc-installer__spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .bc-installer__spinner { animation: none; }
        }
        .bc-installer__successicon {
            width: 66px; height: 66px; border-radius: 50%; margin: 0 auto 18px;
            display: grid; place-items: center; background: #ecfdf5; color: var(--ok);
        }
        .bc-installer__foot { padding: 20px 4px 4px; text-align: center; color: var(--muted); font-size: .82rem; }
        /* The standalone wizard still uses Bootstrap grid and button classes. */
        .bc-installer__card .row { display: flex; flex-wrap: wrap; gap: 14px; }
        .bc-installer__card .col-md-6 { flex: 1 1 260px; }
        .bc-installer__card .g-3 { gap: 14px; }
        .bc-installer__card .mb-3 { margin-bottom: 14px; }
        .bc-installer__card .input-group { display: flex; }
        .bc-installer__card .input-group .bc-installer__input { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .bc-installer__card .input-group button { border: 1px solid var(--line); border-left: 0; border-radius: 0 10px 10px 0; background: var(--wash); padding: 0 14px; color: var(--muted); cursor: pointer; }
        .bc-installer__card .btn { font: inherit; font-size: .92rem; font-weight: 700; border-radius: 10px; padding: 11px 18px; cursor: pointer; border: 1px solid transparent; }
        .bc-installer__card .btn-primary { background: var(--brand); color: #fff; }
        .bc-installer__card .btn-outline-primary { background: transparent; color: var(--brand); border-color: var(--brand); }
        .bc-installer__card .btn-outline-secondary { background: transparent; color: var(--ink); border-color: var(--line); }
        .bc-installer__card .rounded-pill { border-radius: 999px; }
        .bc-installer__card .px-4 { padding-left: 18px; padding-right: 18px; }
        .bc-installer__card .flex-grow-1 { flex: 1; }
        .bc-installer__card .d-flex { display: flex; }
        .bc-installer__card .gap-3 { gap: 14px; }
        .bc-installer__card .mt-4 { margin-top: 16px; }
        .bc-installer__card .text-center { text-align: center; }
        .bc-installer__card .py-4 { padding-top: 20px; padding-bottom: 20px; }
        .bc-installer__card .fw-bold { font-weight: 700; }
        .bc-installer__card .form-label { display: block; font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 7px; }
        .bc-installer__card .form-text { color: var(--muted); font-size: .8rem; margin-top: 6px; }
        .bc-installer__card .form-control, .bc-installer__card .form-select {
            width: 100%; font: inherit; font-size: .95rem; color: var(--ink);
            background: var(--wash); border: 1px solid var(--line); border-radius: 10px;
            padding: 12px 14px; outline: none; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .bc-installer__card .form-control:focus, .bc-installer__card .form-select:focus {
            border-color: var(--brand); box-shadow: 0 0 0 3px rgba(15, 118, 110, .14); background: #fff;
        }
        .bc-installer__card .form-control::placeholder { color: #94a3b8; }
        .bc-installer__card .section-label { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin: 20px 0 12px; }
        .bc-installer__card .bg-light { background: var(--wash); }
        .bc-installer__card .rounded-3 { border-radius: 12px; }
        .bc-installer__card .p-3 { padding: 14px; }
        .bc-installer__card .p-4 { padding: 18px; }
        .bc-installer__card .border { border: 1px solid var(--line); }
        .bc-installer__card .text-start { text-align: left; }
        .bc-installer__card .text-danger { color: var(--bad); }
        .bc-installer__card .text-success { color: var(--ok); }
        .bc-installer__card .spinner-border { display: inline-block; width: 1rem; height: 1rem; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: bc-installer__spin 1s linear infinite; vertical-align: -2px; }
        .bc-installer__card .me-1 { margin-right: 6px; }
        .bc-installer__card .me-2 { margin-right: 8px; }
        .bc-installer__card .ms-2 { margin-left: 8px; }
        .bc-installer__card .mt-2 { margin-top: 8px; }
        .bc-installer__card .mt-1 { margin-top: 4px; }
        .bc-installer__card .mb-0 { margin-bottom: 0; }
        .bc-installer__card .mb-2 { margin-bottom: 8px; }
        .bc-installer__card .mb-4 { margin-bottom: 16px; }
        .bc-installer__card .small { font-size: .85rem; }
        .bc-installer__card .mt-3 { margin-top: 12px; }
        .bc-installer__card .justify-content-between { justify-content: space-between; }
        .bc-installer__card .align-items-center { align-items: center; }
        .bc-installer__card .alert { border-radius: 10px; border: 1px solid; font-size: .9rem; padding: 12px 14px; margin-bottom: 14px; }
        /* The installers still print Bootstrap utility classes around their legacy markup. */
        .bc-installer__card .mt-4 { margin-top: 16px; }
        .bc-installer__card .w-100 { width: 100%; }
        .bc-installer__card .mb-4 { margin-bottom: 16px; }
        .bc-installer__card .alert { border-radius: 10px; border: 1px solid; font-size: .9rem; padding: 12px 14px; margin-bottom: 18px; }
        .bc-installer__card .alert-error, .bc-installer__card .alert-danger { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .bc-installer__card .alert-success, .bc-installer__card .alert-ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .bc-installer__card .alert-warning, .bc-installer__card .alert-warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .bc-installer__card .text-muted { color: var(--muted) !important; }
        .bc-installer__card code { background: #f1f5f9; border-radius: 5px; padding: 1px 6px; font-size: .86em; }
        @media (max-width: 620px) {
            .bc-installer { padding: 18px 14px; }
            .bc-installer__card { padding: 22px 18px; }
            .bc-installer__row, .bc-installer__grid--2, .bc-installer__actions { flex-direction: column; gap: 0; }
            .bc-installer__grid--2 { grid-template-columns: 1fr; }
            .bc-installer__step { width: 58px; }
            .bc-installer__title { font-size: 1.2rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
</style>
        <?php
    }
}

if (!function_exists('bc_installer_head')) {
    /**
     * The <head> block. Prints the shared stylesheet; the caller opens <body>.
     */
    function bc_installer_head($title) {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
<?php bc_installer_css(); ?>
</head>
<body>
<div class="bc-installer__bg" aria-hidden="true"></div>
<?php
    }
}

if (!function_exists('bc_installer_step')) {
    /**
     * One stepper dot with its caption. $state is 'done', 'now' or '' (upcoming).
     */
    function bc_installer_step($n, $label, $state) {
        $class = 'bc-installer__step' . ($state !== '' ? ' bc-installer__step--' . $state : '');
        ?>
<div class="<?php echo $class; ?>" data-step="<?php echo (int)$n; ?>">
    <div class="bc-installer__dot"><span><?php echo (int)$n; ?></span><b><i class="bi bi-check"></i></b></div>
    <div class="bc-installer__steplabel"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></div>
</div>
<?php
    }
}

if (!function_exists('bc_installer_stepper')) {
    /**
     * The stepper row for a 4-step wizard. $step is the current 1-based step.
     */
    function bc_installer_stepper($step) {
        $labels = array('Requirements', 'Database', 'Admin', 'Done');
        echo '<div class="bc-installer__steps" aria-label="Installation progress">';
        foreach ($labels as $i => $label) {
            $n = $i + 1;
            $state = ($n < $step) ? 'done' : (($n === $step) ? 'now' : '');
            bc_installer_step($n, $label, $state);
            if ($n < count($labels)) {
                echo '<div class="bc-installer__bar' . ($n < $step ? ' bc-installer__bar--done' : '') . '" data-after="' . (int)$n . '"></div>';
            }
        }
        echo '</div>';
    }
}

if (!function_exists('bc_installer_js')) {
    /**
     * The installer's client-side behaviour. Both wizards are JS-driven steppers (the SAAS
     * wizard submits a real form per step), so this is shared rather than duplicated.
     */
    function bc_installer_js() {
        ?>
<script>
(function () {
    function goStep(n) {
        document.querySelectorAll('.bc-installer__panel').forEach(function (p) { p.classList.remove('active'); });
        var panel = document.getElementById('step-' + n);
        if (panel) { panel.classList.add('active'); }
        document.querySelectorAll('.bc-installer__step').forEach(function (s) {
            var i = parseInt(s.getAttribute('data-step'), 10);
            s.classList.toggle('bc-installer__step--done', i < n);
            s.classList.toggle('bc-installer__step--now', i === n);
        });
        document.querySelectorAll('.bc-installer__bar').forEach(function (b) {
            var i = parseInt(b.getAttribute('data-after'), 10);
            b.classList.toggle('bc-installer__bar--done', i < n);
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function toggleVis(id, btn) {
        var inp = document.getElementById(id);
        if (!inp) { return; }
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        if (btn) { btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>'; }
    }

    window.goStep = goStep;
    window.toggleVis = toggleVis;
})();
</script>
        <?php
    }
}

if (!function_exists('bc_installer_foot')) {
    /**
     * The closing footer, the shared script and the document end. $note is optional; the
     * $scripts string is the wizard's own inline JS (printed after the shared helpers).
     */
    function bc_installer_foot($note = '', $scripts = '') {
        ?>
<div class="bc-installer__foot">
    <?php if ($note !== '') echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . ' &middot; '; ?>DGV7.0 installation wizard
</div>
<?php bc_installer_js(); ?>
<?php if ($scripts !== '') { ?><script><?php echo $scripts; ?></script><?php } ?>
</body>
</html>
<?php
    }
}

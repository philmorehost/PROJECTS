/**
 * "The pull has been queued for twenty minutes."
 *
 * That card was the whole bug: nothing was slow. The job was still `queued`,
 * which means no worker had ever claimed it — and nothing in the panel said so.
 * 9.4.0's dispatcher returned the string 'http' as soon as cURL was called, so
 * a 403 from a WAF, an `exec()` that is disabled and a worker that really
 * started all looked identical, and every retry was a minute apart.
 *
 * This file pins down the three parts of the fix:
 *
 *   A. dispatch reports what it achieved, not what it attempted;
 *   B. a queued job carries a signed, short-lived worker URL, so the admin's
 *      own browser can start the pull when the server cannot;
 *   C. the console uses it — once per job, with the run token nowhere in sight.
 *
 * Usage: node qa/pull-dispatch.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { boot, LM } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 340) : '')); }
};

const json = raw => {
  const text = String(raw).trim();
  try { return JSON.parse(text); } catch (e) { /* fall through */ }
  const m = text.match(/[{[][\s\S]*[}\]]/);
  if (!m) return null;
  try { return JSON.parse(m[0]); } catch (e) { return null; }
};

const FAKE_PDO_SETUP = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_counts'] = array('licenses' => 12, 'script_updates' => 4, 'github_pulls' => 5, 'github_pull_jobs' => 0, 'jobs_running' => 0, 'update_notifications' => 7);
    $GLOBALS['qa_tiers'] = array(
      array('id'=>'1','tier_name'=>'DGV7.0-SAAS','tier_code'=>'SAAS','description'=>'Extended licence','project_key'=>'PRJ-SAAS','is_active'=>'1','github_repo'=>'philmorehost/PROJECTS','github_branch'=>'main','sort_order'=>'1','license_price'=>'5000','max_domains'=>'3')
    );
    $GLOBALS['qa_updates'] = array(
      array('id'=>'5','tier_id'=>'1','version_number'=>'7.40','is_released'=>'1','release_date'=>'2026-10-20 10:00:00','zip_path'=>'/secure_updates/saas/7.40.zip','checksum'=>'abc','changelog'=>'notes')
    );
    $GLOBALS['qa_pdo'] = new QAPdo();
`;

const QUEUED_JOB = {
  id: 51,
  tier_id: 1,
  status: 'queued',
  stage: 'queued',
  stage_label: 'Queued',
  progress: 0,
  message: 'Waiting for a worker…',
  message_text: 'Waiting for a worker…',
  version: '',
  commit: '',
  update_id: 0,
  created_at: '2026-10-24 10:00:00',
  started_at: '',
  finished_at: '',
  finished: false,
  cancelled: false,
  can_cancel: true,
  elapsed: 1290,
  queued_seconds: 1290,
  queue_note: 'No worker has picked this up in 21 min 30 s. This server cannot start a background worker (exec() disabled, loopback blocked, or cron not running), so the console is starting it in this page instead.',
  worker_url: 'https://manager.pmhserver.name.ng/pull-worker.php?job=51&ticket=1793000000.abc123',
  result: {},
};

const RUNNING_JOB = Object.assign({}, QUEUED_JOB, {
  status: 'running',
  stage: 'packaging',
  stage_label: 'Packaging files',
  progress: 61,
  message: 'Packaging 1400 of 2482 files…',
  message_text: 'Packaging 1400 of 2482 files…',
  started_at: '2026-10-24 10:21:00',
  queued_seconds: 0,
  queue_note: '',
  worker_url: '',
  elapsed: 30,
});

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakejobs.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakejobs.php')));
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  console.log('\nDispatch reports what it achieved');
  const dispatch = json(await run(`
    require '/lm/.qa/fakejobs.php';
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/pull_jobs.php';

    $pdo = new JobsPdo();
    $job = lm_pull_job_enqueue($pdo, 1, array('version' => '7.41'), 'admin');
    $result = lm_pull_job_dispatch($job, $pdo);

    echo json_encode(array(
      'result' => $result,
      'is_array' => is_array($result),
      'status' => (string) lm_pull_job_get($pdo, (int) $job['id'])['status'],
      'cli' => PHP_SAPI,
    ));
  `, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  ok('dispatch answers with a structure, not a bare string',
    dispatch && dispatch.is_array === true && typeof dispatch.result === 'object', JSON.stringify(dispatch && dispatch.result));
  ok('it does not claim a worker started when none did',
    dispatch && dispatch.result && dispatch.result.ok === false && dispatch.result.method === 'none',
    JSON.stringify(dispatch && dispatch.result));
  ok('the job is still queued, so the console knows to fall back',
    dispatch && dispatch.status === 'queued', dispatch && dispatch.status);
  ok('the reason names a cause an operator can act on',
    dispatch && dispatch.result && /exec\(\)|loopback|cURL|pull-worker|raw connection/i.test(dispatch.result.detail),
    dispatch && dispatch.result && dispatch.result.detail);
  ok('the reason is not a raw PHP error', dispatch && !/Fatal error|Warning:|Uncaught/i.test(dispatch.result.detail),
    dispatch && dispatch.result && dispatch.result.detail);

  console.log('\nThe browser holds a ticket, never the run token');
  const ticket = json(await run(`
    require '/lm/.qa/fakejobs.php';
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/pull_jobs.php';

    $pdo = new JobsPdo();
    $job = lm_pull_job_enqueue($pdo, 1, array('version' => '7.41'), 'admin');
    $token = (string) $job['run_token'];
    $public = lm_pull_job_public($job);

    $good = lm_pull_worker_ticket(7, $token);
    $out = array(
      'url' => $public['worker_url'],
      'has_ticket' => strpos((string) $public['worker_url'], 'ticket=') !== false,
      'leaks_token' => strpos((string) $public['worker_url'], $token) !== false,
      'good' => lm_pull_worker_verify_ticket(7, $token, $good),
      'wrong_job' => lm_pull_worker_verify_ticket(8, $token, $good),
      'wrong_token' => lm_pull_worker_verify_ticket(7, str_repeat('a', 32), $good),
      'garbage' => lm_pull_worker_verify_ticket(7, $token, 'nonsense'),
      'empty' => lm_pull_worker_verify_ticket(7, $token, ''),
      'expired' => lm_pull_worker_verify_ticket(7, $token, '1000000000.' . substr(hash_hmac('sha256', 'pull-worker|7|1000000000', $token), 0, 40)),
    );

    // A running job needs no worker URL: something already has it.
    $pdo->prepare("UPDATE github_pull_jobs SET status = 'running' WHERE id = ?")->execute(array((int) $job['id']));
    $running = lm_pull_job_get($pdo, (int) $job['id']);
    $out['running_url'] = lm_pull_job_worker_url($running);

    echo json_encode($out);
  `, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  ok('a queued job gets a worker URL', ticket && ticket.url !== '', JSON.stringify(ticket && ticket.url));
  ok('the URL carries a ticket', ticket && ticket.has_ticket === true);
  ok('the run token is not in the URL', ticket && ticket.leaks_token === false, ticket && ticket.url);
  ok('a fresh ticket verifies', ticket && ticket.good === true);
  ok('a ticket for another job does not', ticket && ticket.wrong_job === false);
  ok('a ticket signed with another token does not', ticket && ticket.wrong_token === false);
  ok('garbage does not verify', ticket && ticket.garbage === false);
  ok('an empty ticket does not verify', ticket && ticket.empty === false);
  ok('an expired ticket does not verify', ticket && ticket.expired === false);
  ok('a running job gets no worker URL', ticket && ticket.running_url === '');

  console.log('\nThe worker refuses what it should');
  const workerSrc = fs.readFileSync(path.join(LM, 'pull-worker.php'), 'utf8');

  ok('a request with neither token nor ticket is rejected before any work',
    /\$lmJobToken === '' && \$lmJobTicket === ''/.test(workerSrc), '');
  ok('an unknown job or a bad ticket answers 403',
    /!\$lmJob \|\| !\$lmAuthorised[\s\S]{0,120}http_response_code\(403\)/.test(workerSrc), '');
  ok('the worker accepts a ticket when the token is absent',
    /lm_pull_worker_verify_ticket\(\$lmJobId/.test(workerSrc), '');
  ok('a passing ticket is never trusted as the claim token',
    /The worker claims with the run token; a ticket only proves the request may/.test(workerSrc), '');
  ok('it answers "accepted" in the shape the dispatcher checks',
    /'accepted' => true/.test(workerSrc), '');
  ok('it does not start a session, so it cannot block the console polls',
    !/lm_session_start|session_start\(/.test(workerSrc), '');
  ok('it still accepts the run token over the header, for the server path',
    /HTTP_X_LM_PULL_TOKEN/.test(workerSrc), '');

  console.log('\nA job nobody claims is not left for ever');
  const reap = json(await run(`
    require '/lm/.qa/fakejobs.php';
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/pull_jobs.php';

    $pdo = new JobsPdo();
    $old = JobStore::insert(array(
      'tier_id' => 1, 'status' => 'queued', 'stage' => 'queued', 'progress' => 0,
      'message' => 'Waiting for a worker…', 'payload' => '{}', 'run_token' => 'tok',
      'actor' => 'admin', 'created_at' => gmdate('Y-m-d H:i:s', time() - 900),
    ));
    $fresh = JobStore::insert(array(
      'tier_id' => 1, 'status' => 'queued', 'stage' => 'queued', 'progress' => 0,
      'message' => 'Waiting for a worker…', 'payload' => '{}', 'run_token' => 'tok2',
      'actor' => 'admin', 'created_at' => gmdate('Y-m-d H:i:s', time() - 20),
    ));

    $reaped = lm_pull_job_reap($pdo);
    echo json_encode(array(
      'reaped' => $reaped,
      'old' => (string) lm_pull_job_get($pdo, $old)['status'],
      'old_message' => (string) lm_pull_job_get($pdo, $old)['message'],
      'fresh' => (string) lm_pull_job_get($pdo, $fresh)['status'],
      'unhandled' => isset($GLOBALS['qa_unhandled']) ? $GLOBALS['qa_unhandled'] : array(),
    ));
  `, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  ok('a queued job older than ten minutes is failed',
    reap && reap.old === 'error', JSON.stringify(reap && reap.old));
  ok('it says nothing was downloaded and points at Start again',
    reap && /No worker picked this pull up/i.test(reap.old_message) && /Start again/i.test(reap.old_message),
    reap && reap.old_message);
  ok('a queued job from a minute ago is left alone',
    reap && reap.fresh === 'queued', JSON.stringify(reap && reap.fresh));
  ok('the fake understood the reaper SQL', reap && Array.isArray(reap.unhandled) && reap.unhandled.length === 0,
    JSON.stringify(reap && reap.unhandled));

  console.log('\nThe console starts it, once');
  const html = String(await run(`
    ${FAKE_PDO_SETUP}
    require_once '/lm/includes/security.php';
    require_once '/lm/admin/_ui.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    require '/lm/admin/github-updates.php';`, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  const calls = [];
  const errors = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', e => {
    if (!/Not implemented: navigation/.test(String(e.message))) errors.push(String(e.message));
  });

  let statusPolls = 0;

  const dom = new JSDOM(html, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    url: 'https://manager.pmhserver.name.ng/admin/github-updates.php',
    virtualConsole,
    beforeParse(window) {
      window.TextEncoder = TextEncoder;
      window.confirm = () => true;
      window.fetch = (url, options) => {
        const form = options && options.body;
        const fields = {};
        if (form && typeof form.forEach === 'function') {
          form.forEach((value, key) => { fields[key] = String(value); });
        }
        calls.push({ url: String(url), fields, method: (options && options.method) || 'GET' });

        let payload = { status: 'success', server_version: '9.5.0' };
        if (fields.action === 'pull_last') payload = { status: 'success', job: QUEUED_JOB };
        if (fields.action === 'pull_status') {
          statusPolls++;
          payload = { status: 'success', job: statusPolls > 1 ? RUNNING_JOB : QUEUED_JOB };
        }
        if (fields.action === 'pulls') payload = { status: 'success', pulls: [] };
        if (fields.action === 'repos') payload = { status: 'success', repos: [] };
        if (fields.action === 'preview') {
          payload = {
            status: 'success', repo: { full_name: 'philmorehost/PROJECTS' }, branch: 'main',
            next_version: '7.41', previous_version: '7.40', up_to_date: false,
            head: { sha: 'abcdef1', title: 'x', author: 'y', date: '2026-10-24 10:00:00' },
            commits: [], files: [], files_count: 0,
          };
        }

        if (String(url).indexOf('pull-worker.php') !== -1) {
          return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('{"ok":true,"accepted":true,"job":51}') });
        }

        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify(payload)) });
      };
    },
  });

  await new Promise(resolve => setTimeout(resolve, 300));

  const { window } = dom;
  const document = window.document;
  const cardText = () => (document.getElementById('pullJobCard') || {}).textContent || '';

  const fatal = errors.filter(e => /is not defined|Cannot read|null|TypeError|ReferenceError/i.test(e));
  ok('the page loads without a JavaScript error', fatal.length === 0, fatal.join('; '));

  const workerCalls = calls.filter(c => c.url.indexOf('pull-worker.php') !== -1);
  ok('a queued job makes the page start the worker itself', workerCalls.length === 1, String(workerCalls.length));
  ok('it asks the exact URL the server signed',
    workerCalls[0] && workerCalls[0].url === QUEUED_JOB.worker_url, workerCalls[0] && workerCalls[0].url);
  ok('the request is kept alive if the admin navigates away',
    workerCalls[0] && calls.find(c => c.url === workerCalls[0].url) !== undefined, '');

  ok('the card explains why the pull has not started',
    /cannot start a background worker|could not start/i.test(cardText()), cardText().slice(0, 300));
  ok('the card still offers Stop', !!document.getElementById('jobStopBtn'));

  await new Promise(resolve => setTimeout(resolve, 4200));

  ok('the worker is not started again on every poll', workerCalls.length === 1,
    workerCalls.map(c => c.url).join(' | '));
  ok('the poll keeps going until the job is running', statusPolls >= 2, String(statusPolls));
  ok('the card moves on to the real stage',
    /Packaging files|Packaging/i.test(cardText()), cardText().slice(0, 300));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FAILED', e); process.exit(1); });

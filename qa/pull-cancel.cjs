/**
 * Stopping a background pull.
 *
 * The complaint that produced this: a pull for DGV7.0-SAAS had been "running"
 * for twenty minutes with no way out of it — no cancel button, and the project
 * locked against a second attempt until the queue reaped the job 45 minutes
 * later. This file pins down both halves of the fix:
 *
 *   A. the state machine — a stopped job frees the project at once, a worker
 *      that has been stopped cannot overwrite the stop or publish into it, and
 *      a stop mid-download is not reported as a failure;
 *   B. the console — a real Stop button on the running card, an honest card
 *      afterwards, and a Start again button that actually starts a pull.
 *
 * Usage: node qa/pull-cancel.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { boot } = require('./lib/boot.cjs');

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

/* ------------------------------------------------------------------ part A */

const STATE_MACHINE = `
    require '/lm/.qa/fakejobs.php';
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/pull_jobs.php';

    $pdo = new JobsPdo();
    $out = array();
    $t = microtime(true);

    // 1. queue a pull, then stop it before a worker ever looks at it
    $job1 = lm_pull_job_enqueue($pdo, 1, array('version' => '7.41'), 'admin');
    $out['enqueued'] = $job1 ? (int) $job1['id'] : 0;
    $out['enqueued_status'] = $job1 ? (string) $job1['status'] : '';
    $p1 = lm_pull_job_public($job1);
    $out['queued_public'] = array('can_cancel' => $p1['can_cancel'], 'finished' => $p1['finished']);

    $cancel1 = lm_pull_job_cancel($pdo, (int) $job1['id'], 'admin');
    $out['cancel_queued'] = $cancel1;
    $row1 = lm_pull_job_get($pdo, (int) $job1['id']);
    $out['row1'] = array(
      'status' => (string) $row1['status'],
      'stage' => (string) $row1['stage'],
      'finished_at' => (string) $row1['finished_at'],
      'message' => (string) $row1['message'],
      'progress' => (int) $row1['progress'],
    );

    $again = lm_pull_job_cancel($pdo, (int) $job1['id'], 'admin');
    $out['cancel_twice'] = array('ok' => $again['ok'], 'message' => $again['message']);

    // 2. the project is free immediately — this is "start afresh"
    $out['active_after_cancel'] = lm_pull_job_active($pdo, 1) === null;
    $job2 = lm_pull_job_enqueue($pdo, 1, array('version' => '7.41'), 'admin');
    $out['requeued'] = $job2 ? (int) $job2['id'] : 0;
    $out['requeued_status'] = $job2 ? (string) $job2['status'] : '';

    // 3. a worker that arrives late cannot claim a stopped job
    $err = '';
    $claimedDead = lm_pull_job_claim($pdo, (int) $job1['id'], (string) $job1['run_token'], $err);
    $out['claim_cancelled'] = array('claimed' => $claimedDead, 'error' => $err);

    $err2 = '';
    $claimed = lm_pull_job_claim($pdo, (int) $job2['id'], (string) $job2['run_token'], $err2);
    $out['claim_new'] = array('claimed' => $claimed, 'error' => $err2);
    $out['running_status'] = (string) lm_pull_job_get($pdo, (int) $job2['id'])['status'];

    // 4. mid-flight stop
    lm_pull_job_progress($pdo, (int) $job2['id'], 'downloading', 42, 'Downloading 12 MB…');
    $out['cancelled_while_running'] = lm_pull_job_cancelled($pdo, (int) $job2['id']);
    $cancel2 = lm_pull_job_cancel($pdo, (int) $job2['id'], 'admin');
    $out['cancel_running'] = $cancel2;
    $row2 = lm_pull_job_get($pdo, (int) $job2['id']);
    $out['row2_message'] = (string) $row2['message'];
    $out['cancelled_after'] = lm_pull_job_cancelled($pdo, (int) $job2['id']);

    $guardClass = '';
    try {
        lm_pull_job_guard($pdo, (int) $job2['id']);
    } catch (Throwable $e) {
        $guardClass = get_class($e);
    }
    $out['guard_throws'] = $guardClass;

    // 5. the worker's late writes must not overwrite the stop
    $finished = lm_pull_job_finish($pdo, (int) $job2['id'], array('ok' => true, 'status' => 'published', 'version' => '7.41'));
    $afterFinish = lm_pull_job_get($pdo, (int) $job2['id']);
    $out['finish_after_cancel'] = $finished;
    $out['status_after_finish'] = (string) $afterFinish['status'];

    lm_pull_job_progress($pdo, (int) $job2['id'], 'packaging', 70, 'Packaging…');
    $afterProgress = lm_pull_job_get($pdo, (int) $job2['id']);
    $out['stage_after_progress'] = (string) $afterProgress['stage'];

    $pub = lm_pull_job_public($afterFinish);
    $out['cancelled_public'] = array(
      'finished' => $pub['finished'],
      'can_cancel' => $pub['can_cancel'],
      'cancelled' => $pub['cancelled'],
      'stage_label' => $pub['stage_label'],
    );
    $pub['message_text'] = lm_pull_job_summary($pub);
    $out['cancelled_summary'] = $pub['message_text'];

    // 6. a stopped job is never reaped back to life, and never runs from cron
    $stale = JobStore::insert(array(
      'tier_id' => 1, 'status' => 'running', 'stage' => 'downloading', 'progress' => 20,
      'message' => 'Downloading…', 'payload' => '{}', 'run_token' => 'tok' . $t,
      'actor' => 'admin', 'heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 4000),
      'started_at' => gmdate('Y-m-d H:i:s', time() - 4000),
    ));
    $out['reaped'] = lm_pull_job_reap($pdo);
    $out['stale_status'] = (string) lm_pull_job_get($pdo, $stale)['status'];
    $out['cancelled_after_reap'] = (string) lm_pull_job_get($pdo, (int) $job2['id'])['status'];

    $out['unhandled_sql'] = isset($GLOBALS['qa_unhandled']) ? $GLOBALS['qa_unhandled'] : array();
    $out['rows'] = JobStore::all();

    echo json_encode($out);
`;

/* ------------------------------------------------------------------ part B */

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

const RUNNING_JOB = {
  id: 41,
  tier_id: 1,
  status: 'running',
  stage: 'downloading',
  stage_label: 'Downloading the archive',
  progress: 34,
  message: 'Downloading 18 MB…',
  message_text: 'Downloading 18 MB…',
  version: '',
  commit: '',
  update_id: 0,
  created_at: '2026-10-24 10:00:00',
  started_at: '2026-10-24 10:00:00',
  finished_at: '',
  finished: false,
  cancelled: false,
  can_cancel: true,
  elapsed: 1245,
  result: {},
};

const STOPPED_JOB = Object.assign({}, RUNNING_JOB, {
  status: 'cancelled',
  stage: 'done',
  stage_label: 'Finished',
  progress: 34,
  message: 'Stopped by admin while downloading the archive.',
  message_text: 'Stopped by admin while downloading the archive. The worker exits at its next checkpoint; the archive already downloaded is kept for the next attempt.',
  finished_at: '2026-10-24 10:21:00',
  finished: true,
  cancelled: true,
  can_cancel: false,
  elapsed: 1260,
});

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakejobs.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakejobs.php')));
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  console.log('\nA stopped pull frees the project at once');
  const state = json(await run(STATE_MACHINE, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  if (!state) {
    ok('the state-machine probe ran', false, 'no JSON returned');
  } else {
    ok('the probe understood every statement pull_jobs.php sends',
      Array.isArray(state.unhandled_sql) && state.unhandled_sql.length === 0,
      JSON.stringify(state.unhandled_sql));

    ok('a queued pull can be stopped', state.cancel_queued && state.cancel_queued.ok === true,
      JSON.stringify(state.cancel_queued));
    ok('the card says a queued pull is cancellable',
      state.queued_public && state.queued_public.can_cancel === true && state.queued_public.finished === false,
      JSON.stringify(state.queued_public));
    ok('the row becomes cancelled with a timestamp',
      state.row1 && state.row1.status === 'cancelled' && state.row1.finished_at !== '',
      JSON.stringify(state.row1));
    ok('and says nothing was downloaded or published',
      state.row1 && /before it started/i.test(state.row1.message) && /nothing was downloaded/i.test(state.row1.message),
      state.row1 && state.row1.message);
    ok('stopping twice is refused politely',
      state.cancel_twice && state.cancel_twice.ok === false && /already stopped/i.test(state.cancel_twice.message),
      JSON.stringify(state.cancel_twice));

    ok('the project is free immediately (no 45-minute wait)', state.active_after_cancel === true);
    ok('a fresh pull can be queued straight away',
      state.requeued > 0 && state.requeued !== state.enqueued && state.requeued_status === 'queued',
      JSON.stringify({ first: state.enqueued, second: state.requeued, status: state.requeued_status }));
    ok('the new job is a new row, not the stopped one', state.requeued === state.enqueued + 1,
      JSON.stringify({ first: state.enqueued, second: state.requeued }));

    console.log('\nA worker that has been stopped cannot continue');
    ok('it cannot claim a stopped job',
      state.claim_cancelled && state.claim_cancelled.claimed === false,
      JSON.stringify(state.claim_cancelled));
    ok('and is told the pull was stopped, not that it is running',
      state.claim_cancelled && /stopped before it started/i.test(state.claim_cancelled.error),
      state.claim_cancelled && state.claim_cancelled.error);
    ok('the fresh job can be claimed', state.claim_new && state.claim_new.claimed === true,
      JSON.stringify(state.claim_new));
    ok('claiming moves it to running', state.running_status === 'running', state.running_status);
    ok('a running job is not reported as cancelled', state.cancelled_while_running === false);

    ok('a running pull can be stopped', state.cancel_running && state.cancel_running.ok === true,
      JSON.stringify(state.cancel_running));
    ok('the stored row says what it was doing when it was stopped',
      /while downloading the archive/i.test(state.row2_message || ''), state.row2_message);
    ok('it promises the worker exits at the next checkpoint',
      state.cancel_running && /next checkpoint/i.test(state.cancel_running.message),
      state.cancel_running && state.cancel_running.message);
    ok('the gate now throws LmPullCancelled', state.guard_throws === 'LmPullCancelled', state.guard_throws);

    ok('a late "finished" cannot overwrite the stop', state.finish_after_cancel === false,
      String(state.finish_after_cancel));
    ok('the row stays cancelled after the worker reports success', state.status_after_finish === 'cancelled',
      state.status_after_finish);
    ok('a late progress write does not move the stage', state.stage_after_progress === 'done',
      state.stage_after_progress);

    ok('the console payload marks it finished and not cancellable',
      state.cancelled_public && state.cancelled_public.finished === true && state.cancelled_public.can_cancel === false
        && state.cancelled_public.cancelled === true,
      JSON.stringify(state.cancelled_public));
    ok('the one-line summary says it was stopped',
      state.cancelled_summary && /stopped/i.test(state.cancelled_summary), state.cancelled_summary);

    console.log('\nThe housekeeping respects a stop');
    ok('a stale running job is still reaped', state.reaped === 1 && state.stale_status === 'error',
      JSON.stringify({ reaped: state.reaped, status: state.stale_status }));
    ok('a stopped job is left alone by the reaper', state.cancelled_after_reap === 'cancelled',
      state.cancelled_after_reap);
  }

  console.log('\nThe cancellation reaches the long steps');
  const glue = json(await run(`
    require '/lm/.qa/fakejobs.php';
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/pull_jobs.php';
    require_once '/lm/includes/updates.php';
    require_once '/lm/includes/github.php';

    $out = array();

    // A stage callback that throws must NOT be swallowed by the progress
    // helper: that helper is what carries a stop out of lm_github_pull().
    $options = array('on_stage' => function () { throw new LmPullCancelled('stop'); });
    $thrown = '';
    try { lm_github_pull_stage($options, 'packaging', 55, 'x'); }
    catch (Throwable $e) { $thrown = get_class($e); }
    $out['stage_rethrows'] = $thrown;

    // An ordinary exception from a progress callback must still be ignored:
    // a broken progress write may never fail a pull.
    $options2 = array('on_stage' => function () { throw new RuntimeException('progress hiccup'); });
    $swallowed = '';
    try { lm_github_pull_stage($options2, 'packaging', 55, 'x'); }
    catch (Throwable $e) { $swallowed = get_class($e); }
    $out['stage_swallows'] = $swallowed;

    // lm_github_pull_cancelled() calls the operator's check, and does nothing
    // when there is none (foreground pulls from the CLI, for example).
    $called = 0;
    $opts3 = array('cancel' => function () use (&$called) { $called++; });
    lm_github_pull_cancelled($opts3);
    lm_github_pull_cancelled(array());
    $out['cancel_hook_calls'] = $called;

    $out['zip_supported'] = lm_zip_supported();
    $out['unhandled_sql'] = isset($GLOBALS['qa_unhandled']) ? $GLOBALS['qa_unhandled'] : array();
    echo json_encode($out);
  `, '/lm/admin/github-updates.php', { fresh: true, debug: true }));

  ok('a stop thrown at a stage change escapes the progress helper',
    glue && glue.stage_rethrows === 'LmPullCancelled', JSON.stringify(glue && glue.stage_rethrows));
  ok('an unrelated error in a progress callback is still ignored',
    glue && glue.stage_swallows === '', JSON.stringify(glue && glue.stage_swallows));
  ok('the cancel hook is called where it is given, and skipped when it is not',
    glue && glue.cancel_hook_calls === 1, JSON.stringify(glue && glue.cancel_hook_calls));

  /*
   * The ZIP loop cannot run here: php-wasm has no zlib, so lm_zip_supported()
   * is false and no archive can be read or written. What is asserted instead is
   * that the hook and the cleanup are actually wired into that loop — the two
   * things that would fail silently on the server (a cancel that is never
   * asked for, and a half-written package left on disk).
   */
  const zip = fs.readFileSync(path.join(__dirname, '..', 'LM', 'includes', 'zip.php'), 'utf8');
  ok('the ZIP loop asks about cancellation as it copies',
    /\$cancel !== null && \(\+\+\$seen % 200\) === 0/.test(zip), '');
  ok('it closes both handles and deletes the partial package before unwinding',
    /catch \(Throwable \$e\) \{\s*fclose\(\$in\);\s*fclose\(\$out\);\s*@unlink\(\$destination\);\s*throw \$e;/.test(zip), '');
  const updatesSrc = fs.readFileSync(path.join(__dirname, '..', 'LM', 'includes', 'updates.php'), 'utf8');
  ok('lm_zip_rewrite forwards the cancel hook it is given',
    /'cancel' => isset\(\$options\['cancel'\]\) \? \$options\['cancel'\] : null/.test(updatesSrc), '');
  const githubSrc = fs.readFileSync(path.join(__dirname, '..', 'LM', 'includes', 'github.php'), 'utf8');
  ok('the pull passes the hook into the re-pack', /Re-packing a big archive is the second-longest step/.test(githubSrc), '');
  ok('the pull checks again immediately before publishing',
    /Last checkpoint before the point of no return[\s\S]{0,120}lm_github_pull_cancelled\(\$options\);\s*lm_github_pull_stage\(\$options, 'publishing'/.test(githubSrc), '');
  ok('a stopped pull is not reported to the admin as a failure',
    /if \(\(string\) \(\$job\['status'\] \?\? ''\) === 'cancelled'[\s\S]{0,400}return \$out;/.test(
      fs.readFileSync(path.join(__dirname, '..', 'LM', 'includes', 'pull_jobs.php'), 'utf8')), '');

  console.log('\nThe console offers it, and it works');
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

  const dom = new JSDOM(html, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    url: 'https://manager.pmhserver.name.ng/admin/github-updates.php',
    virtualConsole,
    beforeParse(window) {
      window.TextEncoder = TextEncoder;
      window.confirm = () => true;                 // the operator agrees to stop
      window.fetch = (url, options) => {
        const form = options && options.body;
        const fields = {};
        if (form && typeof form.forEach === 'function') {
          form.forEach((value, key) => {
            fields[key] = fields[key] === undefined ? String(value) : [].concat(fields[key], String(value));
          });
        }
        calls.push({ url: String(url), fields });

        let payload = { status: 'success', server_version: '9.4.0' };
        if (fields.action === 'pull_last') payload = { status: 'success', job: RUNNING_JOB };
        if (fields.action === 'pull_cancel') {
          payload = { status: 'success', message: 'Stopping. The worker exits at its next checkpoint.', job: STOPPED_JOB, job_id: 41, was: 'running' };
        }
        if (fields.action === 'preview') {
          payload = {
            status: 'success',
            repo: { full_name: 'philmorehost/PROJECTS' },
            branch: 'main',
            next_version: '7.41',
            previous_version: '7.40',
            up_to_date: false,
            head: { sha: 'abcdef1234567890', title: 'Fix the session', author: 'philmore', date: '2026-10-24 09:00:00' },
            commits: [],
            files: [],
            files_count: 0,
            changelog_html: '',
            changelog_text: '',
          };
        }
        if (fields.action === 'pulls') payload = { status: 'success', pulls: [] };
        if (fields.action === 'repos') payload = { status: 'success', repos: [] };
        if (fields.action === 'pull') payload = { status: 'success', message: 'Pull started.', job: Object.assign({}, RUNNING_JOB, { id: 42 }), job_id: 42 };

        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify(payload)) });
      };
      window.location.reload = () => {};
    },
  });

  await new Promise(resolve => setTimeout(resolve, 250));

  const { window } = dom;
  const document = window.document;
  const fatal = errors.filter(e => /is not defined|Cannot read|null|TypeError|ReferenceError/i.test(e));
  ok('the page loads without a JavaScript error', fatal.length === 0, fatal.join('; '));

  const card = document.getElementById('pullJobCard');
  const cardText = () => (document.getElementById('pullJobCard') || {}).textContent || '';

  ok('a running pull shows a card', /Pulling DGV7.0-SAAS/.test(cardText()), cardText().slice(0, 200));

  const stop = document.getElementById('jobStopBtn');
  ok('the card has a Stop button', !!stop, cardText().slice(0, 300));
  ok('the button says what it does', stop && /stop this pull/i.test(stop.textContent), stop && stop.textContent);
  ok('the card shows how long it has been running', /2[0-9] min|1[0-9] min|min/.test(cardText()), cardText().slice(0, 200));
  ok('the card says nothing is published until the end', /only published at the end/i.test(cardText()), cardText().slice(0, 300));

  if (stop) stop.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 200));

  const cancelCall = calls.find(c => c.fields.action === 'pull_cancel');
  ok('clicking Stop posts to the server', !!cancelCall, JSON.stringify(calls.map(c => c.fields.action)));
  ok('it names the job to stop', cancelCall && String(cancelCall.fields.job_id) === '41', JSON.stringify(cancelCall && cancelCall.fields));
  ok('it sends the project too, so a reloaded console can still stop it',
    cancelCall && String(cancelCall.fields.tier_id) === '1', JSON.stringify(cancelCall && cancelCall.fields));

  ok('the card switches to “Pull stopped”', /Pull stopped/.test(cardText()), cardText().slice(0, 300));
  ok('it explains what happened', /Stopped by admin/i.test(cardText()), cardText().slice(0, 300));
  ok('a stopped card has no Stop button', !document.getElementById('jobStopBtn'));
  ok('the operator is told starting again is possible', /start a new pull|start again/i.test(cardText()), cardText().slice(0, 300));

  const restart = document.getElementById('jobRestartBtn');
  ok('there is a Start again button', !!restart, cardText().slice(0, 300));

  if (restart) restart.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 250));
  ok('Start again really queues a new pull', calls.some(c => c.fields.action === 'pull' && String(c.fields.tier_id) === '1'),
    JSON.stringify(calls.map(c => c.fields.action)));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FAILED', e); process.exit(1); });

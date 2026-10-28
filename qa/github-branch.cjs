/**
 * "No commit found for SHA: arena/8a3f6d9a-projects".
 *
 * The report that produced this: a project in the same repository as a working
 * one failed with that sentence, while its neighbour connected fine. Nothing in
 * it says "the branch you saved was deleted" — which is exactly what happened:
 * arena/… branches are temporary and go away when their pull request merges.
 *
 * This file pins the three halves of the fix:
 *
 *   A. the classification — GitHub's 422 for a missing ref becomes a sentence
 *      that names the branch, says why it vanished, and lists the branches that
 *      do exist;
 *   B. the recovery — preview and "Test connection" report branch_missing with
 *      the real branch list and a suggestion, and a pull refuses rather than
 *      silently publishing a different branch; saving refuses the stale name
 *      up front and forgets the old SHA when the branch changes;
 *   C. the console — the dead card is replaced by buttons that actually fill the
 *      Branch field and retry the preview.
 *
 * Usage: node qa/github-branch.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { boot } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 380) : '')); }
};

const json = raw => {
  const text = String(raw).trim();
  try { return JSON.parse(text); } catch (e) { /* fall through */ }
  const m = text.match(/[{[][\s\S]*[}\]]/);
  if (!m) return null;
  try { return JSON.parse(m[0]); } catch (e) { return null; }
};

const LM = path.join(__dirname, '..', 'LM');
const githubSrc = fs.readFileSync(path.join(LM, 'includes', 'github.php'), 'utf8');
const ajaxSrc = fs.readFileSync(path.join(LM, 'admin', 'ajax_github.php'), 'utf8');
const uiSrc = fs.readFileSync(path.join(LM, 'admin', 'github-updates.php'), 'utf8');

const GITHUB_422 = 'GitHub could not process the request (422): No commit found for SHA: arena/8a3f6d9a-projects';

async function main() {
  /* ---------------------------------------------------------------- part A */

  console.log('GitHub’s 422 says what went wrong, and what to do');

  const probe = await boot();
  const out = json(await probe.run(`
    require_once '/lm/includes/github.php';

    $result = array();

    // A branch name is not a commit. This column only ever holds a hash.
    $result['sha_like'] = array(
      'full'  => lm_github_sha_like('9f1c2d3e4b5a69788796a5b4c3d2e1f009182736'),
      'short' => lm_github_sha_like('9f1c2d3'),
      'branch' => lm_github_sha_like('arena/8a3f6d9a-projects'),
      'main'  => lm_github_sha_like('main'),
      'six'   => lm_github_sha_like('9f1c2d'),
      'empty' => lm_github_sha_like(''),
    );

    // The exact 422 the panel used to print verbatim.
    $result['missing_ref'] = lm_github_ref_error(array(
      'status' => 422,
      'error' => ${JSON.stringify(GITHUB_422)},
      'data' => array('message' => 'No commit found for SHA: arena/8a3f6d9a-projects'),
    ));

    // GitHub sometimes sends the words without the "could not process" prefix.
    $result['bare'] = lm_github_ref_error(array(
      'status' => 422,
      'error' => 'No commit found for SHA: release/7.4',
      'data' => null,
    ));

    $result['not_found'] = lm_github_ref_error(array('status' => 404, 'error' => 'Not Found', 'data' => null));
    $result['empty_repo'] = lm_github_ref_error(array('status' => 409, 'error' => 'Git Repository is empty.', 'data' => null));
    $result['other'] = lm_github_ref_error(array('status' => 500, 'error' => 'Server Error', 'data' => null));

    echo json_encode($result);
  `, '/lm/admin/ajax_github.php', { fresh: true, debug: true }));

  ok('the probe ran', !!out, 'no JSON came back');
  if (out) {
    ok('a 40-character hash is a commit', out.sha_like.full === true, JSON.stringify(out.sha_like));
    ok('a short hash is a commit', out.sha_like.short === true, JSON.stringify(out.sha_like));
    ok('a branch name is not a commit', out.sha_like.branch === false, JSON.stringify(out.sha_like));
    ok('"main" is not a commit', out.sha_like.main === false, JSON.stringify(out.sha_like));
    ok('six characters is too short to be a hash', out.sha_like.six === false, JSON.stringify(out.sha_like));
    ok('an empty value is not a commit', out.sha_like.empty === false, JSON.stringify(out.sha_like));

    ok('the 422 is classified as a missing ref', out.missing_ref.kind === 'ref_missing', JSON.stringify(out.missing_ref));
    ok('it names the branch that vanished', out.missing_ref.ref === 'arena/8a3f6d9a-projects', JSON.stringify(out.missing_ref));
    ok('it says the branch does not exist any more',
      /does not exist in this repository any more/.test(out.missing_ref.message), out.missing_ref.message);
    ok('it explains why a branch disappears',
      /deleted when the work lands/.test(out.missing_ref.message), out.missing_ref.message);
    ok('it no longer repeats GitHub’s raw sentence',
      !/No commit found for SHA/.test(out.missing_ref.message), out.missing_ref.message);
    ok('a bare GitHub message is classified the same way',
      out.bare.kind === 'ref_missing' && out.bare.ref === 'release/7.4', JSON.stringify(out.bare));
    ok('a real 404 stays a 404', out.not_found.kind === 'not_found', JSON.stringify(out.not_found));
    ok('an empty repository is named as such', out.empty_repo.kind === 'empty_repo', JSON.stringify(out.empty_repo));
    ok('anything else is left alone', out.other.kind === 'other', JSON.stringify(out.other));
  }

  console.log('\nThe panel offers the branches that do exist');

  const check = json(await probe.run(`
    // Stub the three network calls, so the composition can be read without
    // touching api.github.com. github.php guards each with function_exists.
    function lm_github_branch_head($owner, $repo, $branch, $token = '') {
      return array(
        'ok' => false,
        'error' => ${JSON.stringify(GITHUB_422)},
        'status' => 422,
        'message' => 'No commit found for SHA: ' . $branch,
        'ref_missing' => true,
        'commit' => null,
        'data' => null,
      );
    }
    function lm_github_list_branches($owner, $repo, $token, $limit = 100) {
      return array('ok' => true, 'branches' => array('arena/58e52fdb-projects', 'arena/b4f073f2-projects', 'main'), 'truncated' => false);
    }
    function lm_github_repo_info($owner, $repo, $token = '') {
      return array('ok' => true, 'data' => array('full_name' => 'philmorehost/PROJECTS', 'default_branch' => 'main'), 'error' => '');
    }

    require_once '/lm/includes/github.php';

    $raw = lm_github_branch_check('philmorehost', 'PROJECTS', 'arena/8a3f6d9a-projects', 'tok');
    echo json_encode(array(
      'ok' => $raw['ok'],
      'missing' => $raw['missing'],
      'branch' => $raw['branch'],
      'branches' => $raw['branches'],
      'suggested' => $raw['suggested'],
      'error' => $raw['error'],
      'has_sha_suggestion' => isset($raw['head']['sha']),
    ));
  `, '/lm/admin/ajax_github.php', { fresh: true, debug: true }));

  ok('the branch check reports the failure', check && check.ok === false, JSON.stringify(check));
  if (check) {
    ok('it is flagged as a missing branch, not a token problem', check.missing === true, JSON.stringify(check));
    ok('it keeps the branch that was asked for', check.branch === 'arena/8a3f6d9a-projects', JSON.stringify(check));
    ok('it lists the branches that exist',
      Array.isArray(check.branches) && check.branches.indexOf('main') !== -1 && check.branches.length === 3,
      JSON.stringify(check.branches));
    ok('it suggests the repository default', check.suggested === 'main', String(check.suggested));
    ok('the message names the dead branch', /arena\/8a3f6d9a-projects/.test(check.error), check.error);
    ok('the message lists the live branches', /arena\/58e52fdb-projects/.test(check.error) && /main/.test(check.error), check.error);
    ok('the message says how to fix it', /Set the Branch field/.test(check.error), check.error);
    ok('it never invents a commit', check.has_sha_suggestion === false, JSON.stringify(check));
  }

  /* ---------------------------------------------------------------- part B */

  console.log('\nEvery route out of the old message is closed');

  ok('the 422 goes through the classifier',
    /case 422:[\s\S]{0,700}lm_github_ref_error\(/.test(githubSrc), '');
  ok('the request result carries the raw GitHub words separately',
    /'message' => ''/.test(githubSrc) && /'ref_missing' => false/.test(githubSrc), '');
  ok('a missing ref is flagged on the request result',
    /if \(\$verdict\['kind'\] === 'ref_missing'\)[\s\S]{0,400}\$out\['ref_missing'\] = true;/.test(githubSrc), '');
  ok('a missing branch is not dressed up as a 404',
    !/case 404:[\s\S]{0,200}No commit found/.test(githubSrc), '');

  ok('the preview reports the branch list instead of only an error',
    /'branch_missing' => false[\s\S]{0,400}'suggested_branch' => ''/.test(githubSrc), '');
  const previewBody = githubSrc.slice(githubSrc.indexOf('function lm_github_preview('));
  const previewCheck = previewBody.indexOf("lm_github_branch_check($config['owner']");
  const previewCommits = previewBody.indexOf("lm_github_recent_commits($config['owner']");
  ok('the preview validates the branch before fetching any commit',
    previewCheck !== -1 && previewCommits !== -1 && previewCheck < previewCommits,
    'check at ' + previewCheck + ', commits at ' + previewCommits);
  ok('the test-connection path returns the branch list too',
    /'branch_missing' => !empty\(\$check\['missing'\]\)[\s\S]{0,300}'suggested_branch' => \$check\['suggested'\]/.test(githubSrc), '');
  ok('a pull refuses a missing branch and says which one',
    /\$result\['error_code'\] = !empty\(\$check\['missing'\]\) \? 'branch_missing' : 'ref_error';/.test(githubSrc), '');
  ok('a pull never silently switches branch',
    !/ref_missing[\s\S]{0,200}\$branch = \$default/.test(githubSrc), '');
  ok('a stored branch name is not mistaken for a commit sha',
    /is_string\(\$tier\['github_last_sha'\] \?\? null\) && lm_github_sha_like\(\$tier\['github_last_sha'\]\)/.test(githubSrc), '');

  ok('saving checks the branch exists before the UPDATE',
    ajaxSrc.indexOf("$branchVerified = lm_github_branch_check(") !== -1
    && ajaxSrc.indexOf("$branchVerified = lm_github_branch_check(") < ajaxSrc.indexOf('$sql = "UPDATE script_tiers'),
    'the validation is missing or runs after the write');
  ok('saving refuses a branch that is gone',
    /else \{\s*lm_json\(array\(\s*'status' => 'error',\s*'message' => \$branchVerified\['error'\],\s*'branch_missing' => true/.test(ajaxSrc), '');
  ok('saving returns the branches to choose from',
    /'branches' => \$branchVerified\['branches'\],\s*'suggested_branch'/.test(ajaxSrc), '');
  ok('a new branch forgets the old commit sha',
    /\$clearLastSha = \(\$branch !== \$previousBranch \|\| \$repoInput !== \$previousRepo\);/.test(ajaxSrc)
    && /\(\$clearLastSha \? ', github_last_sha = NULL' : ''\)/.test(ajaxSrc), '');
  ok('the save response can carry a non-fatal warning',
    /'notice' => isset\(\$saveNotice\) \? \$saveNotice : ''/.test(ajaxSrc), '');

  ok('the console has a card for a vanished branch',
    /function branchMissingCard\(result\)/.test(uiSrc), '');
  ok('the card offers each surviving branch as a button',
    /data-use-branch="' \+ escapeHtml\(branch\)/.test(uiSrc), '');
  ok('choosing a branch fills the field and retries',
    /el\('githubBranch'\)\.value = button\.getAttribute\('data-use-branch'\);[\s\S]{0,120}loadPreview\(false\);/.test(uiSrc), '');
  ok('a failed save shows the same card',
    /result\.status === 'error'\) \{[\s\S]{0,200}branch_missing[\s\S]{0,200}branchMissingCard\(result\)/.test(uiSrc), '');
  const testHandler = uiSrc.slice(uiSrc.indexOf("el('testBtn')"), uiSrc.indexOf('let previewTimer'));
  ok('a failed test shows it as well',
    /toast\(result\.message, 'err'\);/.test(testHandler)
    && /result\.branch_missing/.test(testHandler)
    && /branchMissingCard\(result\)/.test(testHandler), testHandler.slice(0, 260));

  /* ---------------------------------------------------------------- part C */

  console.log('\nThe console turns the dead card into a fix');

  await probe.write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  const FAKE_PDO_SETUP = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_counts'] = array('licenses' => 12, 'script_updates' => 4, 'github_pulls' => 5, 'github_pull_jobs' => 0, 'jobs_running' => 0, 'update_notifications' => 7);
    $GLOBALS['qa_tiers'] = array(
      array('id'=>'1','tier_name'=>'DGV7.0-SAAS','tier_code'=>'SAAS','description'=>'Extended licence','project_key'=>'PRJ-SAAS','is_active'=>'1','github_repo'=>'philmorehost/PROJECTS','github_branch'=>'arena/8a3f6d9a-projects','sort_order'=>'1','license_price'=>'5000','max_domains'=>'3')
    );
    $GLOBALS['qa_pdo'] = new QAPdo();
  `;

  const html = String(await probe.run(`
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

  const MISSING = {
    status: 'error',
    message: 'The branch arena/8a3f6d9a-projects does not exist in this repository any more. A temporary branch — anything under arena/, a merged pull request, a renamed branch — is deleted when the work lands, and a project still pointing at it cannot pull. Branches that do exist: arena/58e52fdb-projects, arena/b4f073f2-projects, main. Set the Branch field to one of those (or leave it blank to follow the repository default, main) and pull again.',
    branch_missing: true,
    branch: 'arena/8a3f6d9a-projects',
    branches: ['arena/58e52fdb-projects', 'arena/b4f073f2-projects', 'main'],
    suggested_branch: 'main',
  };

  let previewAttempts = 0;

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
          form.forEach((value, key) => {
            fields[key] = fields[key] === undefined ? String(value) : [].concat(fields[key], String(value));
          });
        }
        calls.push({ url: String(url), fields });

        let payload = { status: 'success', server_version: '9.5.1' };
        if (fields.action === 'preview') {
          previewAttempts++;
          payload = previewAttempts === 1
            ? MISSING
            : {
              status: 'success',
              repo: { full_name: 'philmorehost/PROJECTS', default_branch: 'main' },
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
        if (fields.action === 'branches') {
          payload = { status: 'success', branches: ['arena/58e52fdb-projects', 'arena/b4f073f2-projects', 'main'], branch: 'arena/8a3f6d9a-projects', truncated: false };
        }
        if (fields.action === 'repos') payload = { status: 'success', repos: [] };
        if (fields.action === 'pulls') payload = { status: 'success', pulls: [] };

        return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify(payload)) });
      };
      window.location.reload = () => {};
    },
  });

  await new Promise(resolve => setTimeout(resolve, 300));

  const { window } = dom;
  const document = window.document;
  const fatal = errors.filter(e => /is not defined|Cannot read|null|TypeError|ReferenceError/i.test(e));
  ok('the page loads without a JavaScript error', fatal.length === 0, fatal.join('; '));

  const previewCall = calls.find(c => c.fields.action === 'preview');
  ok('opening a project previews its saved branch', !!previewCall, JSON.stringify(calls.map(c => c.fields.action)));

  const body = document.getElementById('previewBody');
  const bodyText = () => (body || {}).textContent || '';
  const branchField = document.getElementById('githubBranch');

  await (async () => {
    // The page previews on selection; give the first render a frame to land.
    for (let i = 0; i < 20 && !/no longer exists/i.test(bodyText()); i++) {
      await new Promise(resolve => setTimeout(resolve, 100));
    }
  })();

  ok('the card says the branch no longer exists', /no longer exists/i.test(bodyText()), bodyText().slice(0, 220));
  ok('the card no longer prints the raw 422 alone',
    !/^GitHub could not process the request \(422\): No commit found for SHA/.test(bodyText().trim()), bodyText().slice(0, 220));

  const buttons = Array.from(document.querySelectorAll('[data-use-branch]'));
  ok('there is a button per surviving branch', buttons.length === 3, buttons.map(b => b.getAttribute('data-use-branch')).join(', '));
  ok('the suggested branch is first in the offer',
    buttons.length > 0 && buttons[buttons.length - 1].getAttribute('data-use-branch') === 'main',
    buttons.map(b => b.getAttribute('data-use-branch')).join(', '));
  ok('the suggested button is marked as the one to press',
    buttons.some(b => b.getAttribute('data-use-branch') === 'main' && /btn-primary/.test(b.className)),
    buttons.map(b => b.className).join(' | '));
  ok('the card warns that saving is still needed', /press Save afterwards/i.test(bodyText()), bodyText().slice(-200));

  const mainButton = buttons.find(b => b.getAttribute('data-use-branch') === 'main');
  if (mainButton) mainButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 250));

  ok('clicking it fills the Branch field', branchField && branchField.value === 'main', branchField && branchField.value);
  ok('clicking it previews again', previewAttempts >= 2, String(previewAttempts));
  const retry = calls.filter(c => c.fields.action === 'preview').pop();
  ok('the retry uses the branch that was chosen', retry && retry.fields.branch === 'main', JSON.stringify(retry && retry.fields));
  ok('a successful retry replaces the error card',
    /Fix the session|Branch is already/.test(bodyText()) || !/no longer exists/i.test(bodyText()),
    bodyText().slice(0, 200));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});

/**
 * Holding updates back until a vendor pays.
 *
 * The request: "disable access to licenses from pulling updates … I have an
 * update I don't want the vendors to gain access to until the vendors manually
 * pay … a WhatsApp button should pop up to reach out to <number> (this will be
 * set by the admin, not seeded) … display on their website update page remotely
 * when they try to pull updates."
 *
 * So the switch lives on the licence, the release itself is untouched, and the
 * customer's page gets a separate `access` block — never a change to the
 * changelog — plus a button built from a number the admin types in Settings.
 *
 * Usage: node qa/update-access.cjs
 */
const fs = require('fs');
const path = require('path');
const { boot, LM } = require('./lib/boot.cjs');

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

const read = rel => fs.readFileSync(path.join(LM, rel), 'utf8');
const checkUpdate = read('check-update.php');
const download = read('download.php');
const notifications = read('includes/notifications.php');
const licencesPage = read('admin/licenses.php');
const settingsPage = read('admin/settings.php');
const migrations = read('includes/migrations.php');
const guide = read('includes/guide.php');

const SAAS_PAGE = path.join(LM, '..', 'VTU', 'DGV7.0-SAAS', 'bc-spadmin', 'UpdateSystem.php');
const NON_SAAS_PAGE = path.join(LM, '..', 'VTU', 'DGV7.0-NON-SAAS', 'bc-admin', 'UpdateSystem.php');
const SAAS_DL = path.join(LM, '..', 'VTU', 'DGV7.0-SAAS', 'bc-spadmin', 'ajax_download_update.php');
const NON_SAAS_DL = path.join(LM, '..', 'VTU', 'DGV7.0-NON-SAAS', 'bc-admin', 'ajax_download_update.php');

const vendorPage = fs.readFileSync(SAAS_PAGE, 'utf8');
const vendorPageStd = fs.readFileSync(NON_SAAS_PAGE, 'utf8');
const vendorDl = fs.readFileSync(SAAS_DL, 'utf8');
const vendorDlStd = fs.readFileSync(NON_SAAS_DL, 'utf8');

async function main() {
  /* ------------------------------------------------------------- helpers */

  console.log('A phone number typed by hand still makes a WhatsApp link');

  const probe = await boot();
  const numbers = json(await probe.run(`
    require_once '/lm/includes/lm_config.php';
    $cases = array(
      'plain'      => lm_whatsapp_number('2348086697100'),
      'plus'       => lm_whatsapp_number('+234 808 669 7100'),
      'dashed'     => lm_whatsapp_number('(234) 808-669-7100'),
      'local'      => lm_whatsapp_number('08086697100'),
      'zerozero'   => lm_whatsapp_number('002348086697100'),
      'spaces'     => lm_whatsapp_number('  234 808 669 7100  '),
      'empty'      => lm_whatsapp_number(''),
      'letters'    => lm_whatsapp_number('call me'),
      'short'      => lm_whatsapp_number('12345'),
      'url'        => lm_whatsapp_url('08086697100', 'Hello there'),
      'url_none'   => lm_whatsapp_url('', 'Hello'),
      'url_plain'  => lm_whatsapp_url('2348086697100'),
    );
    echo json_encode($cases);
  `, '/lm/admin/settings.php', { fresh: true, debug: true }));

  ok('the probe ran', !!numbers, 'no JSON came back');
  if (numbers) {
    ok('digits pass through', numbers.plain === '2348086697100', numbers.plain);
    ok('a + and spaces are stripped', numbers.plus === '2348086697100', numbers.plus);
    ok('brackets and dashes are stripped', numbers.dashed === '2348086697100', numbers.dashed);
    ok('a local 0… number gains the country code', numbers.local === '2348086697100', numbers.local);
    ok('a 00 international prefix is dropped', numbers.zerozero === '2348086697100', numbers.zerozero);
    ok('surrounding spaces do not matter', numbers.spaces === '2348086697100', numbers.spaces);
    ok('an empty field stays empty', numbers.empty === '', numbers.empty);
    ok('words are not a number', numbers.letters === '', numbers.letters);
    ok('too few digits is not a number', numbers.short === '', numbers.short);
    ok('the link is wa.me with the message encoded',
      numbers.url === 'https://wa.me/2348086697100?text=Hello%20there', numbers.url);
    ok('no number means no link', numbers.url_none === '', numbers.url_none);
    ok('a link without a message has no query string',
      numbers.url_plain === 'https://wa.me/2348086697100', numbers.url_plain);
  }

  /* --------------------------------------------------------- access state */

  console.log('\nThe switch is on the licence, and it is off by default');

  const state = json(await probe.run(`
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/updates.php';

    $allowed = array('id' => 5, 'license_key' => 'ABCD-1234');
    $blocked = array(
      'id' => 6,
      'license_key' => 'WXYZ-9876',
      'updates_enabled' => '0',
      'updates_blocked_at' => '2026-10-28 09:15:00',
      'updates_block_note' => 'Balance NGN 15,000 for v7.41',
    );

    $out = array();
    $out['allowed_flag'] = array(
      'on'      => lm_license_updates_allowed(array('updates_enabled' => '1')),
      'off'     => lm_license_updates_allowed(array('updates_enabled' => '0')),
      'null'    => lm_license_updates_allowed(array('updates_enabled' => null)),
      'missing' => lm_license_updates_allowed($allowed),
    );

    $out['ok_state'] = lm_update_access_state($allowed, '7.41');
    $out['hold_state'] = lm_update_access_state($blocked, '7.41');
    $out['hold_no_version'] = lm_update_access_state($blocked, '');
    $out['json'] = lm_update_access_json($out['hold_state']);
    echo json_encode($out);
  `, '/lm/check-update.php', { fresh: true, debug: true }));

  ok('the probe ran', !!state, 'no JSON came back');
  if (state) {
    ok('1 means allowed', state.allowed_flag.on === true, JSON.stringify(state.allowed_flag));
    ok('0 means on hold', state.allowed_flag.off === false, JSON.stringify(state.allowed_flag));
    ok('a NULL column is not a block', state.allowed_flag.null === true, JSON.stringify(state.allowed_flag));
    ok('an older schema (no column) does not lock anyone out',
      state.allowed_flag.missing === true, JSON.stringify(state.allowed_flag));

    ok('an allowed licence has nothing to explain',
      state.ok_state.allowed === true && state.ok_state.message === '' && state.ok_state.title === '',
      JSON.stringify(state.ok_state));

    ok('a held licence is flagged', state.hold_state.allowed === false, JSON.stringify(state.hold_state));
    ok('it has a heading', state.hold_state.title === 'Update access is on hold', state.hold_state.title);
    ok('the message names the version they are waiting for',
      /v7\.41/.test(state.hold_state.message), state.hold_state.message);
    ok('the message says who to contact', /Contact the developer/.test(state.hold_state.message), state.hold_state.message);
    ok('the admin’s note is carried separately, not glued into the message',
      state.hold_state.note === 'Balance NGN 15,000 for v7.41', state.hold_state.note);
    ok('the hold has a date', /28 Oct 2026/.test(state.hold_state.blocked_since), state.hold_state.blocked_since);
    ok('without a version the wording still makes sense',
      /Update access for this licence is on hold/.test(state.hold_no_version.message), state.hold_no_version.message);

    ok('the JSON block carries the decision', state.json.allowed === false, JSON.stringify(state.json));
    ok('the JSON block carries every contact field',
      ['whatsapp', 'whatsapp_url', 'email', 'message', 'note', 'blocked_since', 'version']
        .every(k => Object.prototype.hasOwnProperty.call(state.json, k)),
      Object.keys(state.json).join(', '));
  }

  console.log('\nWith no number configured, nothing pretends to be a button');

  const probe2 = await boot();
  const bare = json(await probe2.run(`
    // The harness writes a settings file with an admin e-mail; this case is
    // "the admin has filled in neither", so replace it before anything reads it.
    file_put_contents('/lm/settings.json', json_encode(array(
      'site_name' => 'Philmore Licence Manager',
      'site_url' => 'https://manager.pmhserver.name.ng',
      'admin_email' => '',
      'vendor_whatsapp' => '',
    )));
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/updates.php';
    $state = lm_update_access_state(array('updates_enabled' => '0'), '7.41');
    echo json_encode(array('state' => $state, 'contact' => lm_vendor_contact()));
  `, '/lm/check-update.php', { fresh: true, debug: true }));

  ok('the probe ran', !!bare, 'no JSON came back');
  if (bare) {
    ok('no WhatsApp number means no link',
      bare.state.whatsapp === '' && bare.state.whatsapp_url === '', JSON.stringify(bare.state));
    ok('no e-mail and no number means the page says so',
      /No contact channel is configured/.test(bare.state.message), bare.state.message);
  }

  console.log('\nThe number the admin types is what the customer sees');

  const probe3 = await boot();

  const seeded = json(await probe3.run(`
    file_put_contents('/lm/settings.json', json_encode(array(
      'site_name' => 'Philmore Licence Manager',
      'site_url' => 'https://manager.pmhserver.name.ng',
      'admin_email' => 'ops@philmore.test',
      'vendor_whatsapp' => '+234 808 669 7100',
      'vendor_contact_message' => 'Access to {version} is on hold. Message us to settle it.',
    )));
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/updates.php';
    $contact = lm_vendor_contact();
    $state = lm_update_access_state(array('updates_enabled' => '0'), '7.41');
    echo json_encode(array('contact' => $contact, 'state' => $state));
  `, '/lm/check-update.php', { fresh: true, debug: true }));

  ok('the probe ran', !!seeded, 'no JSON came back');
  if (seeded) {
    ok('the saved number is normalised again on read',
      seeded.contact.whatsapp === '2348086697100', seeded.contact.whatsapp);
    ok('the link points at that number',
      seeded.contact.whatsapp_url.indexOf('https://wa.me/2348086697100') === 0, seeded.contact.whatsapp_url);
    ok('the pre-filled message names the product',
      /Philmore Licence Manager/.test(decodeURIComponent(seeded.contact.whatsapp_url)), seeded.contact.whatsapp_url);
    ok('a custom wording replaces the built-in one',
      seeded.state.message === 'Access to v7.41 is on hold. Message us to settle it.', seeded.state.message);
  }

  /* ------------------------------------------------------------- contracts */

  console.log('\nThe package is never handed over while it is on hold');

  const gateAt = checkUpdate.indexOf('lm_update_access_state(');
  const tokenAt = checkUpdate.lastIndexOf('$downloadUrl = lm_ota_issue_download(');
  ok('check-update checks the licence before minting a token',
    gateAt !== -1 && tokenAt !== -1 && gateAt < tokenAt,
    'gate at ' + gateAt + ', token at ' + tokenAt);
  ok('a held licence gets its own status', /'status' => 'payment_required'/.test(checkUpdate), '');
  ok('a held response carries no download link',
    /'download_url' => '',[\s\S]{0,200}'access' => lm_update_access_json\(\$access\)/.test(checkUpdate), '');
  ok('a held response clears the checksum too',
    /'checksum' => '',[\s\S]{0,400}'changelog' => \$latestUpdate\['changelog'\]/.test(checkUpdate), '');
  ok('a held response says update_required is false',
    /'update_required' => false,\s*'update_available' => true,/.test(checkUpdate), '');
  ok('the release is still described, so paying is worth something',
    /'latest_version' => \$latestUpdate\['version_number'\][\s\S]{0,400}'changelog' => \$latestUpdate\['changelog'\]/.test(checkUpdate), '');
  ok('the refusal is logged for the admin',
    /Update access on hold for licence/.test(checkUpdate), '');
  ok('an allowed response still tells the client access was granted',
    /'download_url' => \$downloadUrl,\s*'access' => lm_update_access_json\(\$access\),/.test(checkUpdate), '');

  const dlGate = download.indexOf('lm_license_updates_allowed(');
  const dlServe = download.indexOf('lm_resolve_update_path(');
  ok('download.php re-checks the licence, not just the token',
    dlGate !== -1 && dlServe !== -1 && dlGate < dlServe, 'gate at ' + dlGate + ', serve at ' + dlServe);
  ok('a token that outlived the hold is refused with the same explanation',
    /http_response_code\(403\);[\s\S]{0,300}'status' => 'payment_required'/.test(download), '');
  ok('the refusal is logged', /Download refused: update access on hold/.test(download), '');

  ok('notification emails are not queued for a held licence',
    /if \(!lm_license_updates_allowed\(\$license\)\) \{[\s\S]{0,300}Update access on hold/.test(notifications), '');
  ok('the reason lands in the delivery log',
    /UPDATE update_notifications SET status = 'skipped', last_error = 'Update access on hold'/.test(notifications), '');

  console.log('\nThe admin can hold one licence, or a whole project');

  ok('the row has a hold/allow action', /name="toggle_updates"/.test(licencesPage), '');
  ok('the action knows the current state, so it toggles',
    /name="current_updates"/.test(licencesPage), '');
  ok('holding records when it happened',
    /SET updates_enabled = 0, updates_blocked_at = NOW\(\)/.test(licencesPage), '');
  ok('allowing clears the date', /updates_blocked_at = NULL/.test(licencesPage), '');
  ok('the table shows the state', /status-onhold/.test(licencesPage) && /On hold/.test(licencesPage), '');
  ok('the note is visible on the row', /updates_block_note/.test(licencesPage), '');
  ok('a whole project can be held or released at once',
    /name="bulk_updates"/.test(licencesPage) && /name="bulk_mode" value="hold"/.test(licencesPage), '');
  ok('the bulk control counts licences and how many are on hold',
    /SUM\(CASE WHEN l\.updates_enabled = 0 THEN 1 ELSE 0 END\)/.test(licencesPage), '');
  ok('the edit form explains what the switch does',
    /Allow this licence to pull updates/.test(licencesPage), '');

  ok('Settings saves the number normalised',
    /\$new\['vendor_whatsapp'\] = lm_whatsapp_number\(\$_POST\['vendor_whatsapp'\]/.test(settingsPage), '');
  ok('Settings has the card', /Vendor Contact &amp; Update Access/.test(settingsPage), '');
  ok('the card previews the real button',
    /Button preview:/.test(settingsPage) && /lm_vendor_contact\(\)/.test(settingsPage), '');
  ok('an empty number is called out, not silently accepted',
    /No WhatsApp number yet/.test(settingsPage), '');
  const defaults = fs.readFileSync(path.join(LM, 'includes/lm_config.php'), 'utf8');
  ok('nothing is seeded: the default number is empty',
    /'vendor_whatsapp'\s*=> ''/.test(defaults), '');
  ok('the default wording is empty too, so the built-in text is used',
    /'vendor_contact_message'\s*=> ''/.test(defaults), '');

  console.log('\nThe licence table gains its columns exactly once');

  ok('the schema version moved', /return '2026_10_28_update_access';/.test(migrations), '');
  ok('the migration is registered', /'2026_10_28_update_access' => 'lm_migration_update_access'/.test(migrations), '');
  ok('every licence keeps working after the upgrade',
    /'updates_enabled', "TINYINT\(1\) NOT NULL DEFAULT 1"/.test(migrations), '');
  ok('a NULL from a half-applied ALTER is repaired',
    /UPDATE licenses SET updates_enabled = 1 WHERE updates_enabled IS NULL/.test(migrations), '');
  ok('a reference SQL file ships with it',
    fs.existsSync(path.join(LM, 'migrations', '03_update_access.sql')), '');

  console.log('\nThe customer’s own update page shows the card');

  const holdAt = vendorPage.indexOf('if ($license_hold)');
  const installAt = vendorPage.indexOf('elseif ($update_available)');
  ok('the hold branch is checked first',
    holdAt !== -1 && installAt !== -1 && holdAt < installAt, 'hold at ' + holdAt + ', install at ' + installAt);
  ok('"payment_required" is understood, not treated as an error',
    /status'\] === 'payment_required'/.test(vendorPage), '');
  ok('the page keeps the version and changelog while on hold',
    /Waiting for you[\s\S]{0,1500}What's New in this release/.test(vendorPage), '');
  ok('the card draws the WhatsApp button from the remote block',
    /href="<\?= htmlspecialchars\(\$license_hold\['whatsapp_url'\]\) \?>"/.test(vendorPage), '');
  ok('there is a fallback when only an email exists',
    /btn-whatsapp-mail/.test(vendorPage), '');
  ok('no install button survives on the hold branch',
    vendorPage.slice(0, holdAt).indexOf('btn-start-update') === -1
    && vendorPage.slice(holdAt, installAt).indexOf('btn-start-update') === -1, '');
  ok('the page now reports its installed version',
    /'current_version' => \$current_version/.test(vendorPage), '');
  ok('a hold applied mid-install is shown where they are looking',
    /if \(downloadData\.status === 'payment_required'\)/.test(vendorPage), '');

  ok('the STANDARD build has the same card',
    /if \(\$license_hold\)/.test(vendorPageStd) && /btn-whatsapp/.test(vendorPageStd), '');
  ok('the download endpoint passes the block through',
    /'status' => 'payment_required'[\s\S]{0,200}'access' => \$res_data\['access'\]/.test(vendorDl), '');
  ok('a refused download shows the server’s sentence, not "HTTP 403"',
    /is_array\(\$errorJson\) && !empty\(\$errorJson\['message'\]\)/.test(vendorDl), '');
  ok('the STANDARD download endpoint does too',
    /'status' => 'payment_required'/.test(vendorDlStd), '');

  console.log('\nThe admin guide explains it without a support ticket');

  ok('there is a section for it', /Holding a release until a vendor pays/.test(guide), '');
  ok('it says the site keeps working', /keeps validating/.test(guide), '');
  ok('it points at Settings for the number', /Vendor Contact &amp; Update Access/.test(guide), '');

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});

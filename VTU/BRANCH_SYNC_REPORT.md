# Branch sync report — `arena/c1921fae-projects` vs `main`

Date: 2026-11-07

## 1. Answer: the branch had **nothing** that main was missing

```
merge-base(main, arena/c1921fae-projects) == arena/c1921fae-projects head
branch head before : cb63a93  "SMSP"
main head before   : 243382c  "correction for the referral system"
commits ahead of main : 0
commits behind main   : 61
```

The branch sat exactly on the merge base, so it had **no unique commits at all** — there was nothing
to merge from it into `main`. The problem was the opposite of what you expected: cPanel has been
pulling a branch that is **61 commits stale**, which is why today's referral fix never reached the
server.

Checked every other remote branch too. All of them are ancestors of `main` except one:

| Branch | Ahead of main | Notes |
| --- | --- | --- |
| all `arena/*` except `8a3f6d9a` | 0 | fully merged, nothing pending |
| `arena/8a3f6d9a-projects` | 4 | ⚠️ contains commit `779bae2 "Delete VTU directory"` — **do not merge**, it would delete the whole `VTU/` tree (DGV7 + all mobile apps) |

## 2. What was done

Because the branch was an ancestor, moving it to `main` is a **fast-forward** — no merge commit, no
conflicts, and impossible to lose work (the branch had no unique commits to lose).

```
git push origin origin/main:refs/heads/arena/c1921fae-projects      # cb63a93 -> 243382c
```

```
branch head after : 243382c   (identical to main at that moment)
```

Then a security problem was found in that content and a second fast-forward was applied (see §3):

```
git push origin origin/main:refs/heads/arena/c1921fae-projects      # 243382c -> da0030e
branch head now   : da0030e9dcb6890904283a1e80770d8fb8926ac9
main head now     : da0030e9dcb6890904283a1e80770d8fb8926ac9
```

**Branch and `main` are now the same commit.**

### Rollback

```
git push --force-with-lease origin cb63a9361438919a9ddbcbbeb7fcd2b40debcf67:refs/heads/arena/c1921fae-projects
```

## 3. Security incident found during the check

Commit `a9046c8 "COMMIT"` (and `e88dae7`) added **private signing keys and build output** to the
repository, and they were pushed:

| Path | Contents |
| --- | --- |
| `VTU/play-signing-keys/*.jks` | **Private upload keystores** for DG7, JIKABIZ, MZEEVTU |
| `VTU/play-signing-keys/*-cert.pem/.der` | their public certificates |
| `VTU/ANDROID - iOS/release-output/` | 342 MB of signed `.aab`/`.apk` bundles + `mapping.txt` |

Containment applied in `da0030e`:

* `git rm -r --cached` on both paths — files removed from git, **nothing deleted from disk**
* `.gitignore` now blocks `VTU/play-signing-keys/`, `*.jks`, `*.keystore`, `*.p12`, `*.pfx`,
  `*-cert.pem`, `*-cert.der`, `key.properties`, `keystore.properties`, `**/release-output/`, `*.aab`

Verified afterwards:

```
keys tracked on main   : 0
keys tracked on branch : 0
artifacts tracked      : 0
git check-ignore VTU/play-signing-keys/mzeevtu-upload.jks  ->  matched
```

### ⚠️ The keys are still in history — treat them as exposed

`a9046c8` remains in the history of `main` and every branch, so anyone who clones the repository can
recover the `.jks` files with `git show a9046c8:VTU/play-signing-keys/mzeevtu-upload.jks`.
Combined with `VTU/ANDROID - iOS/DG7-Android/release.keystore.b64` (already tracked since
`f30d84c`) and the passwords shared in chat, **all three upload keys must be considered
compromised.**

Recommended rotation:

1. **MZEEVTU / JIKABIZ** — their Play upload-key resets have not been approved yet. Generate a
   **fresh** key pair outside the repository and submit that certificate in the reset request
   instead of the leaked one; the leaked files then never become a Play upload key at all.
2. **DG7** — its key (`1E:92:6C…`) was already accepted by Play for versionCode 7. It is exposed via
   `release.keystore.b64` and the chat password. After the current release is published, perform an
   upload-key reset to a freshly generated key.
3. Optionally scrub history with `git filter-repo --path VTU/play-signing-keys --invert-paths` and
   force-push **after** coordinating, because it rewrites every commit SHA and every branch.

## 4. What cPanel will now receive

Pulling `arena/c1921fae-projects` moves the server `cb63a93 -> da0030e`: **153 files**.

The DGV7/PHP changes — the part that matters for the live site — are exactly today's fix:

```
M  VTU/DGV7.0-SAAS/func/bc-tables.php
M  VTU/DGV7.0-SAAS/web/api/referral.php
A  VTU/DGV7.0-SAAS/tests/app_referral_resilience_test.php
M  VTU/DGV7.0-NON-SAAS/func/bc-tables.php
M  VTU/DGV7.0-NON-SAAS/web/api/referral.php
A  VTU/DGV7.0-NON-SAAS/tests/app_referral_resilience_test.php
```

No keys and no build artifacts are in the pull (`0` of each).

The remaining ~147 files are `KINGDOMBUILDERS/` (76), `REMIT/` (67), root `index.php`, `.gitignore`
and `.github/workflows/kingdombuilders.yml` — all already on `main`, none of them DGV7.

### One thing to check before pulling

Root `index.php` changes from a plain text listing to:

```php
<?php
header('Location: ./KINGDOMBUILDERS/', true, 302);
exit;
```

If the cPanel document root is the **repository root**, that redirect will change what the site's
homepage does. If each project is served from its own subfolder / subdomain (e.g. the DGV7 docroot is
`VTU/DGV7.0-SAAS`), it is irrelevant. Verify the docroot before pulling, or pull only the six DGV7
files.

## 5. Procedure for next time

```bash
# 1. Is the branch you deploy from behind main?
git fetch origin
git rev-list --count origin/arena/c1921fae-projects..origin/main   # >0 means stale

# 2. Fast-forward it (safe: fails instead of merging if they diverged)
git push origin origin/main:refs/heads/arena/c1921fae-projects

# 3. On cPanel
cd ~/repositories/PROJECTS && git fetch --all && git reset --hard origin/arena/c1921fae-projects
```

Using `git reset --hard` on the server is what keeps it exactly aligned with the branch; a plain
`git pull` fails when the server has local edits (common when a file is patched directly in cPanel).

KINGDOM BUILDERS QUIZ — SETUP GUIDE (v2)
==========================================

FILES TO UPLOAD
- index.html   -> Login page (students enter name; admin logs in with password)
- quiz.html    -> The test itself — driven entirely by your Settings
- admin.html   -> Admin dashboard: Results / Settings / Questions tabs
- api.php      -> Backend: scoring, settings, question bank, anti-cheat logic

These files auto-create on first use (do not upload them, they appear on their own):
- settings.json  -> your saved test controls
- questions.json -> the question bank (seeded with 40 Matthew & Mark questions)
- sessions.json  -> in-progress/completed test sessions (internal use)
- scores.json    -> submitted results

HOW TO UPLOAD
1. cPanel -> File Manager -> public_html (or your chosen subfolder).
2. Upload index.html, quiz.html, admin.html, api.php.
3. Make sure the folder is writable (Permissions 755 on the folder; if saving
   settings/scores fails, try 775). Files: 644.

LINKS
- Everyone uses the same link: http://kingdombuilders.pmhserver.name.ng/
- Students pick the "Student" tab and enter their name.
- You (admin) pick the "Administrator" tab and enter the password.

DEFAULT ADMIN PASSWORD: kingdom2026
Change it by opening api.php and editing this line near the top:
  $ADMIN_PASSWORD = "kingdom2026";

------------------------------------------
ADMIN CONTROLS (Dashboard -> Settings tab)
------------------------------------------
- Questions per test       — how many of the question bank each student gets
- Pass mark (%)             — shown as Pass/Fail on the Results tab
- Seconds per question      — timer length
- Max tab-switches before auto-submit — anti-cheat (0 disables monitoring)
- Timer enabled              — turn the countdown off entirely if you want
- Allow going back           — let students revisit/change earlier answers
- Randomize question order   — each student gets a different question order
- Randomize answer order     — option positions (A/B/C/D) shuffle per student
- Show correct/wrong feedback during test — keep this OFF for anti-cheat
- One attempt per name       — blocks a repeat submission under the same name
- Test open                  — flip off to stop new attempts instantly

Settings apply to every NEW test started after you click Save — students
already mid-test are unaffected.

------------------------------------------
QUESTION BANK (Dashboard -> Questions tab)
------------------------------------------
Add, edit or delete questions right from the dashboard — no code editing
needed anymore. Each question needs 4 options and a marked correct answer.
The 40 starter questions (Matthew & Mark) are already loaded.

------------------------------------------
ANTI-CHEATING MEASURES BUILT IN
------------------------------------------
1. Answers never reach the browser. The correct answer is checked and stored
   on the server, not in quiz.html's code — so "view source" or the browser
   console reveals nothing useful.
2. No feedback leak. By default, students are NOT shown whether an answer was
   right or wrong during the test (toggle this in Settings if you want it on
   for practice runs).
3. Per-student shuffling. If enabled, each student sees questions and answer
   options in a different order, making it harder to copy a neighbor's
   answer pattern ("B, A, D, C..." means something different for everyone).
4. Tab/window switch detection. If a student switches tabs or apps during
   the test, it's logged and shown on your Results tab (and you can set it
   to auto-submit their test after a chosen number of switches).
5. Copy & right-click disabled on the quiz page.
6. One attempt per name (optional) stops someone retaking the test to get
   a better score or to feed answers to a classmate.
7. Accidental close/refresh warning — reduces "lost my progress, let me
   retry" as a backdoor for a second attempt.

HONEST LIMITATION: this is a browser-based tool, not locked-down exam
software. A determined student with a second device can still look up
answers externally, and nothing here can stop that — these measures target
the easy, common forms of cheating (copying a neighbor, replaying the
test, inspecting the page for answers), not a fully proctored environment.

------------------------------------------
RESULTS TAB
------------------------------------------
Shows name, score, percentage, Pass/Fail, time taken, and tab-switch count
for every student, newest first. "Export CSV" downloads everything for
Excel/Sheets. "Clear All" wipes all results and sessions — use with care.

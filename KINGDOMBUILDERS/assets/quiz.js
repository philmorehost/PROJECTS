'use strict';
(() => {
  const root = document.getElementById('quiz-app');
  if (!root) return;
  const token = document.querySelector('meta[name="csrf-token"]').content;
  const endpoint = root.dataset.endpoint;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let state = null;
  let current = 0;
  let busy = false;
  let finished = false;
  let clock = null;
  let offset = 0;
  let lastFlag = 0;
  let queue = Promise.resolve();
  let leaving = false;

  const node = (tag, className, text) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  };

  /** AbortSignal.timeout() is missing on older Safari; fall back to a controller. */
  function timeoutSignal(ms) {
    if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function') return AbortSignal.timeout(ms);
    const controller = new AbortController();
    setTimeout(() => controller.abort(), ms);
    return controller.signal;
  }

  async function request(action, data = {}) {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify({ action, ...data }),
      signal: timeoutSignal(15000)
    });
    const result = await response.json();
    if (!response.ok) {
      throw Object.assign(new Error(result.error || 'The connection was interrupted. Please try again.'), { status: response.status });
    }
    return result;
  }

  function enqueue(action, data) {
    const task = queue.then(() => request(action, data));
    queue = task.catch(() => {});
    return task;
  }

  function say(text, tone = '') {
    const el = document.getElementById('quiz-status');
    if (!el) return;
    el.textContent = text;
    el.className = 'quiz-status' + (tone ? ' is-' + tone : '');
  }

  /* ── End of quiz ─────────────────────────────────────────────────────── */
  function showEnd() {
    finished = true;
    clearInterval(clock);
    root.replaceChildren();

    const passed = state.percentage >= (state.settings.pass_mark ?? 50);
    const panel = node('section', 'quiz-finish');
    panel.append(node('span', 'quiz-finish-seal', passed ? '✓' : '✦'));
    panel.append(node('span', 'eyebrow', passed ? 'WELL DONE' : 'EVERY STEP COUNTS'));

    const ring = node('div', 'score-ring' + (passed ? ' is-pass' : ''));
    ring.setAttribute('role', 'img');
    ring.setAttribute('aria-label', `${state.percentage}% — ${state.score} of ${state.total} correct`);
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 120 120');
    svg.setAttribute('aria-hidden', 'true');
    const track = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    track.setAttribute('cx', '60'); track.setAttribute('cy', '60'); track.setAttribute('r', '52');
    track.setAttribute('class', 'score-ring-track');
    const value = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    value.setAttribute('cx', '60'); value.setAttribute('cy', '60'); value.setAttribute('r', '52');
    value.setAttribute('class', 'score-ring-value');
    value.setAttribute('pathLength', '100');
    value.setAttribute('stroke-dasharray', `${Math.max(0, Math.min(100, state.percentage))} 100`);
    svg.append(track, value);
    const caption = node('div', 'score-ring-text');
    caption.append(node('strong', null, `${Math.round(state.percentage)}%`), node('span', null, `${state.score} of ${state.total} correct`));
    ring.append(svg, caption);

    panel.append(ring);
    const canReview = state.can_review !== false;
    panel.append(node('h1', null, passed ? 'Another step forward.' : 'Keep growing—one question at a time.'));
    panel.append(node('p', 'quiz-finish-lead', canReview
      ? (passed
        ? 'Your assessment is submitted — this attempt is now complete. Review anything you missed while it is still fresh.'
        : 'Your assessment is submitted — this attempt is now complete. The review below shows the correct answers with references.')
      : 'Your assessment is submitted and scored. This attempt is complete, and your score is ready on your result page.'));
    if (state.percentage < (state.settings.pass_mark ?? 50)) {
      panel.append(node('p', 'quiz-finish-note', `The pass mark for this community is ${state.settings.pass_mark ?? 50}%.`));
    }

    const actions = node('div', 'quiz-finish-actions');
    const review = node('a', 'button', canReview ? 'Review your answers →' : 'View your score →');
    review.href = root.dataset.result;
    const home = node('a', 'button secondary', 'Back to dashboard');
    home.href = root.dataset.dashboard;
    actions.append(review, home);
    panel.append(actions);

    root.append(panel);
    review.focus({ preventScroll: true });
  }

  async function finish(expired = false) {
    if (busy || finished) return;
    busy = true;
    say(expired ? 'Time is up. Saving your answers…' : 'Submitting your answers…');
    try {
      state = await enqueue('finish');
      finished = true;
      showEnd();
    } catch (error) {
      say(error.message + ' Use “Finish quiz” to try again.', 'error');
    } finally {
      busy = false;
    }
  }

  /* ── Timer ───────────────────────────────────────────────────────────── */
  function updateClock() {
    const el = document.getElementById('quiz-timer');
    const value = el ? el.querySelector('.quiz-timer-value') : null;
    const bar = document.getElementById('quiz-time-fill');
    if (!el || !value || !state || finished) return;
    if (!state.expires_at) {
      value.textContent = 'No limit';
      el.classList.remove('is-urgent');
      return;
    }
    const left = Math.max(0, state.expires_at - Math.floor(Date.now() / 1000) + offset);
    const mins = Math.floor(left / 60);
    value.textContent = `${mins}:${String(left % 60).padStart(2, '0')}`;
    el.classList.toggle('is-urgent', left <= 60);
    if (bar) {
      const total = state.budget_seconds || state.total * (state.settings.seconds_per_question || 24);
      bar.style.width = Math.max(0, Math.min(100, (left / Math.max(1, total)) * 100)) + '%';
    }
    if (left === 0) { clearInterval(clock); leaving = false; finish(true); }
  }

  /* ── Question screen ─────────────────────────────────────────────────── */
  function render() {
    if (!state || state.status !== 'active') { if (state) showEnd(); return; }
    root.replaceChildren();

    const shell = node('div', 'quiz-shell');
    const q = state.questions[current];
    const answered = state.questions.filter(item => item.answered).length;
    // While the attempt runs, a saved answer can be changed (if the administrator
    // allows revisiting). Once the attempt is finished, nothing is interactive.
    const editable = q.editable !== false && state.status === 'active';
    const revisitable = state.settings.allow_back !== false;

    /* header */
    const head = node('header', 'quiz-head');
    const meta = node('div', 'quiz-head-meta');
    meta.append(node('span', 'eyebrow', 'THE BIBLE QUIZ'));
    meta.append(node('span', 'quiz-count', `Question ${current + 1} of ${state.total}`));
    const jump = node('button', 'quiz-jump');
    jump.type = 'button';
    jump.id = 'quiz-jump';
    jump.setAttribute('aria-expanded', 'false');
    jump.setAttribute('aria-controls', 'quiz-dots');
    jump.append(node('span', null, 'Answer sheet'), node('strong', null, `${answered}/${state.total}`));
    jump.addEventListener('click', () => {
      const open = jump.getAttribute('aria-expanded') === 'true';
      jump.setAttribute('aria-expanded', open ? 'false' : 'true');
      document.getElementById('quiz-dots')?.classList.toggle('is-open', !open);
    });
    meta.append(jump);
    const timer = node('div', 'quiz-timer');
    timer.id = 'quiz-timer';
    timer.setAttribute('role', 'timer');
    timer.setAttribute('aria-live', 'off');
    timer.append(node('span', 'quiz-timer-icon', '◷'), node('span', 'quiz-timer-value', '—'));
    head.append(meta, timer);

    /* progress */
    const progress = node('div', 'quiz-progress');
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', String(state.total));
    progress.setAttribute('aria-valuenow', String(answered));
    progress.setAttribute('aria-label', `${answered} of ${state.total} answered`);
    const rail = node('div', 'quiz-progress-rail');
    const fill = node('div', 'quiz-progress-fill');
    fill.style.width = `${(answered / state.total) * 100}%`;
    const timeFill = node('div', 'quiz-time-fill');
    timeFill.id = 'quiz-time-fill';
    rail.append(fill, timeFill);
    const dots = node('ol', 'quiz-dots');
    dots.id = 'quiz-dots';
    state.questions.forEach((item, index) => {
      const dot = node('li', 'quiz-dot');
      if (item.answered) dot.classList.add('is-done');
      if (index === current) dot.classList.add('is-current');
      const tip = node('button', 'quiz-dot-button');
      tip.type = 'button';
      tip.append(node('span', null, String(index + 1)));
      tip.setAttribute('aria-label', `Question ${index + 1}: ${item.answered ? 'answered' : 'not answered'}${index === current ? ', current question' : ''}`);
      tip.setAttribute('aria-current', index === current ? 'true' : 'false');
      // Jumping back is only an option when the administrator allows revisiting.
      const canJump = index === current || state.settings.allow_back;
      tip.disabled = !canJump;
      if (canJump) tip.addEventListener('click', () => { current = index; render(); focusQuestion(); });
      dot.append(tip);
      dots.append(dot);
    });
    progress.append(rail, dots);

    /* card */
    const card = node('section', 'quiz-card');
    card.setAttribute('aria-labelledby', 'quiz-question');
    const badges = node('div', 'quiz-badges');
    if (q.category) badges.append(node('span', 'quiz-chip', q.category));
    badges.append(node('span', 'quiz-chip is-quiet', `Question ${current + 1} / ${state.total}`));
    if (q.answered && editable) badges.append(node('span', 'quiz-chip is-edit', revisitable ? '✓ Answer saved · you can change it' : '✓ Answer saved · still current question'));
    else if (q.answered) badges.append(node('span', 'quiz-chip is-lock', '🔒 Answer locked'));
    card.append(badges);

    const question = node('h1', 'quiz-question', q.question);
    question.id = 'quiz-question';
    question.tabIndex = -1;
    card.append(question);

    const answers = node('div', 'quiz-answers');
    answers.setAttribute('role', 'group');
    answers.setAttribute('aria-labelledby', 'quiz-question');
    q.options.forEach((text, index) => {
      const picked = q.answer === index;
      const muted = q.answer !== null && q.answer !== -1 && !editable;
      const button = node('button', 'answer' + (picked ? ' is-picked' : '') + (muted && !picked ? ' is-dimmed' : ''));
      button.type = 'button';
      button.setAttribute('aria-pressed', String(picked));
      button.disabled = !editable;
      button.append(node('span', 'answer-letter', String.fromCharCode(65 + index)));
      button.append(node('span', 'answer-text', text));
      button.append(node('span', 'answer-state', picked ? 'Selected' : ''));
      if (editable) button.addEventListener('click', () => save(index));
      answers.append(button);
    });
    card.append(answers);

    const lockNote = node('div', 'quiz-locked-note' + (q.answered ? '' : ' is-hidden'));
    lockNote.id = 'quiz-lock-note';
    lockNote.append(node('span', 'quiz-locked-icon', editable ? '✓' : '🔒'), node('span', null,
      !q.answered ? ''
        : (editable
          ? 'Saved. Think another option is better? Choose it — you can change your answer until time runs out or you submit.'
          : 'This answer is final. The assessment has ended.')));
    card.append(lockNote);
    if (revisitable && state.total > 1) {
      const sheetHint = node('p', 'quiz-sheet-hint', 'Use “Answer sheet” or the numbered strip to revisit any question. Answers are only final when you submit.');
      card.append(sheetHint);
    }

    /* footer controls */
    const footer = node('footer', 'quiz-footer');
    const back = node('button', 'button secondary quiz-back', '← Previous');
    back.type = 'button';
    back.disabled = current === 0 || !revisitable;
    back.addEventListener('click', () => {
      if (busy || current === 0) return;
      current -= 1; render(); focusQuestion();
    });

    const hint = node('span', 'quiz-hint');
    hint.append(node('kbd', null, '1'), node('span', null, '–'), node('kbd', null, '4'), node('span', null, ' to answer · '), node('kbd', null, 'Enter'), node('span', null, ' to continue'));

    // "Next" only needs an answer for the question on screen; "Submit" is
    // available once anything has been answered, so a member can hand in early.
    const last = current === state.total - 1;
    const anyAnswered = state.questions.some(item => item.answered);
    const next = node('button', 'button quiz-next', last ? 'Submit assessment →' : 'Next question →');
    next.type = 'button';
    next.id = 'quiz-next';
    next.disabled = last ? !anyAnswered : !q.answered;
    next.addEventListener('click', advance);

    footer.append(back, hint, next);
    card.append(footer);

    const status = node('p', 'quiz-status');
    status.id = 'quiz-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = q.answered
      ? (editable ? 'Answer saved. You can change it until you submit.' : 'This answer is final.')
      : 'Choose the answer you believe is correct. You can change it until you submit.';

    shell.append(head, progress, card, status);
    root.append(shell);
    updateClock();
  }

  function focusQuestion() {
    const heading = document.getElementById('quiz-question');
    if (heading) heading.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
  }

  function advance() {
    if (busy || finished) return;
    const q = state.questions[current];
    if (!q.answered) { say('Choose an answer first. You can change it later.', 'error'); return; }
    if (current < state.total - 1) {
      current += 1;
      render();
      focusQuestion();
      return;
    }
    // Submitting is final, so say exactly what will be handed in.
    const unanswered = state.questions.filter(item => !item.answered).length;
    const message = unanswered === 0
      ? 'Submit your assessment now? This is final — you cannot take it again.'
      : `${unanswered} question${unanswered === 1 ? ' has' : 's have'} no answer. Submit anyway? This is final — you cannot take it again.`;
    if (confirm(message)) finish();
  }

  async function save(choice) {
    if (busy || finished) return;
    const position = current;
    const question = state.questions[position];
    if (question.editable === false) { say('The assessment has ended. Your answers are final.', 'error'); return; }
    if (question.answered && question.answer === choice) { say('That is already your saved answer.', ''); return; }

    busy = true;
    const changing = question.answered;
    const buttons = Array.from(document.querySelectorAll('.quiz-answers .answer'));
    buttons.forEach(button => { button.disabled = true; });
    const nextButton = document.getElementById('quiz-next');
    if (nextButton) nextButton.disabled = true;
    buttons[choice]?.classList.add('is-choosing');
    say(changing ? 'Changing your answer…' : 'Saving your answer…');

    try {
      const result = await enqueue('answer', { position, choice });
      question.answer = choice;
      question.answered = true;
      render();
      const fresh = document.getElementById('quiz-next');
      if (fresh) fresh.focus({ preventScroll: true });
      say(changing ? 'Answer changed. You can still change it again before you submit.' : 'Answer saved. You can change it before you submit.', 'success');
      if (result.feedback) {
        const card = document.querySelector('.quiz-card');
        const feedback = node('div', 'quiz-feedback' + (result.feedback.correct ? ' is-correct' : ' is-wrong'),
          (result.feedback.correct ? 'Correct. ' : 'Not quite. ') + (result.feedback.explanation || ''));
        card?.querySelector('.quiz-footer')?.before(feedback);
      }
    } catch (error) {
      buttons.forEach(button => { button.disabled = false; button.classList.remove('is-choosing'); });
      say(error.message, 'error');
      if (error.status === 409) {
        try {
          state = await request('state');
          const existing = state.questions[position];
          if (existing && existing.editable === false) { render(); return; }
        } catch { /* keep the current screen usable */ }
      }
      const retry = document.getElementById('quiz-next');
      if (retry) retry.disabled = true;
    } finally {
      busy = false;
    }
  }

  async function flag() {
    if (finished || Date.now() - lastFlag < 5000 || document.hidden === false) return;
    lastFlag = Date.now();
    try {
      const result = await enqueue('flag');
      if (result.ended) { state = await enqueue('state'); showEnd(); }
      else say(`Focus change recorded (${result.count}). Please stay on this page.`, 'error');
    } catch {
      say('The focus signal could not be saved. Your quiz time continues.', 'error');
    }
  }

  /**
   * Leaving the page ends the assessment. ask() lets a click on a link or a
   * button cancel first; the browser's own confirmation covers closing the tab.
   */
  function active() {
    return !finished && state && state.status === 'active';
  }

  function leave(keepalive = false) {
    if (!active() || leaving) return Promise.resolve();
    leaving = true;
    const body = JSON.stringify({ action: 'leave' });
    if (keepalive && navigator.sendBeacon) {
      // A beacon survives the page being closed. The CSRF token travels in the
      // body, which sendBeacon can set with a Blob of type application/json.
      navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
      return Promise.resolve();
    }
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token },
      body,
    }).then(response => response.json().catch(() => ({}))).then(result => {
      if (result && result.ended) { finished = true; showEnd(); }
      return result;
    }).catch(() => {});
  }

  // Arm the answer sheet, the mobile dock and the header links.
  document.addEventListener('click', event => {
    if (!active()) return;
    const link = event.target.closest('a[href], form[action] button');
    if (!link || link.dataset.keepOpen === 'true') return;
    // Same-page links (the answer sheet, for example) do not leave the paper.
    const leavesQuiz = link.tagName === 'A' ? !link.getAttribute('href').startsWith('#') : true;
    if (!leavesQuiz) return;
    if (!window.confirm('Leave the quiz? Your assessment will be submitted with the answers saved so far, and you cannot take it again.')) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    // A page navigation needs the request to outlive it.
    leave(true);
  }, true);

  document.addEventListener('visibilitychange', flag);

  window.addEventListener('beforeunload', event => {
    if (!active()) return;
    // The browser shows its own wording; ours explains the consequence.
    event.preventDefault();
    event.returnValue = '';
    leave(true);
  });

  window.addEventListener('pagehide', () => { if (active()) leave(true); });

  document.addEventListener('keydown', event => {
    if (finished || busy || !state || state.status !== 'active') return;
    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) return;
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const question = state.questions[current];
    if (!question) return;
    if (/^[1-4]$/.test(event.key) && question.editable !== false && Number(event.key) - 1 < question.options.length) {
      event.preventDefault();
      save(Number(event.key) - 1);
    } else if (event.key === 'Enter' && document.activeElement?.tagName !== 'BUTTON') {
      event.preventDefault();
      advance();
    }
  });

  request('state')
    .then(data => {
      state = data;
      offset = state.server_time - Math.floor(Date.now() / 1000);
      if (state.status === 'active') {
        // Resume where the member stopped: the first unanswered question, or the
        // last one when everything has been answered and can still be revised.
        const unanswered = state.questions.findIndex(q => !q.answered);
        current = unanswered < 0 ? state.total - 1 : unanswered;
        render();
        if (state.expires_at) clock = setInterval(updateClock, 500);
      } else {
        showEnd();
      }
    })
    .catch(error => {
      root.replaceChildren();
      const panel = node('section', 'panel quiz-loading');
      panel.append(node('span', 'eyebrow', 'CONNECTION'), node('h1', null, 'Let’s reconnect.'), node('p', null, error.message));
      const retry = node('button', 'button', 'Try again');
      retry.addEventListener('click', () => location.reload());
      panel.append(retry);
      root.append(panel);
    });
})();

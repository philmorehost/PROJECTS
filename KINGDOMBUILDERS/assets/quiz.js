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
        ? 'Your answers are saved. Review anything you missed while it is still fresh.'
        : 'Your answers are saved. The review below shows the correct answers with references.')
      : 'Your answers are saved and scored. Your score is ready on your result page.'));
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
      const total = state.total * (state.settings.seconds_per_question || 30);
      bar.style.width = Math.max(0, Math.min(100, (left / Math.max(1, total)) * 100)) + '%';
    }
    if (left === 0) { clearInterval(clock); finish(true); }
  }

  /* ── Question screen ─────────────────────────────────────────────────── */
  function render() {
    if (!state || state.status !== 'active') { if (state) showEnd(); return; }
    root.replaceChildren();

    const shell = node('div', 'quiz-shell');
    const q = state.questions[current];
    const answered = state.questions.filter(item => item.locked).length;
    const locked = !!q.locked;

    /* header */
    const head = node('header', 'quiz-head');
    const meta = node('div', 'quiz-head-meta');
    meta.append(node('span', 'eyebrow', 'THE BIBLE QUIZ'));
    meta.append(node('span', 'quiz-count', `Question ${current + 1} of ${state.total}`));
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
    state.questions.forEach((item, index) => {
      const dot = node('li', 'quiz-dot');
      if (item.locked) dot.classList.add('is-done');
      if (index === current) dot.classList.add('is-current');
      dot.setAttribute('aria-label', item.locked ? `Question ${index + 1}: answered` : `Question ${index + 1}: not answered`);
      dot.append(node('span', null, String(index + 1)));
      dots.append(dot);
    });
    progress.append(rail, dots);

    /* card */
    const card = node('section', 'quiz-card');
    card.setAttribute('aria-labelledby', 'quiz-question');
    const badges = node('div', 'quiz-badges');
    if (q.category) badges.append(node('span', 'quiz-chip', q.category));
    badges.append(node('span', 'quiz-chip is-quiet', `Question ${current + 1} / ${state.total}`));
    if (locked) badges.append(node('span', 'quiz-chip is-lock', '🔒 Answer locked'));
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
      const button = node('button', 'answer' + (picked ? ' is-picked' : '') + (locked ? ' is-locked' : ''));
      button.type = 'button';
      button.setAttribute('aria-pressed', String(picked));
      button.disabled = locked;
      button.append(node('span', 'answer-letter', String.fromCharCode(65 + index)));
      button.append(node('span', 'answer-text', text));
      button.append(node('span', 'answer-state', picked ? 'Saved' : ''));
      if (!locked) button.addEventListener('click', () => save(index));
      answers.append(button);
    });
    card.append(answers);

    const lockNote = node('div', 'quiz-locked-note' + (locked ? '' : ' is-hidden'));
    lockNote.id = 'quiz-lock-note';
    lockNote.append(node('span', 'quiz-locked-icon', '🔒'), node('span', null, locked
      ? 'Your answer is saved and locked. It cannot be changed.'
      : 'Once you choose, your answer is saved and locked.'));
    card.append(lockNote);

    /* footer controls */
    const footer = node('footer', 'quiz-footer');
    const back = node('button', 'button secondary quiz-back', '← Previous');
    back.type = 'button';
    back.disabled = current === 0 || !state.settings.allow_back;
    back.addEventListener('click', () => {
      if (busy || current === 0) return;
      current -= 1; render();
    });

    const hint = node('span', 'quiz-hint');
    hint.append(node('kbd', null, '1'), node('span', null, '–'), node('kbd', null, '4'), node('span', null, ' to answer · '), node('kbd', null, 'Enter'), node('span', null, ' to continue'));

    const next = node('button', 'button quiz-next', current === state.total - 1 ? 'Finish quiz →' : 'Next question →');
    next.type = 'button';
    next.id = 'quiz-next';
    next.disabled = !locked;
    next.addEventListener('click', advance);

    footer.append(back, hint, next);
    card.append(footer);

    const status = node('p', 'quiz-status');
    status.id = 'quiz-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = locked ? 'Answer saved. Continue when you are ready.' : 'Choose the answer you believe is correct. It locks as soon as it is saved.';

    shell.append(head, progress, card, status);
    root.append(shell);
    updateClock();
  }

  function advance() {
    if (busy || finished) return;
    const q = state.questions[current];
    if (!q.locked) { say('Choose an answer first — it locks once saved.', 'error'); return; }
    if (current < state.total - 1) {
      current += 1;
      render();
      const heading = document.getElementById('quiz-question');
      if (heading) heading.focus({ preventScroll: true });
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
      return;
    }
    const unanswered = state.questions.filter(item => !item.locked).length;
    if (unanswered === 0 || confirm(`${unanswered} question${unanswered === 1 ? ' has' : 's have'} no answer. Finish anyway? Your saved answers will be scored.`)) finish();
  }

  async function save(choice) {
    if (busy || finished) return;
    const position = current;
    const question = state.questions[position];
    if (question.locked) { say('That answer is already saved and cannot be changed.', 'error'); return; }

    busy = true;
    const buttons = Array.from(document.querySelectorAll('.quiz-answers .answer'));
    buttons.forEach(button => { button.disabled = true; });
    const nextButton = document.getElementById('quiz-next');
    if (nextButton) nextButton.disabled = true;
    buttons[choice]?.classList.add('is-choosing');
    say('Saving your answer…');

    try {
      const result = await enqueue('answer', { position, choice });
      question.answer = choice;
      question.locked = true;
      render();
      const fresh = document.getElementById('quiz-next');
      if (fresh) fresh.focus({ preventScroll: true });
      say(result.unchanged ? 'That answer was already saved.' : 'Answer saved and locked.', 'success');
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
          if (existing && existing.locked) { render(); return; }
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

  document.addEventListener('visibilitychange', flag);
  window.addEventListener('beforeunload', event => {
    if (!finished && (busy || (state && state.status === 'active' && state.questions[current] && !state.questions[current].locked))) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  document.addEventListener('keydown', event => {
    if (finished || busy || !state || state.status !== 'active') return;
    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) return;
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const question = state.questions[current];
    if (!question) return;
    if (/^[1-4]$/.test(event.key) && !question.locked && Number(event.key) - 1 < question.options.length) {
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
        const unanswered = state.questions.findIndex(q => !q.locked);
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

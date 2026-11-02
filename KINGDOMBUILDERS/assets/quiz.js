'use strict';
(() => {
  const root = document.getElementById('quiz-app');
  if (!root) return;
  const token = document.querySelector('meta[name="csrf-token"]').content;
  const endpoint = root.dataset.endpoint;
  let state, current = 0, busy = false, finished = false, clock, offset = 0, lastFlag = 0;
  let queue = Promise.resolve();
  const node = (tag, className, text) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  };
  async function request(action, data = {}) {
    const response = await fetch(endpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':token},
      body: JSON.stringify({action, ...data}), signal: AbortSignal.timeout(15000)
    });
    const result = await response.json();
    if (!response.ok) throw Object.assign(new Error(result.error || 'Connection interrupted. Please try again.'), {status:response.status});
    return result;
  }
  function enqueue(action, data) {
    const task = queue.then(() => request(action, data));
    queue = task.catch(() => {});
    return task;
  }
  function status(text, error = false) {
    const el = document.getElementById('quiz-status');
    if (el) { el.textContent = text; el.style.color = error ? 'var(--red)' : ''; }
  }
  function showEnd() {
    finished = true; clearInterval(clock);
    root.replaceChildren();
    const panel = node('section','message-panel');
    panel.append(node('span','success-seal','✓'),node('span','eyebrow','YOUR PROGRESS HAS BEEN SAVED'),node('h1',null,'Another step forward.'),node('p',null,`${state.score} of ${state.total} correct. Your result is ${state.percentage}%.`));
    const link = node('a','button','Review your answers →'); link.href = root.dataset.result;
    panel.append(link); root.append(panel);
  }
  async function finish(expired = false) {
    if (busy || finished) return;
    busy = true; status(expired ? 'Time is up. Saving your result…' : 'Submitting your answers…');
    try { state = await enqueue('finish'); showEnd(); }
    catch (error) { status(error.message + ' Use “Finish quiz” to retry.',true); }
    finally { busy = false; }
  }
  function updateClock() {
    const el = document.getElementById('quiz-timer');
    if (!el || !state || finished) return;
    if (!state.expires_at) { el.textContent = 'No time limit'; return; }
    const left = Math.max(0,state.expires_at - Math.floor(Date.now()/1000) + offset);
    el.textContent = `${Math.floor(left/60)}:${String(left%60).padStart(2,'0')} remaining`;
    el.classList.toggle('urgent',left <= 30);
    if (left === 0) { clearInterval(clock); finish(true); }
  }
  function render() {
    if (state.status !== 'active') { showEnd(); return; }
    root.replaceChildren();
    const shell = node('div','quiz-shell');
    const top = node('div','quiz-top');
    top.append(node('span','eyebrow','THE BIBLE QUIZ · ONE QUESTION AT A TIME'),node('span','timer'));
    top.lastChild.id = 'quiz-timer'; top.lastChild.setAttribute('aria-label','Time remaining');
    const progress = node('div','progress-track');
    const fill = node('div','progress-fill'); fill.style.width = `${current/state.total*100}%`;
    progress.setAttribute('aria-label',`Question ${current+1} of ${state.total}`); progress.append(fill);
    const card = node('section','panel quiz-card');
    const q = state.questions[current];
    card.append(node('span','eyebrow',`QUESTION ${current+1} OF ${state.total}`),node('h1',null,q.question));
    const answers = node('div','answers'); answers.setAttribute('role','group'); answers.setAttribute('aria-label','Choose one answer');
    q.options.forEach((text,index) => {
      const answer = node('button','answer'+(q.answer === index?' picked':''));
      answer.type = 'button'; answer.setAttribute('aria-pressed',String(q.answer === index));
      answer.append(node('span','answer-letter',String.fromCharCode(65+index)),node('span',null,text));
      answer.addEventListener('click',() => save(index,answer)); answers.append(answer);
    });
    card.append(answers);
    const controls = node('div','quiz-controls');
    const back = node('button','button secondary','← Previous'); back.type = 'button';
    back.disabled = !state.settings.allow_back || current === 0;
    back.addEventListener('click',() => { if(!busy && current>0 && state.settings.allow_back){current--;render();} });
    const next = node('button','button',current === state.total-1?'Finish quiz →':'Next question →'); next.type = 'button'; next.id = 'quiz-next';
    next.disabled = q.answer === null;
    next.addEventListener('click',() => {
      if(current < state.total-1){current++;render();}
      else {
        const unanswered = state.questions.filter(item=>item.answer===null).length;
        if(!unanswered || confirm(`You have ${unanswered} unanswered question(s). Finish this quiz?`)) finish();
      }
    });
    controls.append(back,next); card.append(controls);
    if(state.settings.practice_feedback && q.answer !== null && q.answer >= 0) card.append(node('div','quiz-feedback','Practice mode: your answer is saved. Review the explanation after completing the quiz.'));
    const message = node('p','quiz-status','Your answers are saved securely. Take a breath and choose carefully.'); message.id = 'quiz-status'; message.setAttribute('role','status'); message.setAttribute('aria-live','polite');
    shell.append(top,progress,card,message);
    const help = node('p','muted quiz-status',state.settings.allow_back?'You can revisit your answers before finishing.':'Answers lock when you move forward. A reload does not restart your time.');
    shell.append(help); root.append(shell); updateClock();
  }
  async function save(choice, button) {
    if(busy || finished) return;
    busy = true;
    const position = current;
    document.querySelectorAll('.answer,.quiz-controls button').forEach(el=>el.disabled=true);
    const next = document.getElementById('quiz-next'); if(next) next.disabled=true;
    status('Saving your answer…');
    try {
      const result = await enqueue('answer',{position,choice});
      state.questions[position].answer = choice;
      if(choice>=0) {
        document.querySelectorAll('.answer').forEach((el,i)=>{el.classList.toggle('picked',i===choice);el.setAttribute('aria-pressed',String(i===choice));});
      }
      if(next) next.disabled=false;
      status('Answer saved.');
      if(result.feedback) {
        document.querySelector('.quiz-feedback')?.remove();
        const feedback = node('div','quiz-feedback',(result.feedback.correct?'Correct. ':'Not quite. ')+(result.feedback.explanation || ''));
        document.querySelector('.quiz-controls').before(feedback);
      }
    } catch(error) {
      status(error.message,true);
      if(error.status===409){state=await request('state');render();}
    } finally {
      busy=false;
      document.querySelectorAll('.answer').forEach(el=>el.disabled=false);
      const back=document.querySelector('.quiz-controls button'); if(back) back.disabled=!state.settings.allow_back || current===0;
      const nextButton=document.getElementById('quiz-next'); if(nextButton) nextButton.disabled=state.questions[current].answer===null;
    }
  }
  async function flag() {
    if(finished || Date.now()-lastFlag<5000 || document.hidden===false) return;
    lastFlag=Date.now();
    try {
      const result=await enqueue('flag');
      if(result.ended){state=await enqueue('state');showEnd();}
      else status(`Focus change recorded (${result.count}). Please stay on this page.`,true);
    } catch(error) { status('Focus signal could not be saved. Your quiz time continues.',true); }
  }
  document.addEventListener('visibilitychange',flag);
  window.addEventListener('beforeunload',event=>{if(!finished && busy){event.preventDefault();event.returnValue='';}});
  request('state').then(data=>{
    state=data; offset=state.server_time-Math.floor(Date.now()/1000);
    const unanswered=state.questions.findIndex(q=>q.answer===null);
    current=state.settings.allow_back?0:(unanswered<0?state.total-1:unanswered);
    render(); if(state.status==='active' && state.expires_at) clock=setInterval(updateClock,500);
  }).catch(error=>{
    root.replaceChildren(); const panel=node('section','panel quiz-loading');
    panel.append(node('h1',null,'Let’s reconnect.'),node('p',null,error.message));
    const retry=node('button','button','Try again');retry.addEventListener('click',()=>location.reload());panel.append(retry);root.append(panel);
  });
})();

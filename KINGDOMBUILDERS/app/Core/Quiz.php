<?php
declare(strict_types=1);
namespace KB;

final class Quiz {
    public static function settingsSnapshot(): array {
        $keys = ['questions_per_attempt','duration_minutes','seconds_per_question','timer_enabled','pass_mark','randomize_questions','randomize_answers','allow_back','practice_feedback','review_questions','max_tab_switches'];
        return array_intersect_key(Settings::all(),array_flip($keys));
    }
    public static function uuid(): string {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
    }
    public static function start(int $userId): string {
        if (!Settings::get('quiz_open')) throw new UserError('Assessments are currently closed. Please check back later.');
        return DB::transaction(static function () use ($userId): string {
            if (DB::one('SELECT id FROM users WHERE id=? AND status="active" FOR UPDATE', [$userId]) === null) throw new UserError('Account unavailable.',403);
            $settings = self::settingsSnapshot();
            $existing = DB::one('SELECT id FROM attempts WHERE active_user_id=?',[$userId]);
            if ($existing) return $existing['id'];
            // One assessment per member, always: a finished attempt cannot be repeated.
            if (DB::value('SELECT COUNT(*) FROM attempts WHERE user_id=? AND status<>"active"',[$userId])) throw new UserError(self::alreadyAttempted());
            $count = (int)DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1');
            if (!$count) throw new UserError('No questions are available yet. Please contact your administrator.');
            $take = min($count,(int)$settings['questions_per_attempt']);
            $max = (int)DB::value('SELECT MAX(id) FROM questions WHERE is_active=1');
            $ids=[];
            if ($settings['randomize_questions']) {
                // Bounded work: no ORDER BY RAND(), no loading question bodies during sampling.
                // Random pivots are cheap but collide on a sparse bank, so the
                // attempt count scales with the number of questions requested.
                for ($i=0; $i<max($take*6,200) && count($ids)<$take; $i++) {
                    $pivot=random_int(1,max(1,$max));
                    $row=DB::one('SELECT id FROM questions WHERE is_active=1 AND id>=? ORDER BY id LIMIT 1 OFFSET '.random_int(0,3),[$pivot]);
                    if (!$row) $row=DB::one('SELECT id FROM questions WHERE is_active=1 ORDER BY id LIMIT 1');
                    if ($row) $ids[(int)$row['id']]=true;
                }
                $ids=array_keys($ids);
            } else $ids=array_column(DB::all('SELECT id FROM questions WHERE is_active=1 ORDER BY id LIMIT ?',[$take]),'id');
            if (count($ids)<$take) $ids=array_column(DB::all('SELECT id FROM questions WHERE is_active=1 ORDER BY id LIMIT ?',[$take]),'id');
            $ids=array_slice(array_values(array_unique(array_map('intval',$ids))),0,$take);
            $in=implode(',',array_fill(0,count($ids),'?'));
            $questions=DB::all("SELECT * FROM questions WHERE id IN ($in)",$ids);
            $byId=array_column($questions,null,'id');
            $id=self::uuid(); $start=time(); $budget=self::budget($settings,$take); $expiry=$settings['timer_enabled'] ? gmdate('Y-m-d H:i:s',$start+$budget) : null;
            DB::run('INSERT INTO attempts(id,user_id,active_user_id,settings_snapshot,total_questions,started_at,expires_at) VALUES (?,?,?,?,?,?,?)',[$id,$userId,$userId,json_encode($settings),$take,gmdate('Y-m-d H:i:s',$start),$expiry]);
            foreach ($ids as $position=>$questionId) {
                $q=$byId[$questionId]; $options=[$q['option_a'],$q['option_b'],$q['option_c'],$q['option_d']]; $order=[0,1,2,3];
                if ($settings['randomize_answers']) { for ($i=3;$i>0;$i--) { $j=random_int(0,$i); [$order[$i],$order[$j]]=[$order[$j],$order[$i]]; } }
                $correct=array_search((int)$q['correct_option'],$order,true);
                DB::run('INSERT INTO attempt_items(attempt_id,position,question_id,question_text,options_snapshot,correct_option,reference,explanation) VALUES (?,?,?,?,?,?,?,?)',[$id,$position,$questionId,$q['question_text'],json_encode(array_map(static fn($i)=>$options[$i],$order)),$correct,$q['reference'],$q['explanation']]);
            }
            Audit::activity('assessment.started','attempt',$id,[],null,'Your Bible quiz has started. Your progress is saved on the server.');
            return $id;
        });
    }
    /**
     * Seconds the member has for the whole attempt.
     *
     * The administrator sets the total budget in minutes (20 by default), so the
     * count and the clock are independent: 50 questions in 20 minutes is 24
     * seconds each, but a bank with fewer questions still gets the full 20
     * minutes. `seconds_per_question` remains the fallback for an attempt that
     * was created before the duration setting existed.
     */
    public static function budget(array $settings, int $questions): int {
        $minutes = (int) ($settings['duration_minutes'] ?? 0);
        if ($minutes > 0) return min(86400, $minutes * 60);
        $pace = max(5, (int) ($settings['seconds_per_question'] ?? 24));
        return min(86400, max(60, $questions * $pace));
    }

    /** Human wording for a remaining time, e.g. "19 minutes". */
    public static function durationLabel(int $seconds): string {
        if ($seconds >= 3600) { $hours = intdiv($seconds, 3600); $minutes = (int) round(($seconds % 3600) / 60); return $hours . ' hour' . ($hours === 1 ? '' : 's') . ($minutes ? ' ' . $minutes . ' minutes' : ''); }
        $minutes = max(1, (int) round($seconds / 60));
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    public static function alreadyAttempted(): string {
        return 'You have already taken this assessment. Each member may take it once, so your first submission stands. '
            . (self::reviewEnabled() ? 'Open your result from the dashboard to review the questions.' : 'Open your result from the dashboard to see your score.');
    }

    /**
     * Whether completed members may see the questions and answers again.
     * Off by default: a finished attempt exposes its score, not its content.
     */
    public static function reviewEnabled(): bool {
        return (bool) Settings::get('review_questions');
    }

    /** The member's unfinished attempt, used by the mobile dock's "Resume quiz" tab. */
    public static function activeId(int $userId): ?string {
        $id = DB::value('SELECT id FROM attempts WHERE active_user_id=? AND status="active"', [$userId]);
        return is_string($id) && $id !== '' ? $id : null;
    }
    public static function owned(string $id, int $userId, bool $lock=false): array {
        if (!preg_match('/^[a-f0-9-]{36}$/',$id)) throw new UserError('Assessment not found.',404);
        $attempt=DB::one('SELECT * FROM attempts WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),[$id,$userId]);
        if (!$attempt) throw new UserError('Assessment not found.',404);
        return $attempt;
    }
    public static function payload(string $id,int $userId): array {
        $a=self::owned($id,$userId);
        if ($a['status']==='active' && $a['expires_at'] && strtotime($a['expires_at'].' UTC')<=time()) { self::finish($id,$userId,true); $a=self::owned($id,$userId); }
        $review=self::reviewEnabled();
        // Once an attempt is finished, question content is only loaded when the
        // administrator allows review. Otherwise it never reaches the browser.
        $settings=json_decode($a['settings_snapshot'],true);
        $items=$a['status']!=='active' && !$review ? [] : DB::all('SELECT ai.id,ai.position,ai.question_text,ai.options_snapshot,ai.answer,ai.is_skipped,ai.reference,q.category FROM attempt_items ai LEFT JOIN questions q ON q.id=ai.question_id WHERE ai.attempt_id=? ORDER BY ai.position',[$id]);
        $public=[];
        foreach ($items as $item) {
            $answered=$item['answer']!==null || (int)$item['is_skipped']===1;
            $public[]=[
                'position'=>(int)$item['position'],
                'question'=>$item['question_text'],
                'options'=>json_decode($item['options_snapshot'],true),
                'answer'=>(int)$item['is_skipped']?-1:($item['answer']===null?null:(int)$item['answer']),
                'reference'=>$item['reference'],
                'category'=>(string)($item['category']??''),
                // "answered" marks the position menu and the progress bar; an
                // answer stays changeable only while the attempt is running.
                'answered'=>$answered,
                // Editable while the attempt runs (subject to the administrator's
                // "allow revisiting" setting); never editable once submitted.
                'editable'=>$a['status']==='active' && ($answered ? $settings['allow_back'] : true),
            ];
        }
        return ['id'=>$id,'status'=>$a['status'],'settings'=>$settings,'questions'=>$public,'can_review'=>$review,'budget_seconds'=>self::budget($settings,(int)$a['total_questions']),'duration_label'=>self::durationLabel(self::budget($settings,(int)$a['total_questions'])),'server_time'=>time(),'expires_at'=>$a['expires_at']?strtotime($a['expires_at'].' UTC'):null,'score'=>(int)$a['score'],'total'=>(int)$a['total_questions'],'percentage'=>(float)$a['percentage'],'tab_switches'=>(int)$a['tab_switches']];
    }
    public static function answer(string $id,int $userId,int $position,int $choice): array {
        if ($choice < -1 || $choice>3) throw new UserError('Invalid answer.');
        return DB::transaction(static function () use ($id,$userId,$position,$choice): array {
            $a=self::owned($id,$userId,true);
            if ($a['status']!=='active') throw new UserError('This assessment has already ended.',409);
            if ($a['expires_at'] && strtotime($a['expires_at'].' UTC')<=time()) throw new UserError('Time has elapsed. Submit your assessment.',409);
            $settings=json_decode($a['settings_snapshot'],true);
            $item=DB::one('SELECT * FROM attempt_items WHERE attempt_id=? AND position=?',[$id,$position]);
            if (!$item) throw new UserError('Question not found.',404);
            $alreadySaved=$item['answer']!==null || (int)$item['is_skipped']===1;
            // A repeated identical request (a network retry) is acknowledged so the
            // client can recover without changing anything.
            $same=$choice<0 ? (int)$item['is_skipped']===1 : ($item['answer']!==null && (int)$item['answer']===$choice);
            if ($alreadySaved && $same) {
                return ['saved'=>true,'changed'=>false,'feedback'=>!empty($settings['practice_feedback'])?['correct'=>$choice===(int)$item['correct_option'],'explanation'=>$item['explanation']]:null];
            }
            if ($alreadySaved) {
                // Changing one's mind is allowed while the attempt is running and
                // inside the time limit. Nothing can be changed after submission.
                // With revisiting switched off, only the question on screen can be
                // changed — you cannot go back to earlier ones.
                if (!$settings['allow_back']) {
                    $next=(int)DB::value('SELECT COALESCE(MIN(position),?) FROM attempt_items WHERE attempt_id=? AND answer IS NULL AND is_skipped=0',[$a['total_questions'],$id]);
                    if ($position!==$next) throw new UserError('Earlier answers cannot be changed in this assessment.',409);
                }
            } elseif (!$settings['allow_back']) {
                $next=(int)DB::value('SELECT COALESCE(MIN(position),?) FROM attempt_items WHERE attempt_id=? AND answer IS NULL AND is_skipped=0',[$a['total_questions'],$id]);
                if ($position!==$next) throw new UserError('Earlier answers cannot be changed in this assessment.',409);
            }
            DB::run('UPDATE attempt_items SET answer=?,is_skipped=?,answered_at=? WHERE id=?',[$choice<0?null:$choice,$choice<0?1:0,now(),$item['id']]);
            return ['saved'=>true,'changed'=>$alreadySaved,'locked'=>false,
                'remaining'=>max(0,($a['expires_at']!==null?strtotime($a['expires_at'].' UTC')-time():0)),
                'feedback'=>!empty($settings['practice_feedback'])?['correct'=>$choice===(int)$item['correct_option'],'explanation'=>$item['explanation']]:null];
        });
    }
    /**
     * End the attempt when a member walks away from the quiz page.
     *
     * The paper is submitted rather than left open, so "one attempt" is literal:
     * leaving the page keeps the answers already saved and uses up the attempt.
     * The clock is still authoritative if they come back before it expires.
     */
    public static function leave(string $id,int $userId): array {
        return DB::transaction(static function () use ($id,$userId): array {
            $a=self::owned($id,$userId,true);
            if ($a['status']!=='active') return ['ended'=>true,'status'=>$a['status']];
            $answered=(int)DB::value('SELECT COUNT(*) FROM attempt_items WHERE attempt_id=? AND (answer IS NOT NULL OR is_skipped=1)',[$id]);
            self::finishLocked($a,'submitted');
            Audit::log('assessment.left','attempt',$id,['answered'=>$answered]);
            return ['ended'=>true,'status'=>'submitted','answered'=>$answered];
        });
    }

    public static function flag(string $id,int $userId): array {
        return DB::transaction(static function () use ($id,$userId): array {
            $a=self::owned($id,$userId,true);
            if ($a['status']!=='active') return ['ended'=>true];
            DB::run('UPDATE attempts SET tab_switches=tab_switches+1 WHERE id=?',[$id]);
            $max=(int)json_decode($a['settings_snapshot'],true)['max_tab_switches'];
            $ended=$max>0 && (int)$a['tab_switches']+1>=$max;
            if ($ended) self::finishLocked($a,'submitted');
            return ['count'=>(int)$a['tab_switches']+1,'ended'=>$ended];
        });
    }
    public static function finish(string $id,int $userId,bool $expired=false): array {
        DB::transaction(static function () use ($id,$userId,$expired): void {
            $a=self::owned($id,$userId,true);
            if ($a['status']==='active') self::finishLocked($a,$expired?'expired':'submitted');
        });
        return self::payload($id,$userId);
    }
    private static function finishLocked(array $a,string $status): void {
        $score=(int)DB::value('SELECT COUNT(*) FROM attempt_items WHERE attempt_id=? AND answer=correct_option',[$a['id']]);
        $percent=round($score/max(1,(int)$a['total_questions'])*100,2);
        DB::run('UPDATE attempts SET status=?,active_user_id=NULL,score=?,percentage=?,submitted_at=? WHERE id=?',[$status,$score,$percent,now(),$a['id']]);
        Audit::activity('assessment.completed','attempt',$a['id'],['score'=>$score,'total'=>(int)$a['total_questions'],'status'=>$status],null,'Your assessment result is ready: '.$score.' out of '.$a['total_questions'].' ('.$percent.'%).');
    }
    public static function finalizeExpired(): int {
        $ids=DB::all('SELECT id,user_id FROM attempts WHERE status="active" AND expires_at<=? ORDER BY expires_at LIMIT 100',[now()]);
        foreach ($ids as $a) self::finish($a['id'],(int)$a['user_id'],true);
        return count($ids);
    }
}

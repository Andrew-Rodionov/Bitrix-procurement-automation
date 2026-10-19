<?php
declare(strict_types=1);
require_once __DIR__.'/mail.php';
require_once __DIR__.'/procurement-store.php';
require_once __DIR__.'/ai-tasks.php';

function procurementSnapshot(array $c,$ch,?callable $request=null):array {
    $raw=($request??'imapRequest')($c,'','STATUS INBOX (UIDVALIDITY UIDNEXT)',$ch);
    if(!preg_match('/UIDVALIDITY\s+(\d+)/i',$raw,$v)||!preg_match('/UIDNEXT\s+(\d+)/i',$raw,$n))throw new RuntimeException('Не удалось определить состояние папки входящих.');
    return ['identity'=>hash('sha256',$c['imap'].'|'.$c['username'].'|'.$c['folder'].'|'.$v[1]),'last_uid'=>max(0,(int)$n[1]-1)];
}
function procurementSender(string $from):string {
    if(preg_match('/<([^<>]+)>/',$from,$m))$from=$m[1];
    $from=strtolower(trim($from));
    return filter_var($from,FILTER_VALIDATE_EMAIL)?$from:'';
}
function procurementSubject(string $subject):string {
    $subject=preg_replace('/^(?:(?:re|fw|fwd|aw|ответ|пересылка)\s*(?:\[\d+\])?\s*:\s*)+/iu','',trim($subject));
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$subject)),'UTF-8');
}
function procurementMatch(array $headers,int $uid,array $campaigns,string $own):?array {
    if(strtolower($headers['auto-submitted']??'no')!=='no'||preg_match('/\b(?:bulk|list|junk)\b/i',$headers['precedence']??''))return null;
    $sender=procurementSender(mailDecodeHeader($headers['from']??''));
    if($sender===''||$sender===strtolower($own))return null;
    $subject=mailDecodeHeader($headers['subject']??'');
    $references=($headers['in-reply-to']??'').' '.($headers['references']??'');
    preg_match_all('/<[^<>\s]+>/',$references,$matches);$refs=$matches[0];
    $weak=[];
    foreach(array_reverse($campaigns,true) as $id=>$campaign){
        if($campaign['status']==='draft'||$uid<=($campaign['uid_min']??0))continue;
        foreach($campaign['deliveries'] as $delivery){
            if(!in_array($delivery['status'],['sent','unknown'],true))continue;
            if(!empty($delivery['message_id'])&&in_array($delivery['message_id'],$refs,true))return ['campaign'=>$id,'supplier'=>$delivery['address'],'match'=>'thread'];
        }
        foreach($campaign['messages'] as $message)
            if(!empty($message['message_id'])&&in_array($message['message_id'],$refs,true))return ['campaign'=>$id,'supplier'=>$message['supplier'],'match'=>'thread'];
        // A matching subject must be an actual reply, not just any unrelated Re:.
        if(preg_match('/^\s*(?:re|aw|ответ)\s*(?:\[\d+\])?\s*:/iu',$subject)&&procurementSubject($subject)===procurementSubject($campaign['subject'])){
            return ['campaign'=>$id,'supplier'=>$sender,'match'=>'subject'];
        }
        foreach($campaign['deliveries'] as $delivery)
            if(in_array($delivery['status'],['sent','unknown'],true)&&strtolower($delivery['address'])===$sender)
                $weak[]=['campaign'=>$id,'supplier'=>$delivery['address'],'match'=>'sender'];
    }
    if(!$weak)return null;
    $result=$weak[0];$result['ambiguous']=count(array_unique(array_column($weak,'campaign')))>1;
    return $result;
}
function procurementDraft(array $c,string $brief,?callable $compose=null):array {
    if(trim($brief)===''||strlen($brief)>16000)throw new RuntimeException('Опишите закупку: от 1 до 16 КБ текста.');
    return procurementLock($c,static function(array &$state,callable $save)use($brief,$compose):array{
        $data=($compose??'composeProcurement')($brief);$id=bin2hex(random_bytes(8));
        $state['campaigns'][$id]=['brief'=>$brief,'subject'=>$data['result']['subject'],'body'=>$data['result']['body'],
            'created_at'=>time(),'sent_at'=>null,'status'=>'draft','suppliers'=>[],'deliveries'=>[],'messages'=>[],
            'ranking'=>null,'ranking_error'=>null,'ranking_dirty'=>false,'ranking_next'=>0,'compose_meta'=>array_diff_key($data,['result'=>true])];
        $save();return ['campaign'=>['id'=>$id]+$state['campaigns'][$id]];
    });
}
function procurementSend(array $c,string $id,array $suppliers,string $subject,string $body,?callable $snapshot=null,?callable $send=null):array {
    $allowed=array_map('strtolower',procurementSuppliers());
    $suppliers=mailRecipients(implode(';',$suppliers));
    foreach($suppliers as $supplier)if(!in_array(strtolower($supplier),$allowed,true))throw new RuntimeException('Выберите поставщиков из suppliers.txt.');
    $subject=trim($subject);$body=trim($body);
    if($subject===''||strlen($subject)>400||preg_match('/[\r\n]/',$subject)||$body===''||strlen($body)>24000)throw new RuntimeException('Проверьте тему и текст письма: до 400 байт и 24 КБ соответственно.');
    return procurementLock($c,static function(array &$state,callable $save)use($c,$id,$suppliers,$subject,$body,$snapshot,$send):array{
        if(!isset($state['campaigns'][$id]))throw new RuntimeException('Запрос закупки не найден.');
        $campaign=&$state['campaigns'][$id];
        if($campaign['status']!=='draft'&&$campaign['status']!=='sending')return ['deliveries'=>$campaign['deliveries'],'repeated'=>true];
        if($campaign['status']==='draft'){
            $snapshot??=static function(array $c):array{$ch=curl_init();try{return procurementSnapshot($c,$ch);}finally{curl_close($ch);}};
            $start=$snapshot($c);
            if($state['mailbox']!==$start['identity']){$state['mailbox']=$start['identity'];$state['cursor']=$start['last_uid'];}
            $campaign['uid_min']=$start['last_uid'];
            $campaign['subject']=$subject.' [ЗКП-'.$id.']';$campaign['body']=$body;$campaign['suppliers']=$suppliers;
            $campaign['send_token']=bin2hex(random_bytes(24));$campaign['sent_at']=time();$campaign['status']='sending';
            // Save intent before the first SMTP operation so interrupted submissions reuse the same journal.
            $campaign['deliveries']=array_map(static fn($address)=>['address'=>$address,'status'=>'unknown','message_id'=>null],$suppliers);
            $save();
        }
        $result=($send??'outgoingSend')($c,$campaign['suppliers'],$campaign['subject'],$campaign['body'],$campaign['send_token']);
        $campaign['deliveries']=$result['deliveries'];$campaign['status']='sent';
        $state['next_check']=time();$save();return $result;
    });
}
function procurementScan(array $c,?callable $request=null):array {
    $request??='imapRequest';
    return procurementLock($c,static function(array &$state,callable $save)use($c,$request):array{
        $active=array_filter($state['campaigns'],static fn($campaign)=>$campaign['status']!=='draft');
        if(!$active){$state['last_check']=time();$state['next_check']=time()+3600;$save();return ['found'=>0,'message'=>'Сначала отправьте запрос поставщикам.'];}
        $ch=curl_init();$found=0;
        try{
            $snapshot=procurementSnapshot($c,$ch,$request);
            if($state['mailbox']!==$snapshot['identity']){
                $state['mailbox']=$snapshot['identity'];$state['cursor']=0;
                foreach($state['campaigns'] as &$campaign)$campaign['uid_min']=0;
                unset($campaign);
            }
            $cursor=(int)($state['cursor']??0);
            $earliest=min(array_column($active,'sent_at'));
            $raw=$request($c,'','UID SEARCH UID '.($cursor+1).':* SINCE '.gmdate('d-M-Y',$earliest),$ch);
            $uids=[];
            if(preg_match('/\* SEARCH([^\r\n]*)/',$raw,$m))foreach(preg_split('/\s+/',trim($m[1])) as $uid)if(ctype_digit($uid)&&(int)$uid>$cursor)$uids[]=(int)$uid;
            sort($uids,SORT_NUMERIC);$batch=array_slice($uids,0,60);
            foreach($batch as $uid){
                $headers=mailHeaders($request($c,';UID='.$uid.';SECTION=HEADER',null,$ch));
                $match=procurementMatch($headers,$uid,$state['campaigns'],$c['address']);
                if($match){
                    try{$message=mailMessage($request($c,';UID='.$uid,null,$ch));}
                    catch(RuntimeException $e){
                        if(!str_contains($e->getMessage(),'5 МБ'))throw $e;
                        $message=['subject'=>mailDecodeHeader($headers['subject']??''),'from'=>mailDecodeHeader($headers['from']??''),
                            'date'=>$headers['date']??'','message_id'=>$headers['message-id']??'','body'=>'','attachments'=>['Письмо превышает 5 МБ']];
                    }
                    $key=hash('sha256',$snapshot['identity'].'|'.$uid);
                    $campaign=&$state['campaigns'][$match['campaign']];
                    // Message-ID deduplication also survives a mailbox UIDVALIDITY reset.
                    $duplicate=false;
                    foreach($campaign['messages'] as $previous)if($message['message_id']!==''&&$previous['message_id']===$message['message_id']&&$previous['body']===$message['body']){$duplicate=true;break;}
                    if(!$duplicate){
                        $message+=['supplier'=>$match['supplier'],'match'=>$match['match'],'ambiguous'=>$match['ambiguous']??false,
                            'received_at'=>time(),'status'=>'pending','analysis'=>null,'analysis_error'=>null,'retry_at'=>0];
                        if(trim($message['body'])===''||strlen($message['body'])>24000){$message['status']='unsupported';$message['analysis_error']=trim($message['body'])===''?'В письме нет текста. Вложения пока не анализируются.':'Текст письма превышает лимит 24 КБ. Требуется отдельная обработка.';}
                        $campaign['messages'][$key]=$message;$found++;
                    }
                    unset($campaign);
                }
                $state['cursor']=$uid;$save();
            }
            $state['check_error']=null;$state['last_check']=time();$state['next_check']=time()+(count($uids)>count($batch)?30:3600);
            $save();return ['found'=>$found,'more'=>count($uids)>count($batch),'message'=>$found?'Новые письма загружены. ИИ выполняет анализ.':'Новых ответов поставщиков нет.'];
        }catch(Throwable $e){$state['check_error']=$e instanceof RuntimeException?$e->getMessage():'Ошибка проверки почты.';$state['next_check']=time()+3600;$save();throw $e;}
        finally{curl_close($ch);}
    });
}
// One analysis task per call. Saved successful results are never sent for analysis again.
function procurementProcess(array $c,?callable $analyze=null,?callable $rank=null):array {
    return procurementLock($c,static function(array &$state,callable $save)use($analyze,$rank):array{
        foreach($state['campaigns'] as &$campaign){
            foreach($campaign['messages'] as &$message){
                if(!in_array($message['status'],['pending','error'],true)||($message['retry_at']??0)>time())continue;
                try{
                    $analysis=($analyze??'analyzeOffer')($message['body']);
                    if(!is_array($analysis['result']??null))throw new RuntimeException($analysis['format_error']??'Не удалось проверить ответ модели.');
                    $message['analysis']=$analysis;$message['status']='analyzed';$message['analysis_error']=null;
                    $campaign['ranking_dirty']=true;$campaign['ranking_next']=0;
                }catch(Throwable $e){$message['status']='error';$message['analysis_error']=$e instanceof RuntimeException?$e->getMessage():'Ошибка анализа.';$message['retry_at']=time()+3600;}
                $save();return ['worked'=>true];
            }
            unset($message);
        }
        unset($campaign);
        foreach($state['campaigns'] as &$campaign){
            if(!$campaign['ranking_dirty']||($campaign['ranking_next']??0)>time())continue;
            try{$campaign['ranking']=($rank??'rankProcurement')($campaign);$campaign['ranking_error']=null;$campaign['ranking_dirty']=false;}
            catch(Throwable $e){$campaign['ranking_error']=$e instanceof RuntimeException?$e->getMessage():'Ошибка сравнения.';$campaign['ranking_next']=time()+3600;}
            $save();return ['worked'=>true];
        }
        return ['worked'=>false];
    });
}
function procurementRetry(array $c):array {
    return procurementLock($c,static function(array &$state,callable $save):array{
        foreach($state['campaigns'] as &$campaign){
            foreach($campaign['messages'] as &$message)if($message['status']==='error')$message['retry_at']=0;
            unset($message);if($campaign['ranking_dirty'])$campaign['ranking_next']=0;
        }
        $save();return ['ready'=>true];
    });
}

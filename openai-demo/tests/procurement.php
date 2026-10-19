<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/config.php';
require dirname(__DIR__).'/src/procurement.php';
function check(bool $ok,string $label):void {if(!$ok)throw new LogicException($label);echo "PASS: $label\n";}
$suppliers=procurementSuppliers();
check(in_array('andryrodi8@gmail.com',$suppliers,true)&&in_array('r0dionov.andrew@yandex.ru',$suppliers,true),'supplier file contains both requested addresses');
$c=['address'=>'test-'.bin2hex(random_bytes(8)).'@example.invalid','imap'=>'test.invalid','username'=>'test','folder'=>'INBOX'];
$path=procurementPath($c);$calls=0;
try{
    $draft=procurementDraft($c,'Нужно два медицинских прибора.',static fn($brief)=>['result'=>['subject'=>'Запрос двух приборов','body'=>'Просим предложение на два прибора.']]);
    $id=$draft['campaign']['id'];
    $snapshot=static fn()=>['identity'=>hash('sha256','test.invalid|test|INBOX|1'),'last_uid'=>10];
    $send=function($config,$addresses,$subject,$body,$token)use(&$calls):array{
        $calls++;check(count($addresses)===2&&str_contains($subject,'[ЗКП-'),'individual recipients retained and campaign marker attached');
        return ['deliveries'=>array_map(static fn($a)=>['address'=>$a,'status'=>'sent','message_id'=>'<'.hash('sha256',$a).'@test.invalid>'],$addresses),'repeated'=>false];
    };
    $r=procurementSend($c,$id,$suppliers,'Запрос двух приборов','Просим предложение на два прибора.',$snapshot,$send);
    $r=procurementSend($c,$id,$suppliers,'Запрос двух приборов','Просим предложение на два прибора.',$snapshot,$send);
    check($calls===1&&$r['repeated'],'repeat submission does not send again');
    $state=procurementRead($path.'/state.json');$campaigns=$state['campaigns'];
    $headers=['from'=>'Supplier <andryrodi8@gmail.com>','subject'=>'Предложение'];
    check(procurementMatch($headers,11,$campaigns,$c['address'])['match']==='sender','known supplier selected without Re');
    check(procurementMatch($headers,10,$campaigns,$c['address'])===null,'old inbox mail excluded');
    check(procurementMatch(['from'=>'other@example.org','subject'=>'Re: Посторонняя тема'],11,$campaigns,$c['address'])===null,'unrelated Re excluded');
    $reply=['from'=>'other@example.org','subject'=>'Re: '.$campaigns[$id]['subject']];
    check(procurementMatch($reply,11,$campaigns,$c['address'])['match']==='subject','matching Re subject accepted from new address');
    $reply['subject']='Other';$reply['in-reply-to']=$r['deliveries'][0]['message_id'];
    check(procurementMatch($reply,11,$campaigns,$c['address'])['supplier']===$suppliers[0],'In-Reply-To maps changed sender to original supplier');
    $headers['auto-submitted']='auto-replied';
    check(procurementMatch($headers,11,$campaigns,$c['address'])===null,'automatic responder excluded');unset($headers['auto-submitted']);
    $headers['from']=$c['address'];check(procurementMatch($headers,11,$campaigns,$c['address'])===null,'own outgoing mail excluded');
    $headers['from']='andryrodi8@gmail.com';
    $raw="From: andryrodi8@gmail.com\r\nSubject: Offer\r\nMessage-ID: <offer-1@test.invalid>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nАппарат А: 100 рублей. Аппарат Б: 200 рублей. Доставка 5 дней.";
    $imap=function($config,$suffix,$command,$ch)use($raw):string{
        if($command!==null&&str_starts_with($command,'STATUS'))return '* STATUS INBOX (UIDVALIDITY 1 UIDNEXT 13)';
        if($command!==null&&str_starts_with($command,'UID SEARCH'))return '* SEARCH 11 12';
        if(str_contains($suffix,'UID=12'))return "From: stranger@example.org\r\nSubject: Re: another thing\r\n\r\nUnrelated";
        return $raw;
    };
    $scan=procurementScan($c,$imap);check($scan['found']===1,'scan imports only matching new mail');
    $scan=procurementScan($c,$imap);check($scan['found']===0,'same UID never imported twice');
    $state=procurementRead($path.'/state.json');check($state['cursor']===12,'unmatched mail advances incremental cursor');
    $messageId=array_key_first($state['campaigns'][$id]['messages']);
    $analysis=['result'=>['summary'=>'Два аппарата','items'=>[['name'=>'Аппарат А','attributes'=>[]],['name'=>'Аппарат Б','attributes'=>[]]],'common_attributes'=>[],'questions'=>[]]];
    $analysisCalls=0;$rankCalls=0;
    $analyze=function($text)use(&$analysisCalls,$analysis):array{$analysisCalls++;return $analysis;};
    $rank=function($campaign)use(&$rankCalls):array{$rankCalls++;return ['result'=>['summary'=>'Сравнение','top'=>[],'limitations'=>[]]];};
    procurementProcess($c,$analyze,$rank);procurementProcess($c,$analyze,$rank);$idle=procurementProcess($c,$analyze,$rank);
    check($analysisCalls===1&&$rankCalls===1&&!$idle['worked'],'analyze once then rank once, idle calls do not use AI');
    $state=procurementRead($path.'/state.json');$source=$state['campaigns'][$id]['messages'][$messageId];
    check(count($source['analysis']['result']['items'])===2,'all product analyses retained');
    $chain=$state['campaigns'];$chainHeaders=['from'=>'new-sales@example.org','subject'=>'Уточнение','in-reply-to'=>$source['message_id']];
    check(procurementMatch($chainHeaders,13,$chain,$c['address'])['supplier']===$source['supplier'],'follow-up to supplier response remains in original thread');
    $reset=function($config,$suffix,$command,$ch)use($imap):string{
        if($command!==null&&str_starts_with($command,'STATUS'))return '* STATUS INBOX (UIDVALIDITY 2 UIDNEXT 13)';
        return $imap($config,$suffix,$command,$ch);
    };
    check(procurementScan($c,$reset)['found']===0,'UIDVALIDITY reset deduplicates existing Message-ID and body');
    $ranking=['summary'=>'Топ','top'=>[['supplier'=>$suppliers[0],'reason'=>'Срок известен','facts'=>[['label'=>'Срок','value'=>'5 дней','message_id'=>$messageId,'quote'=>'Доставка 5 дней.']],'risks'=>[]]],'limitations'=>[]];
    check(count(validateRanking($ranking,[$messageId=>$source])['top'])===1,'ranking accepts fewer than five suppliers with exact evidence');
    $bad=$ranking;$bad['top'][0]['facts'][0]['quote']='Доставка 1 день.';
    try{validateRanking($bad,[$messageId=>$source]);throw new LogicException('fabrication accepted');}catch(RuntimeException $e){check(true,'fabricated quote rejected');}
    $bad=$ranking;$bad['top'][0]['supplier']='invented@example.org';
    try{validateRanking($bad,[$messageId=>$source]);throw new LogicException('unknown supplier accepted');}catch(RuntimeException $e){check(true,'unknown supplier rejected');}
    check(validateRanking(['summary'=>'Нет предложений','top'=>[],'limitations'=>[]],[])['top']===[],'empty ranking supported');
    $state['campaigns'][$id]['messages'][$messageId]['status']='pending';procurementWrite($path.'/state.json',$state);
    procurementProcess($c,static fn()=>throw new RuntimeException('HTTP 500 test'),$rank);
    $state=procurementRead($path.'/state.json');check($state['campaigns'][$id]['messages'][$messageId]['status']==='error'&&$state['campaigns'][$id]['messages'][$messageId]['analysis']!==null,'AI failure persists error and preserves previous analysis');
    $before=$analysisCalls;procurementProcess($c,$analyze,$rank);check($analysisCalls===$before,'AI errors delayed for an hour to avoid repeated quota use');
    procurementRetry($c);procurementProcess($c,$analyze,$rank);check($analysisCalls===$before+1,'manual retry processes failed mail');
    $auth=['address'=>$c['address'],'password'=>'synthetic-test-password'];$encrypted=procurementSeal($auth);
    check(!str_contains(json_encode($encrypted),$auth['password'])&&procurementUnseal($encrypted)===$auth,'background credentials encrypted and recoverable');
    $encrypted['tag']=base64_encode(random_bytes(16));
    try{procurementUnseal($encrypted);throw new LogicException('tampering accepted');}catch(RuntimeException $e){check(true,'modified credential envelope rejected');}
    $public=procurementPublic(procurementRead($path.'/state.json'));
    check(!isset($public['campaigns'][0]['send_token'])&&!isset($public['campaigns'][0]['messages'][0]['body']),'status response omits send tokens and full originals');
    check(procurementAccount($c)!==procurementAccount(array_replace($c,['address'=>'other@example.invalid'])),'different mailboxes use isolated storage');
    $authPath=$path.'/auth.json';procurementWrite($authPath,procurementSeal($auth));
    procurementDisconnectAccount(procurementAccount($c));check(!is_file($authPath),'logout deletes background credential envelope');
    $lock=fopen($path.'/account.lock','c');flock($lock,LOCK_EX);
    check(procurementProcess($c,$analyze,$rank)['busy']===true,'concurrent worker and web analysis cannot duplicate calls');flock($lock,LOCK_UN);fclose($lock);
    $schema=aiObject(['subject'=>['type'=>'string'],'body'=>['type'=>'string']]);
    check(aiTaskDecode('```json'."\n".'{"subject":"Test","body":"Body"}'."\n".'```',$schema)['subject']==='Test','generated JSON supports fenced replies');
    foreach(['{"subject":"Test"}','{"subject":2,"body":"Body"}','{"subject":"Test","body":"Body","extra":1}'] as $invalid){
        try{aiTaskDecode($invalid,$schema);throw new LogicException('invalid shape accepted');}catch(RuntimeException $e){check(true,'invalid generated JSON shape rejected');}
    }
    $comparison=$state['campaigns'][$id];
    $comparison['messages'][$messageId]['status']='analyzed';
    $comparison['messages'][$messageId]['analysis']['result']['items'][0]['attributes']=[['label'=>'Описание','value'=>'Экран 23,8"','quote'=>'Аппарат А: 100 рублей.']];
    $other=$comparison['messages'][$messageId];$other['supplier']=$suppliers[1];$other['body']='Другая поставка, 300 рублей.';
    $other['analysis']['result']['items']=[];$comparison['messages']['other-source']=$other;
    $context=rankingContext($comparison);
    $generate=static function($instruction,$input,$schema,$name):array{
        check(!isset($schema['properties']['top']['items']['properties']['facts']),'ranking model no longer rewrites values and quotes');
        $supplier=$input['suppliers'][0];
        return ['result'=>['summary'=>'Сравнение','top'=>[['supplier_id'=>$supplier['supplier_id'],'reason'=>'Условия известны',
            'fact_ids'=>[$supplier['messages'][0]['available_facts'][0]['fact_id']],'risks'=>[]]],'limitations'=>[]]];
    };
    $ranked=rankProcurement($comparison,$generate);
    check($ranked['result']['top'][0]['facts'][0]['value']==='Экран 23,8"'&&$ranked['result']['top'][0]['facts'][0]['message_id']===$messageId,'compact selection restores exact values, inch quotes and source ids on server');
    $foreign=$context['input']['suppliers'][1]['messages'][0]['available_facts'][0]['fact_id'];
    try{rankProcurement($comparison,static fn()=>['result'=>['summary'=>'Test','top'=>[['supplier_id'=>'s1','reason'=>'Test','fact_ids'=>[$foreign],'risks'=>[]]],'limitations'=>[]]]);throw new LogicException('cross-supplier fact accepted');}
    catch(RuntimeException $e){check(true,'compact ranking rejects fact from different supplier');}
    $comparison['messages'][$messageId]['attachments']=['old.png','offer.pdf'];
    $visible=procurementPublic(['campaigns'=>['test'=>$comparison],'last_check'=>null,'next_check'=>null,'check_error'=>null]);
    check($visible['campaigns'][0]['messages'][0]['attachments']===['offer.pdf'],'already saved images excluded from public status');
}finally{
    foreach(glob($path.'/*') as $file)if(is_file($file))unlink($file);
    rmdir($path);
}

<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/client.php';
$schema=offerSchema();
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException("FAIL: $name");echo "PASS: $name\n";}
function parse(array $data):array{global $schema;return parseOffer(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$schema);}
function rejects(string $text,string $name):void{global $schema;try{parseOffer($text,$schema);}catch(RuntimeException $e){check(true,$name);return;}check(false,$name);}
$base=['summary'=>'Оборудование','items'=>[
 ['name'=>'УЗИ','attributes'=>[['label'=>'Оплата','value'=>'50% аванс','quote'=>null],['label'=>'Обучение','value'=>'Два специалиста','quote'=>null]]],
 ['name'=>'ЭКГ','attributes'=>[['label'=>'Оплата','value'=>'100% предоплата','quote'=>null]]],
 ['name'=>'Монитор','attributes'=>[['label'=>'Количество','value'=>'3','quote'=>null]]]
],'common_attributes'=>[['label'=>'Разгрузка','value'=>'Заказчиком','quote'=>null]],'questions'=>[]];
check(parse($base)===$base,'three items and arbitrary attributes preserved');
$v=$base;$v['items']=[$v['items'][0]];check(count(parse($v)['items'])===1,'single item works');
$v=$base;$v['items'][2]['attributes'][0]['value']=3;check(parse($v)['items'][2]['attributes'][0]['value']==='3','numeric value normalized');
$v=$base;$v['items'][0]['special_terms']='Датчики отдельно';check(parse($v)['items'][0]['attributes'][2]['value']==='Датчики отдельно','unexpected item fields retained');
$v=$base;$v['valid_until']='10 дней';check(parse($v)['common_attributes'][1]['value']==='10 дней','unexpected common fields retained with warning');
$legacy=['summary'=>'Поставка','product'=>[
 ['name'=>'УЗИ','quantity'=>'1','payment'=>'50%','warranty'=>'12 месяцев'],
 ['name'=>'ЭКГ','quantity'=>'2','payment'=>'100%','warranty'=>'24 месяца'],
 ['name'=>'Монитор','quantity'=>'3','payment'=>'50%','warranty'=>'12 месяцев']
],'quantity'=>null,'payment'=>null,'vat'=>'без НДС','questions'=>[],'evidence'=>[]];
$r=parse($legacy);
check(count($r['items'])===3 && $r['items'][1]['attributes'][1]['value']==='100%','reported product-array regression');
check($r['common_attributes'][0]['value']==='без НДС','common VAT stays separate');
$r=parse(['summary'=>'Стул','product'=>'Стул','quantity'=>'20','questions'=>[],'evidence'=>[]]);
check(count($r['items'])===1 && $r['items'][0]['attributes'][0]['value']==='20','old single-item format');
check(parseOffer("```json\n".json_encode($base)."\n```",$schema)===$base,'markdown fence');
$v=$base;$v['items']=[];check(isset(parse($v)['_warnings']),'empty items explicitly warned');
$v=$base;$v['items'][0]['name']=[];rejects(json_encode($v),'invalid product name rejected');
$v=$base;$v['items'][0]['attributes']=[['value'=>'no label']];rejects(json_encode($v),'malformed attribute rejected');
$v=$base;$v['questions']=[['invalid']];rejects(json_encode($v),'invalid question rejected');
rejects('{"summary":"test"}','unrelated object rejected');
rejects('[1,2]','array root rejected');
rejects('{"summary":"unfinished"','truncated JSON rejected');
$calls=0;$valid=json_encode($base);$broken='{"summary":"Кресло "Комфорт""}';
$r=resolveOffer($valid,$schema,function()use(&$calls){$calls++;throw new RuntimeException('unexpected');});
check($calls===0 && $r['result']===$base,'no repair for valid result');
$r=resolveOffer($broken,$schema,function()use(&$calls,$valid){$calls++;return $valid;});
check($calls===1 && count($r['result']['items'])===3 && $r['repaired'],'repair preserves all items');
$calls=0;$r=resolveOffer($broken,$schema,function()use(&$calls){$calls++;return 'invalid';});
check($calls===1 && $r['result']===null && $r['raw_text']===$broken,'failed repair preserves original');
$r=resolveOffer($broken,$schema,function(){throw new RuntimeException('HTTP 429');});
check($r['result']===null,'repair network failure safe');

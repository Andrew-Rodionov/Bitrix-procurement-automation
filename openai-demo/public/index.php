<?php
require dirname(__DIR__).'/src/config.php';
sessionInit();
header('Cache-Control: no-store');
$ready=setting('API_KEY')!=='';
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Закупки · AI-помощник</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f4;color:#192b37;font-family:Segoe UI,Arial,sans-serif}main{max-width:1160px;margin:48px auto;padding:0 24px}.brand{font-size:13px;font-weight:700;letter-spacing:2px;color:#426a78}h1{font-size:38px;letter-spacing:-1px;margin:18px 0 10px}p{line-height:1.6;color:#5d707d}.badge{display:inline-block;padding:7px 12px;border-radius:20px;background:#ddebe9;color:#246259;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:28px}.card{background:white;border:1px solid #dfe7eb;border-radius:18px;padding:26px}h2{margin:0 0 18px;font-size:21px}label{display:block;margin-bottom:10px;font-size:14px;color:#5d707d}textarea{width:100%;height:320px;resize:vertical;padding:16px;border:1px solid #cdd9df;border-radius:10px;font:15px/1.65 Segoe UI,Arial}textarea:focus{outline:2px solid #2d807b}button{border:0;border-radius:9px;background:#216e68;color:white;padding:13px 19px;font:600 14px Segoe UI;cursor:pointer;margin-top:16px}button:disabled{opacity:.6;cursor:wait}.secondary{background:#eef3f5;color:#526771;margin-left:6px}.note{font-size:12px}.empty{padding:70px 12px;text-align:center;color:#70838e}.symbol{font-size:36px;color:#398b82}.error{background:#fff0ec;border:1px solid #f4c9bd;border-radius:10px;color:#913f2d;padding:14px;line-height:1.5;margin-top:16px}table{border-collapse:collapse;width:100%;font-size:14px}td{padding:11px 0;border-bottom:1px solid #edf1f3;vertical-align:top}td:first-child{width:43%;color:#627580;padding-right:12px}.missing{color:#a47134}li{margin-bottom:9px;line-height:1.5;font-size:14px}h3{font-size:16px;margin-top:24px}details{margin-top:20px;font-size:14px}blockquote{margin:10px 0;padding-left:12px;border-left:3px solid #cee1dc;color:#5d707d}.meta{font-size:12px;color:#6f8592;margin-top:22px}@media(max-width:760px){main{margin-top:28px}.grid{grid-template-columns:1fr}h1{font-size:30px}.card{padding:20px}}
</style><main><div class="brand">ЗАКУПКИ / ЛАБОРАТОРИЯ</div><h1>Из письма — в условия поставки</h1><p>Первый прототип ИИ-помощника для будущего модуля Битрикс24.</p>
<span class="badge"><?= $ready?'Ключ настроен · доступ проверяется при запросе':'Настройка: добавьте API_KEY в .env' ?></span>
<div class="grid"><section class="card"><h2>01 / Письмо поставщика</h2>
<form id="form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">
<label for="letter">Вставьте текст предложения или используйте пример</label>
<textarea id="letter" name="letter" required maxlength="12000">Добрый день!
Готовы поставить 20 офисных кресел «Комфорт» по 12 500 рублей за штуку, НДС включён.
Доставка до вашего склада в Москве — 6 000 рублей.
Срок поставки — 10 рабочих дней после предоплаты.
Условия оплаты: 50% аванс, остаток после получения товара.
С уважением, отдел продаж.</textarea>
<button id="submit" type="submit">Разобрать предложение →</button><button class="secondary" id="sample" type="button">Пример</button>
<div id="error" class="error" hidden role="alert"></div><p class="note">Текст отправляется в выбранный ИИ-сервис. Анализ расходует квоту: один запрос, при ошибке формата — ещё один для исправления. Для первого теста используйте этот вымышленный пример.</p></form></section>
<section class="card" aria-live="polite"><h2>02 / Результат анализа</h2><div id="empty" class="empty"><div class="symbol">✧</div><p>Здесь появятся условия поставки<br>и вопросы для уточнения.</p></div><div id="result" hidden></div></section></div>
<p class="note">Анализ предложения → условия поставки → вопросы для уточнения. Результат требует проверки человеком.</p></main>
<script>
const form=document.querySelector('#form'),button=document.querySelector('#submit'),error=document.querySelector('#error'),result=document.querySelector('#result'),empty=document.querySelector('#empty'),letter=document.querySelector('#letter');
const sample=letter.value;
document.querySelector('#sample').onclick=()=>letter.value=sample;
function el(tag,text,parent){const n=document.createElement(tag);n.textContent=text;parent.append(n);return n;}
function rawText(text,parent){
    const n=el('pre',text,parent);
    n.style.whiteSpace='pre-wrap';n.style.overflowWrap='anywhere';n.style.font='14px/1.6 Segoe UI,Arial';
}
function attributesTable(attributes,parent){
    const table=el('table','',parent);
    for(const attribute of attributes){
        const row=el('tr','',table);el('td',attribute.label,row);
        const cell=el('td',attribute.value,row);
        if(attribute.quote){
            const source=el('details','',cell);el('summary','Цитата',source);
            el('blockquote',attribute.quote,source);
        }
    }
}
function render(data){
    result.replaceChildren();
    if(data.result===null){
        el('p',data.warning,result).className='error';rawText(data.raw_text,result);
        const details=el('details','',result);el('summary','Почему результат не разобран',details);
        el('p',data.format_error,details);el('p',data.repair_error,details);
    } else {
        const offer=data.result;
        for(const warning of offer._warnings??[])el('p',warning,result).className='error';
        el('p',offer.summary,result);
        el('h3','Найдено позиций: '+offer.items.length,result);
        offer.items.forEach((item,index)=>{
            const section=el('section','',result);
            el('h3',(index+1)+'. '+item.name,section);
            if(item.attributes.length)attributesTable(item.attributes,section);
            else el('p','Дополнительные сведения не извлечены.',section);
        });
        if(offer.common_attributes.length){
            el('h3','Общие условия и дополнительные сведения',result);
            attributesTable(offer.common_attributes,result);
        }
        el('h3','Что уточнить у поставщика',result);
        if(offer.questions.length){
            const ul=el('ul','',result);offer.questions.forEach(q=>el('li',q,ul));
        }else el('p','Модель не предложила дополнительных вопросов.',result);
        if(data.repaired){
            const original=el('details','',result);el('summary','Ответ до исправления формата',original);
            rawText(data.raw_text,original);
        }
    }
    el('div',data.model+' · '+data.seconds+' с · '+(data.tokens??'—')+' токенов · запросов: '+(data.requests??1),result).className='meta';
    empty.hidden=true;result.hidden=false;
}

async function readApiResponse(response){
    const text=await response.text();
    const context='HTTP '+response.status;
    if(!text.trim())throw new Error('Сервер вернул пустой ответ ('+context+'). Обновите страницу по адресу http://127.0.0.1:8088 и повторите запрос.');
    let data;
    try{data=JSON.parse(text);}catch{
        throw new Error('Ответ сервера оборван или имеет неверный формат ('+context+'). Проверьте журнал PHP и адрес страницы: http://127.0.0.1:8088.');
    }
    if(!data || typeof data!=='object')throw new Error('Сервер вернул неожиданный ответ ('+context+').');
    if(!response.ok)throw new Error(data.error||'Ошибка сервера ('+context+').');
    if(!Object.hasOwn(data,'result'))throw new Error(data.error||'Сервер не вернул результат анализа.');
    return data;
}

form.onsubmit=async(event)=>{
    event.preventDefault();button.disabled=true;button.textContent='Анализируем…';
    error.hidden=true;result.hidden=true;empty.hidden=false;
    empty.textContent='Модель читает письмо. При необходимости формат ответа будет исправлен…';
    try{
        const response=await fetch('analyze.php',{method:'POST',body:new FormData(form)});
        const data=await readApiResponse(response);
        if(!response.ok)throw new Error(data.error||'Не удалось выполнить запрос.');
        render(data);
    }catch(e){
        error.textContent=e.message;error.hidden=false;
        empty.textContent='Не удалось получить результат. Исправьте причину и повторите запрос.';
    }finally{button.disabled=false;button.textContent='Разобрать предложение →';}
};
</script></html>

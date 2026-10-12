<?php
require dirname(__DIR__).'/src/config.php';
sessionInit();
$sendToken=bin2hex(random_bytes(24));
$_SESSION['send_tokens'][]=$sendToken;
$_SESSION['send_tokens']=array_slice($_SESSION['send_tokens'],-10);
header('Cache-Control: no-store');
$mailConnected=isset($_SESSION['mail_auth']);
$ready=setting('API_KEY')!=='';
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Закупки · AI-помощник</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f4;color:#192b37;font-family:Segoe UI,Arial,sans-serif}main{max-width:1160px;margin:48px auto;padding:0 24px}.brand{font-size:13px;font-weight:700;letter-spacing:2px;color:#426a78}h1{font-size:38px;letter-spacing:-1px;margin:18px 0 10px}p{line-height:1.6;color:#5d707d}.badge{display:inline-block;padding:7px 12px;border-radius:20px;background:#ddebe9;color:#246259;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-top:28px}.card{background:white;border:1px solid #dfe7eb;border-radius:18px;padding:26px}h2{margin:0 0 18px;font-size:21px}label{display:block;margin-bottom:10px;font-size:14px;color:#5d707d}textarea{width:100%;height:320px;resize:vertical;padding:16px;border:1px solid #cdd9df;border-radius:10px;font:15px/1.65 Segoe UI,Arial}textarea:focus{outline:2px solid #2d807b}button{border:0;border-radius:9px;background:#216e68;color:white;padding:13px 19px;font:600 14px Segoe UI;cursor:pointer;margin-top:16px}button:disabled{opacity:.6;cursor:wait}.secondary{background:#eef3f5;color:#526771;margin-left:6px}.note{font-size:12px}.empty{padding:70px 12px;text-align:center;color:#70838e}.symbol{font-size:36px;color:#398b82}.error{background:#fff0ec;border:1px solid #f4c9bd;border-radius:10px;color:#913f2d;padding:14px;line-height:1.5;margin-top:16px}table{border-collapse:collapse;width:100%;font-size:14px}td{padding:11px 0;border-bottom:1px solid #edf1f3;vertical-align:top}td:first-child{width:43%;color:#627580;padding-right:12px}.missing{color:#a47134}li{margin-bottom:9px;line-height:1.5;font-size:14px}h3{font-size:16px;margin-top:24px}details{margin-top:20px;font-size:14px}blockquote{margin:10px 0;padding-left:12px;border-left:3px solid #cee1dc;color:#5d707d}.meta{font-size:12px;color:#6f8592;margin-top:22px}@media(max-width:760px){main{margin-top:28px}.grid{grid-template-columns:1fr}h1{font-size:30px}.card{padding:20px}}
</style><main><div class="brand">ЗАКУПКИ / ЛАБОРАТОРИЯ</div><h1>Из письма — в условия поставки</h1><p>Первый прототип ИИ-помощника для будущего модуля Битрикс24.</p>
<span class="badge"><?= $ready?'Ключ настроен · доступ проверяется при запросе':'Настройка: добавьте API_KEY в .env' ?></span>
<section class="card" style="margin-top:24px">
<h2>Почта помощника</h2>
<div id="mail-login-area" <?= $mailConnected?'hidden':'' ?>>
<form id="mail-login-form" style="max-width:540px">
<label for="mail-address">Адрес ящика помощника</label>
<input id="mail-address" name="address" type="email" required autocomplete="username" placeholder="имя@citology.ru" style="width:100%;padding:12px;border:1px solid #cdd9df;border-radius:9px">
<label for="mail-password" style="margin-top:12px">Пароль почтового подключения</label>
<input id="mail-password" name="password" type="password" required autocomplete="current-password" style="width:100%;padding:12px;border:1px solid #cdd9df;border-radius:9px">
<button id="mail-login-button" type="submit">Войти в почту</button>
<p class="note">После входа данные хранятся в закрытой сессии на сервере. Выход удаляет их из сессии. После 30 минут бездействия понадобится войти снова.</p>
</form>
</div>
<div id="mail-account" <?= $mailConnected?'':'hidden' ?>>
<span id="mail-account-address"><?= htmlspecialchars($_SESSION['mail_auth']['address']??'',ENT_QUOTES,'UTF-8') ?></span>
<button id="mail-logout" type="button" class="secondary">Выйти из почты</button>
</div>

<p class="note">Входящие обрабатываются вручную. Проверка почты не запускает ИИ. Показываются последние 20 писем папки «Входящие».</p>
<button id="mail-refresh" type="button" <?= $mailConnected?'':'disabled' ?>>Проверить почту</button>
<span id="mail-status" class="note" role="status"></span>
<div id="mail-error" class="error" hidden role="alert"></div>
<div id="inbox" style="margin-top:16px"></div>
<p id="selected-mail" class="note"></p>
</section>
<section class="card" style="margin-top:24px">
<h2>Отправить запрос поставщикам</h2><button id="smtp-check" type="button" class="secondary">Проверить SMTP без отправки</button><p id="smtp-check-result" role="status"></p>
<form id="send-form">
<input type="hidden" name="send_token" id="send-token" value="<?= htmlspecialchars($sendToken,ENT_QUOTES,'UTF-8') ?>">
<label for="recipients">Адреса получателей — через запятую, точку с запятой или с новой строки</label>
<textarea id="recipients" name="recipients" required style="height:75px" placeholder="supplier1@example.org; supplier2@example.org"></textarea>
<label for="mail-subject" style="margin-top:14px">Тема</label>
<input id="mail-subject" name="subject" type="text" required maxlength="250" style="width:100%;padding:12px;border:1px solid #cdd9df;border-radius:9px" placeholder="Запрос коммерческого предложения">
<label for="outgoing" style="margin-top:14px">Текст письма — пишет сотрудник</label>
<textarea id="outgoing" name="body" required maxlength="12000" style="height:190px" placeholder="Добрый день! Просим направить предложение..."></textarea>
<button id="send-button" type="submit" <?= $mailConnected?'':'disabled' ?>>Отправить письма</button>
<button class="secondary" type="button" id="new-send" hidden>Новое письмо</button>
<p class="note">Каждому адресату отправляется отдельное письмо. Другие получатели ему не видны. Неопределённый результат отправки не повторяется автоматически.</p>
<div id="send-error" class="error" hidden role="alert"></div>
<div id="send-status" aria-live="polite"></div>
</form></section>

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
<div id="error" class="error" hidden role="alert"></div><p class="note">Текст отправляется в выбранный ИИ-сервис. Анализ расходует квоту. При временном сбое возможен один повтор, при ошибке формата — один запрос исправления. Для первого теста используйте этот вымышленный пример.</p></form></section>
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

const mailRefresh=document.querySelector('#mail-refresh'),mailError=document.querySelector('#mail-error'),mailStatus=document.querySelector('#mail-status'),inbox=document.querySelector('#inbox'),selected=document.querySelector('#selected-mail');
async function mailRequest(action,values={}){
    const body=new FormData();body.set('csrf',form.elements.csrf.value);body.set('action',action);
    for(const [key,value] of Object.entries(values))body.set(key,value);
    return (await readApiResponse(await fetch('mail.php',{method:'POST',body}))).result;
}
mailRefresh.onclick=async()=>{
    mailRefresh.disabled=true;mailError.hidden=true;mailStatus.textContent='Подключаемся…';
    try{
        const data=await mailRequest('list');inbox.replaceChildren();
        mailStatus.textContent=data.address+' · писем: '+data.total;
        if(!data.messages.length)el('p','Во входящих пока нет писем.',inbox);
        for(const message of data.messages){
            const row=el('div','',inbox);row.style.borderBottom='1px solid #edf1f3';row.style.padding='8px 0';
            el('div',message.from+' · '+message.date,row).className='note';
            const pick=el('button',message.subject||'(без темы)',row);pick.type='button';pick.className='secondary';
            pick.onclick=async()=>{
                pick.disabled=true;mailError.hidden=true;
                try{
                    const email=await mailRequest('read',{uid:message.uid});
                    letter.value=email.body;selected.textContent='Выбрано: '+email.subject+' · '+email.from;
                    if(email.attachments.length)selected.textContent+=' · Вложения не анализируются: '+email.attachments.join(', ');
                    if(new TextEncoder().encode(email.body).length>24000)selected.textContent+=' · Текст длиннее лимита анализа. Сократите его в редакторе.';
                    result.hidden=true;empty.hidden=false;empty.textContent='Письмо загружено. Проверьте текст и нажмите «Разобрать предложение».';
                    letter.scrollIntoView({behavior:'smooth',block:'center'});
                }catch(e){mailError.textContent=e.message;mailError.hidden=false;}
                finally{pick.disabled=false;}
            };
        }
    }catch(e){mailError.textContent=e.message;mailError.hidden=false;mailStatus.textContent='Не подключено';}
    finally{mailRefresh.disabled=false;}
};
const sendForm=document.querySelector('#send-form'),sendButton=document.querySelector('#send-button'),sendError=document.querySelector('#send-error'),sendStatus=document.querySelector('#send-status'),newSend=document.querySelector('#new-send');
sendForm.onsubmit=async(event)=>{
    event.preventDefault();sendButton.disabled=true;sendError.hidden=true;sendStatus.replaceChildren();
    el('p','Отправляем отдельное письмо каждому адресату…',sendStatus);
    try{
        const values=Object.fromEntries(new FormData(sendForm));
        const data=await mailRequest('send',values);sendStatus.replaceChildren();
        if(data.repeated)el('p','Показан сохранённый результат. Повторная отправка не выполнялась.',sendStatus);
        for(const delivery of data.deliveries){
            const sent=delivery.status==='sent';
            el('p',delivery.address+' — '+(sent?'почтовый сервер принял письмо':delivery.status==='failed'?'письмо не отправлено':delivery.status==='not_sent'?'письмо не отправлялось — обработка прервалась':'результат неизвестен. Проверьте почту и настройки; повторная отправка может создать дубликат.'),sendStatus).className=sent?'note':'error';
            if(delivery.error)el('p',delivery.error,sendStatus).className='error';
        }
        newSend.hidden=false;
    }catch(e){sendError.textContent=e.message;sendError.hidden=false;sendButton.disabled=false;}
};
newSend.onclick=()=>{location.reload();};


const loginForm=document.querySelector('#mail-login-form'),loginButton=document.querySelector('#mail-login-button'),logoutButton=document.querySelector('#mail-logout');
loginForm.onsubmit=async(event)=>{
    event.preventDefault();loginButton.disabled=true;mailError.hidden=true;
    try{
        const values=Object.fromEntries(new FormData(loginForm));
        const data=await mailRequest('login',values);
        document.querySelector('#mail-login-area').hidden=true;
        document.querySelector('#mail-account').hidden=false;
        document.querySelector('#mail-account-address').textContent=data.address;
        mailRefresh.disabled=false;sendButton.disabled=false;
        mailStatus.textContent='Вход выполнен. Нажмите «Проверить почту».';
        inbox.replaceChildren();selected.textContent='';
    }catch(e){mailError.textContent=e.message;mailError.hidden=false;}
    finally{document.querySelector('#mail-password').value='';loginButton.disabled=false;}
};
logoutButton.onclick=async()=>{
    logoutButton.disabled=true;mailError.hidden=true;
    try{
        await mailRequest('logout');
        document.querySelector('#mail-login-area').hidden=false;
        document.querySelector('#mail-account').hidden=true;
        document.querySelector('#mail-account-address').textContent='';
        mailRefresh.disabled=true;sendButton.disabled=true;
        inbox.replaceChildren();selected.textContent='';sendStatus.replaceChildren();
        mailStatus.textContent='Вы вышли из почты.';
    }catch(e){mailError.textContent=e.message;mailError.hidden=false;}
    finally{logoutButton.disabled=false;}
};


document.querySelector('#smtp-check').onclick=async()=>{
    const check=document.querySelector('#smtp-check');check.disabled=true;
    const output=document.querySelector('#smtp-check-result');output.textContent='Проверяем соединение и вход…';
    try{const data=await mailRequest('smtp_check');output.textContent=data.message;}
    catch(e){output.textContent=e.message;}
    finally{check.disabled=false;}
};

</script></html>

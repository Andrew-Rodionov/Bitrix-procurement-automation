'use strict';
const boot=JSON.parse(document.querySelector('#boot').textContent);
const $=selector=>document.querySelector(selector);
let connected=boot.connected,activeId='',draftId='',state=null,busy=false,polling=false,processing=false,shuttingDown=false,lastSuppliers='',renderedCampaign='';
function node(tag,text,parent,className=''){const n=document.createElement(tag);n.textContent=text;n.className=className;parent.append(n);return n;}
function showError(id,error){const n=$(id);n.textContent=error?.message||'';n.hidden=!error;}
function date(value){return value?new Date(value*1000).toLocaleString('ru-RU'):'—';}
async function request(endpoint,action,values={}){
    if(shuttingDown&&endpoint!=='shutdown.php')throw new Error('Работа проекта завершается.');
    const body=new FormData();body.set('csrf',boot.csrf);body.set('action',action);
    for(const [key,value] of Object.entries(values))body.set(key,value);
    const response=await fetch(endpoint,{method:'POST',body});const raw=await response.text();
    let data;try{data=JSON.parse(raw);}catch{throw new Error('Сервер вернул неполный ответ. Проверьте журнал PHP.');}
    if(response.status===401){connected=false;accountView();showError('#login-error',new Error(data.error||'Войдите в почту снова.'));}
    if(!response.ok||!Object.hasOwn(data,'result'))throw new Error(data.error||'Не удалось выполнить запрос.');
    return data.result;
}
async function api(action,values={}){const data=await request('procurement.php',action,values);if(data.busy)throw new Error(data.message);return data;}
function accountView(){
    $('#login-form').hidden=connected;$('#account').hidden=!connected;$('#workspace').hidden=!connected;
    $('#address').textContent=boot.address;
}
$('#login-form').onsubmit=async event=>{
    event.preventDefault();const button=event.currentTarget.querySelector('button');button.disabled=true;showError('#login-error',null);
    try{const form=event.currentTarget;const data=await request('mail.php','login',Object.fromEntries(new FormData(form)));
        form.elements.password.value='';connected=true;boot.address=data.address;accountView();await loadStatus();
    }catch(e){showError('#login-error',e);}finally{button.disabled=false;}
};
$('#logout').onclick=async()=>{
    $('#logout').disabled=true;
    try{await request('mail.php','logout');connected=false;state=null;activeId='';draftId='';renderedCampaign='';lastSuppliers='';
        for(const id of ['#brief','#body','#subject'])$(id).value='';
        $('#send-status').replaceChildren();$('#campaign-result').replaceChildren();$('#original-content').replaceChildren();$('#original-dialog').close();accountView();}
    catch(e){showError('#login-error',e);}finally{$('#logout').disabled=false;}
};
function suppliersView(suppliers){
    const fingerprint=JSON.stringify(suppliers);if(lastSuppliers===fingerprint)return;lastSuppliers=fingerprint;
    const list=$('#suppliers');list.replaceChildren();
    if(!suppliers.length)node('p','Добавьте адреса в suppliers.txt.',list,'note');
    for(const address of suppliers){const label=node('label','',list,'supplier');const checkbox=document.createElement('input');
        checkbox.type='checkbox';checkbox.value=address;checkbox.name='supplier';checkbox.checked=true;label.append(checkbox,document.createTextNode(address));}
}
function draftView(campaign){
    if(!campaign||campaign.status!=='draft'){draftId='';$('#send-form').hidden=true;$('#draft-empty').hidden=false;
        $('#draft-empty').textContent=campaign?'Запрос отправлен. Для новой закупки заполните тезисы и сформируйте новое письмо.':'Сначала опишите закупку и сформируйте письмо.';return;}
    if(draftId!==campaign.id){draftId=campaign.id;$('#subject').value=campaign.subject;$('#body').value=campaign.body;$('#brief').value=campaign.brief;
        $('#send-status').replaceChildren();showError('#send-error',null);}
    $('#send-form').hidden=false;$('#draft-empty').hidden=true;
}
function attributes(attributes,parent){
    if(!attributes?.length)return;
    const table=node('table','',parent);
    for(const attr of attributes){const row=node('tr','',table);node('td',attr.label,row);const cell=node('td',attr.value,row);
        if(attr.quote){const details=node('details','',cell);node('summary','Цитата',details);node('blockquote',attr.quote,details);}}
}
function analysisView(analysis,parent){
    const result=analysis?.result;if(!result)return;
    node('p',result.summary,parent);
    for(const warning of result._warnings||[])node('p',warning,parent,'warning');
    for(const item of result.items||[]){node('h3',item.name,parent);attributes(item.attributes,parent);}
    if(result.common_attributes?.length){node('h3','Общие условия',parent);attributes(result.common_attributes,parent);}
    if(result.questions?.length){node('h3','Вопросы для уточнения',parent);const ul=node('ul','',parent);for(const q of result.questions)node('li',q,ul);}
}
async function original(campaignId,messageId){
    try{const message=await api('original',{id:campaignId,message_id:messageId});const target=$('#original-content');target.replaceChildren();
        node('h3',message.subject,target);node('p',message.from+' · '+message.date,target,'note');node('pre',message.body,target);
        if(message.attachments?.length)node('p','Вложения не анализировались: '+message.attachments.join(', '),target,'warning');
        $('#original-dialog').showModal();
    }catch(e){showError('#refresh-error',e);}
}
$('#close-dialog').onclick=()=>$('#original-dialog').close();
function messageButton(parent,campaignId,messageId,label){const button=node('button',label,parent,'secondary');button.type='button';
    button.onclick=()=>original(campaignId,messageId);return button;}
function renderCampaign(campaign){
    const fingerprint=JSON.stringify(campaign);if(renderedCampaign===fingerprint)return;renderedCampaign=fingerprint;
    const target=$('#campaign-result');target.replaceChildren();if(!campaign){node('p','Пока нет запросов закупки.',target,'muted');return;}
    if(campaign.status==='draft'){node('p','Черновик. Проверьте письмо и выберите поставщиков выше.',target,'muted');return;}
    const outgoing=node('details','',target);node('summary','Отправленный запрос и результаты отправки',outgoing);
    node('h3',campaign.subject,outgoing);node('pre',campaign.body,outgoing);
    for(const delivery of campaign.deliveries){const text={sent:'Сервер принял письмо',failed:'Не отправлено',not_sent:'Не отправлялось',unknown:'Результат отправки неизвестен; автоматического повтора нет'};
        node('p',delivery.address+' — '+(text[delivery.status]||delivery.status),outgoing,delivery.status==='sent'?'note':'warning');
        if(delivery.error)node('p',delivery.error,outgoing,'error');}
    if(!campaign.messages.length){node('p','Ответов поставщиков ещё нет.',target,'muted');return;}
    const pending=campaign.messages.filter(m=>m.status==='pending').length,errors=campaign.messages.filter(m=>m.status==='error').length;
    if(pending)node('p','Ожидают анализа: '+pending+'. Результаты появятся автоматически.',target,'note');
    if(errors)node('p','Не удалось проанализировать писем: '+errors+'. Причины указаны ниже; можно повторить анализ.',target,'warning');
    if(campaign.ranking_error)node('p','Не удалось обновить сравнение: '+campaign.ranking_error,target,'error');
    const ranking=campaign.ranking?.result;
    if(ranking){
        node('h3',ranking.top.length?'Топ поставщиков ('+ranking.top.length+')':'Пока нет предложений для топа',target);node('p',ranking.summary,target);
        if(campaign.ranking_dirty||campaign.messages.some(m=>m.status!=='analyzed'))node('p','Сравнение основано только на успешно обработанных письмах. После анализа остальных ответов оно будет обновлено.',target,'warning');
        ranking.top.forEach((entry,index)=>{
            const card=node('article','',target,'offer');const title=node('h3','',card);node('span','#'+(index+1),title,'rank');title.append(document.createTextNode(entry.supplier));
            node('p',entry.reason,card);const table=node('table','',card);
            for(const fact of entry.facts){const row=node('tr','',table);node('td',fact.label,row);const cell=node('td',fact.value,row);
                const quote=node('details','',cell);node('summary','Основание в письме',quote);node('blockquote',fact.quote,quote);
                messageButton(quote,campaign.id,fact.message_id,'Показать исходное письмо');}
            if(entry.risks.length){node('h3','Что проверить',card);const ul=node('ul','',card);for(const risk of entry.risks)node('li',risk,ul);}
            const ids=[...new Set(campaign.messages.filter(m=>m.supplier.toLowerCase()===entry.supplier.toLowerCase()).map(m=>m.id))];
            for(const id of ids)messageButton(card,campaign.id,id,'Письмо поставщика');
        });
        if(ranking.limitations.length){const notes=node('div','',target,'warning');node('strong','Ограничения сравнения',notes);const ul=node('ul','',notes);for(const note of ranking.limitations)node('li',note,ul);}
    }else node('p','Сравнение появится после обработки предложений.',target,'muted');
    const all=node('details','',target);node('summary','Все полученные письма ('+campaign.messages.length+')',all);all.open=!ranking;
    for(const message of campaign.messages){
        const row=node('section','',all,'message-row');node('h3',message.supplier+' · '+message.subject,row);
        const statuses={pending:'Ожидает анализа',analyzed:'Проанализировано',error:'Ошибка анализа',unsupported:'Нужна отдельная обработка'};
        node('p',(statuses[message.status]||message.status)+' · '+message.date,row,'tag');
        if(message.match==='sender')node('p',message.ambiguous?'Адрес участвует в нескольких закупках. Письмо отнесено к последнему запросу: проверьте связь.':'Выбрано по адресу поставщика. Проверьте, что ответ относится к этой закупке.',row,'warning');
        if(message.match!=='sender')node('p','Выбрано как ответ на отправленный запрос.',row,'note');
        if(message.analysis_error)node('p',message.analysis_error,row,'error');
        if(message.attachments?.length)node('p','Вложения не анализировались: '+message.attachments.join(', '),row,'warning');
        messageButton(row,campaign.id,message.id,'Показать исходное письмо');
        if(message.analysis){const summary=node('details','',row);node('summary','Суммаризация и все товары',summary);analysisView(message.analysis,summary);}
    }
}
async function loadStatus(){
    if(!connected||shuttingDown)return;const data=await api('status');if(!connected||shuttingDown)return;state=data;suppliersView(data.suppliers);
    $('#worker-status').textContent=data.background_enabled?(data.worker_running?'Фоновая проверка включена · каждые 60 минут.':'Фоновый обработчик не запущен. Перезапустите проект через start.ps1; открытая страница также проверяет почту по расписанию.'):'Для включения фоновой проверки выйдите из почты и войдите снова.';
    const select=$('#campaign-select');select.replaceChildren();
    if(!data.campaigns.length){node('option','Запросов пока нет',select).value='';activeId='';}
    for(const campaign of data.campaigns){const option=node('option',(campaign.status==='draft'?'Черновик · ':'')+campaign.subject,select);option.value=campaign.id;}
    if(!data.campaigns.some(c=>c.id===activeId))activeId=data.campaigns[0]?.id||'';
    select.value=activeId;const current=data.campaigns.find(c=>c.id===activeId);draftView(current);renderCampaign(current);
    $('#refresh-status').textContent=data.status_message;$('#check-time').textContent='Последняя проверка: '+date(data.last_check)+' · Следующая: '+date(data.next_check);
    if(data.check_error)showError('#refresh-error',new Error(data.check_error));
    $('#retry').hidden=!data.campaigns.some(c=>c.ranking_error||c.messages.some(m=>m.status==='error'));
}
$('#campaign-select').onchange=()=>{activeId=$('#campaign-select').value;const c=state?.campaigns.find(c=>c.id===activeId);draftView(c);renderCampaign(c);};
$('#compose-form').onsubmit=async event=>{
    event.preventDefault();if(busy)return;busy=true;const button=event.currentTarget.querySelector('button');button.disabled=true;button.textContent='ИИ готовит письмо…';showError('#compose-error',null);
    try{const data=await api('compose',{brief:$('#brief').value});activeId=data.campaign.id;draftId='';await loadStatus();}
    catch(e){showError('#compose-error',e);}finally{busy=false;button.disabled=false;button.textContent='Сформировать письмо';}
};
$('#send-form').onsubmit=async event=>{
    event.preventDefault();if(busy||!draftId)return;
    const suppliers=[...document.querySelectorAll('input[name=supplier]:checked')].map(n=>n.value);
    if(!suppliers.length){showError('#send-error',new Error('Выберите хотя бы одного поставщика.'));return;}
    busy=true;const button=event.currentTarget.querySelector('button[type=submit]');button.disabled=true;showError('#send-error',null);
    $('#send-status').textContent='Отправляем каждому поставщику отдельное письмо…';
    try{const data=await api('send',{id:draftId,suppliers:JSON.stringify(suppliers),subject:$('#subject').value,body:$('#body').value});
        $('#send-status').replaceChildren();for(const d of data.deliveries)node('p',d.address+' — '+(d.status==='sent'?'принято почтовым сервером':d.error||'результат: '+d.status),$('#send-status'),d.status==='sent'?'success':'error');
        await loadStatus();
    }catch(e){showError('#send-error',e);}finally{busy=false;button.disabled=false;}
};
async function drain(){
    if(processing||!connected||shuttingDown)return;processing=true;
    try{for(let i=0;i<80&&connected&&!shuttingDown;i++){const data=await request('procurement.php','process');if(data.busy)break;await loadStatus();if(!data.worked)break;}}
    finally{processing=false;}
}
async function refresh(){
    if(busy||processing||!connected)return;busy=true;$('#refresh').disabled=true;showError('#refresh-error',null);$('#refresh-status').textContent='Проверяем новые письма…';
    try{const data=await api('refresh');await loadStatus();$('#refresh-status').textContent=data.message;await drain();}
    catch(e){showError('#refresh-error',e);}finally{busy=false;$('#refresh').disabled=false;}
}
$('#refresh').onclick=refresh;
$('#retry').onclick=async()=>{if(busy||processing)return;busy=true;$('#retry').disabled=true;showError('#refresh-error',null);
    try{await api('retry');await drain();}catch(e){showError('#refresh-error',e);}finally{busy=false;$('#retry').disabled=false;}};
$('#manual-form').onsubmit=async event=>{
    event.preventDefault();const button=event.currentTarget.querySelector('button');button.disabled=true;showError('#manual-error',null);$('#manual-result').replaceChildren();
    try{const data=await fetch('analyze.php',{method:'POST',body:new URLSearchParams({csrf:boot.csrf,letter:$('#manual-letter').value})}).then(r=>r.json());
        if(data.error)throw new Error(data.error);if(data.result)analysisView(data,$('#manual-result'));else throw new Error(data.format_error||data.warning);}
    catch(e){showError('#manual-error',e);}finally{button.disabled=false;}
};
async function poll(){
    if(!connected||busy||processing||polling||shuttingDown)return;polling=true;
    try{await loadStatus();if(!state.worker_running){
        if(state.next_check&&state.next_check<=Date.now()/1000){polling=false;await refresh();}
        else await drain();
    }}catch(e){showError('#refresh-error',e);}finally{polling=false;}
}
accountView();if(connected)poll();setInterval(poll,20000);

$('#shutdown').onclick=async()=>{
    if(shuttingDown)return;shuttingDown=true;showError('#shutdown-error',null);
    const controls=[...document.querySelectorAll('button,input,textarea,select')].map(n=>[n,n.disabled]);
    for(const [control] of controls)control.disabled=true;
    const status=$('#shutdown-status');status.hidden=false;status.textContent='Завершаем работу…';
    try{
        const data=await request('shutdown.php','stop');status.textContent=data.message;
        let stopped=false;
        for(let attempt=0;attempt<20;attempt++){
            await new Promise(resolve=>setTimeout(resolve,400));
            try{await fetch('index.php',{cache:'no-store',signal:AbortSignal.timeout(1500)});}
            catch(e){if(e.name!=='TimeoutError'&&e.name!=='AbortError'){stopped=true;break;}}
        }
        if(!stopped)throw new Error('Сайт пока отвечает. Не удалось подтвердить остановку. Попробуйте ещё раз.');
        connected=false;$('#original-dialog').close();
        document.querySelector('main').classList.add('is-stopped');
        $('#shutdown').textContent='Работа завершена';
        status.textContent='Сайт остановлен. Для повторного открытия запустите проект снова.';
    }catch(e){
        shuttingDown=false;for(const [control,disabled] of controls)control.disabled=disabled;
        status.hidden=true;showError('#shutdown-error',e);
    }
};

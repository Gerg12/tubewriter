/* Publishing workspace. No calendar read calls YouTube. */
(function () {
'use strict';
if (!window.TW) return;
var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
async function api(op, data) {
 var form = new FormData(); form.set('action','tw_calendar_workspace'); form.set('nonce',TW.nonce); form.set('tw_front','1'); form.set('op',op); form.set('data',JSON.stringify(data || {}));
 var response = await fetch(TW.ajax,{method:'POST',credentials:'same-origin',body:form});
 var result = await response.json();
 if (!result.success) { var error = new Error(typeof result.data === 'string' ? result.data : (result.data && result.data.message) || 'Could not complete that change.'); error.needsConfirm = result.data && result.data.needs_confirm; throw error; }
 return result.data;
}
// Detect once, without replacing a timezone the account has already chosen.
var timezoneSelect = document.querySelector('#tw_timezone[data-detect="1"]');
var detection = Promise.resolve();
if (timezoneSelect) {
 try { var detected = Intl.DateTimeFormat().resolvedOptions().timeZone; if (Array.from(timezoneSelect.options).some(function(o){return o.value === detected;})) { timezoneSelect.value = detected; detection = api('timezone',{timezone:detected}).catch(function(){}); } } catch (ignore) {}
}
var feed = document.getElementById('uwp-feed');
if (feed) {
 var feedUrl = ''; var enabled = feed.dataset.enabled === '1';
 function feedState() { feed.querySelector('[data-feed="create"]').hidden=enabled; ['rotate','off','save'].forEach(function(a){feed.querySelector('[data-feed="'+a+'"]').hidden=!enabled;}); feed.querySelector('[data-feed="copy"]').disabled=!feedUrl; }
 feedState();
 feed.addEventListener('click',async function(event){
  var button=event.target.closest('[data-feed]'); if(!button)return;
  var action=button.dataset.feed; var out=document.getElementById('uwp-feed-result');
  try {
   if(action==='copy'){ await navigator.clipboard.writeText(feedUrl); out.textContent='Link copied.'; return; }
   if(action==='rotate' && !window.confirm('Replace the calendar link? The old link will stop working.'))return;
   if(action==='off' && !window.confirm('Turn off this calendar feed? Its link will stop working.'))return;
   button.disabled=true;
   var result=await api('feed',{action:action,published:document.getElementById('uwp-feed-published').checked,notes:document.getElementById('uwp-feed-notes').checked});
   enabled=result.enabled;
   if(result.url){feedUrl=result.url;out.innerHTML='<label>Your private link<input type="url" readonly value="'+esc(feedUrl)+'"></label>';}
   else { if(action==='off')feedUrl=''; out.textContent=action==='off'?'Feed turned off.':'Feed options saved.'; }
   document.getElementById('uwp-feed-state').textContent=enabled?'Feed is on.':'Feed is off.';
  }catch(error){out.textContent=error.message;}finally{button.disabled=false;feedState();}
 });
}
var root=document.getElementById('uw-planner'); if(!root)return;
var boot=JSON.parse(root.dataset.boot);
if (boot.detectTimezone) { try { detection=api('timezone',{timezone:Intl.DateTimeFormat().resolvedOptions().timeZone}).catch(function(){}); } catch(ignore) {} } var app=document.getElementById('uwp-app'); var dialog=document.getElementById('uwp-dialog'); var message=document.getElementById('uwp-message');
var trayOpen=false;
var state=null, lane='all', view='upcoming', bestLane='long', selected=boot.today, cursor=boot.today, search='', dragId='', opener=null;
var workspaceLoaded=false;
var weekdays=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
function shortIcon(){return '<svg class="uwp-shorts-icon" width="18" height="22" viewBox="0 0 20 24" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="M6 1h8a4 4 0 0 1 4 4v14a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V5a4 4 0 0 1 4-4Zm2 6v10l7-5-7-5Z"/></svg>';}
function accountToday(){
 var parts=new Intl.DateTimeFormat('en-US',{timeZone:state.tz,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()),values={};
 parts.forEach(function(p){values[p.type]=p.value;});return values.year+'-'+values.month+'-'+values.day;
}
function goToday(){
 state.today=accountToday();state.now=Math.floor(Date.now()/1000);cursor=state.today;selected=state.today;
 view='upcoming';
 render();
 var board=app.querySelector('.uwp-board'),strip=app.querySelector('.uwp-day-detail');
 if(board){board.style.scrollMarginTop=((strip?strip.offsetHeight:0)+20)+'px';board.classList.add('uwp-returned-today');board.scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
 var button=app.querySelector('[data-action="today"]');if(button)button.focus({preventScroll:true});
}
function parse(date){return new Date(date+'T12:00:00');}
function iso(date){return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');}
function add(date,n){var d=parse(date);d.setDate(d.getDate()+n);return iso(d);}
function pretty(date,opts){return parse(date).toLocaleDateString(undefined,opts || {weekday:'long',month:'short',day:'numeric'});}
function timeLabel(time){var a=time.split(':');return (Number(a[0])%12||12)+':'+a[1]+(Number(a[0])<12?'am':'pm');}
function editUrl(id,date,time){return boot.editBase+'?video='+encodeURIComponent(id)+(date?'&schedule_date='+encodeURIComponent(date)+'&schedule_time='+encodeURIComponent(time):'');}
function notice(text,error){message.textContent=text;message.className=error?'uwp-notice is-error':'uwp-notice';}
function shown(e){return lane==='all'||e.lane===lane;}
function byDate(date){return state.events.filter(function(e){return e.date===date&&shown(e);}).sort(function(a,b){return a.time.localeCompare(b.time);});}
function rhythmTime(date,type){var r=state.rhythms[type];return r&&r.days.indexOf(parse(date).getDay())>=0?r.time:state.defaultTime;}
function gaps(){
 var out=[];
 for(var i=0;i<56;i++){
  var date=add(state.today,i), day=parse(date).getDay();
  ['long','shorts'].forEach(function(type){var r=state.rhythms[type];if(!r||r.days.indexOf(day)<0)return;
   if(date===state.today && r.time<=new Intl.DateTimeFormat('en-GB',{timeZone:state.tz,hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(state.now*1000)))return;
   if(!state.events.some(function(e){return e.date===date&&e.lane===type&&(e.type==='planned'||e.type==='scheduled'||e.type==='published');}))out.push({id:'slot-'+date+'-'+type,date:date,time:r.time,lane:type,type:'slot',title:type==='shorts'?'Shorts slot':'Long video slot'});
  });
 }
 return out;
}
function ready(e){if(!e.ready)return '';return '<span class="uwp-dot '+esc(e.ready.level)+'" role="img" aria-label="'+esc(e.ready.reasons.length?e.ready.reasons.join('. '):'Ready')+'" title="'+esc(e.ready.reasons.length?e.ready.reasons.join('. '):'Ready')+'"></span>';}
function movable(e){return e.type==='planned'||e.type==='draft'||(e.type==='scheduled'&&e.at>=state.now+900);}
function durationLabel(seconds){
 var total=Math.floor(Number(seconds));
 if(!Number.isFinite(total)||total<=0)return '';
 var hours=Math.floor(total/3600),minutes=Math.floor(total%3600/60),rest=String(total%60).padStart(2,'0');
 return (hours?hours+':'+String(minutes).padStart(2,'0'):String(minutes))+':'+rest;
}
var clockServer=null,clockClient=0;
function calendarNow(){
 if(clockServer!==state.now){clockServer=state.now;clockClient=Date.now();}
 return Number(clockServer)+(Date.now()-clockClient)/1000;
}
function countdown(at,date){
 var seconds=Number(at)-calendarNow();
 if(seconds<=0)return 'Awaiting sync';
 if(seconds<60)return 'Live in less than a minute';
 if(seconds<3600){var minutes=Math.ceil(seconds/60);return 'Live in '+minutes+' '+(minutes===1?'minute':'minutes');}
 if(seconds<86400){var hours=Math.ceil(seconds/3600);return 'Live in '+hours+' '+(hours===1?'hour':'hours');}
 if(date===add(state.today,1))return 'Tomorrow';
 var days=Math.floor(seconds/86400);return 'Live in '+days+' days';
}
function cardFacts(e,video){
 var out='',game=e.plan&&e.plan.game;
 if(game)out+='<span class="uwp-card-game">'+esc(game)+'</span>';
 if(e.type==='scheduled'){
  out+='<span class="uwp-countdown" data-live-at="'+esc(e.at)+'" data-live-date="'+esc(e.date)+'">'+esc(countdown(e.at,e.date))+'</span>';
  if(e.ready){
   var reasons=(e.ready.reasons||[]).slice();if(!e.thumb)reasons.push('Missing thumbnail');var first=reasons[0]||'',label=!reasons.length?'Ready':first==='No chapters in the description'?'Needs chapters':first==='Description is short or looks like a default'?'Check description':first==='Never pushed with UploadWriter'?'Not pushed yet':first==='Missing thumbnail'?'Needs thumbnail':first;
   var detail=reasons.length?reasons.join('. '):'All local readiness checks passed';var level=reasons.length?(e.ready.level==='red'||e.at<=calendarNow()+172800?'red':'amber'):'green';
   out+='<a class="uwp-ready-summary '+(level==='red'?'is-urgent':reasons.length?'needs-work':'is-ready')+'" href="'+esc(editUrl(video))+'" title="'+esc(detail)+'" aria-label="'+esc(label+'. '+detail)+'">'+ready({ready:{level:level,reasons:reasons}})+'<span>'+esc(label)+(reasons.length>1?' <span class="uwp-extra-reasons">+'+(reasons.length-1)+'</span>':'')+'</span></a>';
  }
  if(!e.thumb&&!e.ready)out+='<a class="uwp-thumbnail-warning" href="'+esc(editUrl(video))+'">Missing thumbnail</a>';
 }
 if(e.type==='published'&&e.views!=null){
  var synced=Number(e.syncedAt)>0?' Last synced '+new Intl.DateTimeFormat('en-US',{timeZone:state.tz,month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(e.syncedAt*1000))+'.':'';
  out+='<span class="uwp-cached-views" title="Views from the local index.'+esc(synced)+'">'+esc(Number(e.views).toLocaleString())+' views <span>· last sync</span></span>';
 }
 return out?'<div class="uwp-card-facts">'+out+'</div>':'';
}

function card(e,full){
 var planned=e.type==='planned',slot=e.type==='slot',color=planned?e.plan.color:'',video=planned?(e.plan.video_id||''):e.id;
 var length=durationLabel(e.duration);
 var status=planned?(e.linked_status?'Linked '+e.linked_status:'Planned'):e.type.charAt(0).toUpperCase()+e.type.slice(1);
 if(slot)return '<button class="uwp-slot" data-slot="'+esc(e.id)+'" title="'+esc(e.lane==='shorts'?'Shorts posting rhythm':'Long video posting rhythm')+'"><span class="uwp-slot-plus" aria-hidden="true">+</span> '+esc(timeLabel(e.time))+'<span>'+esc(e.title)+'</span></button>';
 var title=video?'<a draggable="false" class="uwp-card-title uwp-card-link" href="'+esc(editUrl(video))+'" aria-label="Open video: '+esc(e.title)+'">'+esc(e.title)+'<span class="uwp-open-arrow" aria-hidden="true">↗</span></a>':'<button type="button" class="uwp-card-title uwp-card-link" data-plan="'+esc(e.id)+'" aria-label="Edit plan: '+esc(e.title)+'">'+esc(e.title)+'<span class="uwp-open-arrow" aria-hidden="true">↗</span></button>';
 return '<article class="uwp-card '+(e.thumb?'has-thumb ':'')+esc(e.type)+' '+esc(e.lane)+' color-'+esc(color)+'" data-card="'+esc(e.id)+'" draggable="'+movable(e)+'" title="'+esc(e.lane==='shorts'?'Short (by length)':'Long video')+'">'+
  (e.thumb?'<div class="uwp-card-thumb"><img draggable="false" src="'+esc(e.thumb)+'" alt="" loading="lazy">'+(length?'<span class="uwp-duration" aria-label="Video length '+esc(length)+'">'+esc(length)+'</span>':'')+'</div>':'')+
  '<div class="uwp-card-body"><div class="uwp-card-meta"><span class="uwp-status">'+esc(status)+'</span><span>'+esc(e.type==='draft'?(e.thumb&&length?'Private upload':length||'Private upload'):timeLabel(e.time))+'</span>'+(e.lane==='shorts'?'<span class="uwp-short-label" title="Short (by length)">'+shortIcon()+'Short</span>':'')+'</div>'+title+
  (e.clash?'<span class="uwp-clash">Same time slot</span>':'')+
  cardFacts(e,video)+
  (full&&e.ready&&e.ready.reasons.length?'<ul class="uwp-reasons">'+e.ready.reasons.map(function(reason){return '<li><a href="'+esc(editUrl(video))+'">'+esc(reason)+'</a></li>';}).join('')+'</ul>':'')+
  (e.type!=='published'?'<div class="uwp-card-actions"><button type="button" data-move="'+esc(e.id)+'" aria-label="Move '+esc(e.title)+'"><span aria-hidden="true">↔</span> Move</button>'+(planned&&video?'<button type="button" data-plan="'+esc(e.id)+'">Edit plan</button>':'')+'</div>':'')+'</div></article>';
}
function find(id){return state.events.concat(state.drafts).find(function(e){return e.id===id;});}
function bestSlots(type){return state.best.cells?state.best.cells[type].filter(function(c){return c.score!==null;}).sort(function(a,b){return b.score-a.score||a.day-b.day||a.hour-b.hour;}).slice(0,3):[];}
function hourLabel(h){return (h%12||12)+(h<12||h===24?'am':'pm');}
function slotSentence(c){return weekdays[c.day]+'s '+hourLabel(c.hour)+' to '+hourLabel(c.hour+2)+' do about '+c.score.toFixed(1)+'x your usual views.';}
function bestPanel(){
 if(!state.best.cells)return '<section class="uwp-panel uwp-best"><span class="uwp-eyebrow">LET YOUR CHANNEL GUIDE YOU</span><h2>Best times</h2><p>Run a full sync to find patterns in your own uploads.</p><button class="tw-btn" data-action="sync">Run full sync</button><small>A full sync uses YouTube units. The heatmap uses 0.</small></section>';
 var cells=state.best.cells[bestLane], tops=bestSlots(bestLane);
 var html='<section class="uwp-panel uwp-best"><div class="uwp-panel-title"><div><span class="uwp-eyebrow">LET YOUR CHANNEL GUIDE YOU</span><h2>Best times</h2></div><div class="uwp-segment" aria-label="Best times video type">'+['long','shorts'].map(function(t){return '<button data-best="'+t+'" aria-pressed="'+(bestLane===t)+'">'+(t==='long'?'Long videos':'Shorts')+'</button>';}).join('')+'</div></div><p class="uwp-subtle">Based on your last full sync on '+esc(state.best.synced)+'. Times in '+esc(state.tz)+'.</p>';
 html+='<div class="uwp-heat-scroll"><div class="uwp-heat" role="group" aria-label="Views by weekday and two hour block"><span></span>';
 for(var h=0;h<12;h++)html+='<span class="uwp-heat-label">'+hourLabel(h*2)+'</span>';
 for(var d=1;d<=7;d++){
  var day=d%7;html+='<span class="uwp-heat-label">'+weekdays[day].slice(0,3)+'</span>';
  for(var block=0;block<12;block++){var c=cells[day*12+block],level=c.score===null?0:c.score<0.8?1:c.score<1.2?2:c.score<1.5?3:4;html+='<button class="uwp-heat-cell heat-'+level+'" data-heat="'+(day*12+block)+'" aria-label="'+esc(weekdays[day]+' '+hourLabel(block*2)+' to '+hourLabel(block*2+2)+': '+(c.score===null?'Not enough data':c.score.toFixed(2)+'x usual views, '+c.count+' videos'))+'" title="'+esc(c.score===null?'Not enough data':c.score.toFixed(2)+'x usual views · '+c.count+' videos')+'"></button>';}
 }
 html+='</div></div><p id="uwp-heat-detail" class="uwp-subtle">Select a cell for details. At least 3 videos are needed per slot.</p><div class="uwp-heat-key"><span>Fewer views</span><i class="heat-1"></i><i class="heat-2"></i><i class="heat-3"></i><i class="heat-4"></i><span>More views</span></div>';
 html+=tops.length?'<ol class="uwp-top-slots">'+tops.map(function(c){return '<li>'+esc(slotSentence(c))+'</li>';}).join('')+'</ol>':'<p>More uploads are needed to identify reliable slots.</p>';
 return html+'<p class="uwp-subtle">Patterns, not guarantees. Views are compared with the median of uploads within 60 days. Includes public videos aged 14 days to 18 months.</p></section>';
}
function focusPanel(){ return '<section class="uwp-panel uwp-day-detail"><div class="uwp-panel-title"><div><span class="uwp-eyebrow">SELECTED DAY</span><h2>'+esc(pretty(selected))+'</h2></div>'+(selected>=state.today?'<button class="tw-btn" data-action="new-selected">+ Plan a video</button>':'')+'</div><div class="uwp-detail-cards">'+(byDate(selected).length?byDate(selected).map(function(e){return card(e,true);}).join(''):'<p class="uwp-subtle">No videos on this day. Click another date to see its video cards here.</p>')+'</div></section>'; }
function selectDay(date){
 selected=date;
 var old=app.querySelector('.uwp-day-detail');
 if(old)old.outerHTML=focusPanel();
 app.querySelectorAll('.uwp-day[data-drop]').forEach(function(day){var active=day.dataset.drop===date;day.classList.toggle('is-selected',active);var number=day.querySelector('[data-day]');if(number)number.setAttribute('aria-pressed',String(active));});
}
function calendarRange(){
 if(view==='upcoming')return {first:weekStart(cursor),days:28};
 if(view==='week')return {first:weekStart(cursor),days:7};
 if(view==='list')return {first:state.today,days:30};
 var first=cursor.slice(0,7)+'-01',d=parse(first);
 return {first:weekStart(first),days:Math.ceil((((d.getDay()+6)%7)+new Date(d.getFullYear(),d.getMonth()+1,0).getDate())/7)*7};
}
function weekDivider(date){
 var thisWeek=weekStart(state.today),label=date===thisWeek?'This week':date===add(thisWeek,7)?'Next week':'Week of '+pretty(date,{month:'short',day:'numeric'});
 return '<div class="uwp-week-divider" role="heading" aria-level="3"><strong>'+esc(label)+'</strong><span>'+esc(pretty(date,{month:'short',day:'numeric'}))+' to '+esc(pretty(add(date,6),{month:'short',day:'numeric'}))+'</span></div>';
}
function render(){
 if(!state)return;
 var focus=document.activeElement, focusQuery='';
 if(focus && app.contains(focus)) { ['data-view','data-lane','data-best','data-day','data-action'].some(function(a){if(focus.hasAttribute(a)){focusQuery='['+a+'="'+focus.getAttribute(a)+'"]';return true;}return false;}); }

 var open=gaps(), soon=open.filter(function(s){return s.date<add(state.today,14)&&shown(s);}), counts=state.events.filter(function(e){return e.date>=state.today&&e.date<add(state.today,30)&&shown(e);});
 var range=calendarRange(),smallScreen=window.matchMedia('(max-width:639px)').matches;
 var title=view==='month'?pretty(cursor,{month:'long',year:'numeric'}):(view==='week'||view==='upcoming')?pretty(range.first,{month:'short',day:'numeric'})+' to '+pretty(add(range.first,range.days-1),{month:'short',day:'numeric'}):'Next 30 days';
 var html='<div class="uwp-stats"><div><strong>'+counts.filter(function(e){return e.type==='scheduled';}).length+'</strong><span>Scheduled in 30 days</span></div><div><strong>'+counts.filter(function(e){return e.type==='planned';}).length+'</strong><span>Planned in 30 days</span></div><div><strong>'+state.drafts.filter(shown).length+'</strong><span>Drafts to schedule</span></div></div>';
 if(soon.length)html+='<details class="uwp-gaps"><summary>'+soon.length+' open rhythm '+(soon.length===1?'slot':'slots')+' in the next 14 days <span>Keep your momentum ↗</span></summary><div>'+soon.map(function(s){return '<button data-slot="'+esc(s.id)+'">Nothing scheduled for '+esc(pretty(s.date,{weekday:'short',month:'short',day:'numeric'}))+' · '+esc(s.lane==='shorts'?'Shorts':'Long')+' · '+esc(timeLabel(s.time))+'</button>';}).join('')+'</div></details>';
 html+='<div class="uwp-layout '+(trayOpen?'is-tray-open':'')+'"><div class="uwp-main">'+focusPanel()+'<section class="uwp-board"><div class="uwp-toolbar"><div class="uwp-nav"><button data-action="prev" aria-label="Previous '+(view==='upcoming'?'week':view)+'" '+(view==='list'?'disabled':'')+'>←</button><h2>'+esc(title)+'</h2><button data-action="next" aria-label="Next '+(view==='upcoming'?'week':view)+'" '+(view==='list'?'disabled':'')+'>→</button><button data-action="today">Today</button></div><div class="uwp-segment" aria-label="Calendar view">'+['upcoming','month','week','list'].map(function(v){return '<button data-view="'+v+'" aria-pressed="'+(view===v)+'">'+(v==='list'?'Agenda':v.charAt(0).toUpperCase()+v.slice(1))+'</button>';}).join('')+'</div></div>';
 html+='<div class="uwp-filterbar"><div class="uwp-segment" aria-label="Filter video type">'+['all','long','shorts'].map(function(t){return '<button data-lane="'+t+'" aria-pressed="'+(lane===t)+'">'+(t==='all'?'All videos':t==='long'?'Long':shortIcon()+'Shorts')+'</button>';}).join('')+'</div><span>Times in '+esc(state.tz)+'</span><button data-action="tray" aria-expanded="'+trayOpen+'" aria-controls="uwp-draft-tray">Drafts ('+state.drafts.filter(shown).length+')</button><button data-action="rhythm">Posting rhythm</button></div>';
 if(view==='list'||(view==='upcoming'&&smallScreen)){
  html+='<div class="uwp-agenda">';
  for(var i=0;i<range.days;i++){var date=add(range.first,i),items=byDate(date),slots=open.filter(function(s){return s.date===date&&shown(s);});if(view==='upcoming'&&i%7===0)html+=weekDivider(date);if(!items.length&&!slots.length&&view!=='upcoming')continue;
   html+='<section class="uwp-agenda-day '+(date===state.today?'is-today':date<state.today?'is-past':'is-future')+'" data-drop="'+date+'"><h3><button data-day="'+date+'">'+esc(pretty(date))+'</button></h3><div>'+(items.length||slots.length?items.concat(slots).map(function(e){return card(e,true);}).join(''):'<p class="uwp-subtle">No videos planned</p>')+'</div></section>';
  }
  if(!counts.length&&!open.filter(shown).length)html+='<div class="uwp-empty"><span>✦</span><h3>Your next chapter starts here.</h3><p>Plan your first video or set a posting rhythm to make space for what is next.</p><button class="tw-btn tw-btn--primary" data-action="new">Plan a video</button></div>';
  html+='</div>';
 }else{
  var first=range.first,days=range.days;
  html+='<div class="uwp-grid '+(view==='week'?'is-week':view==='upcoming'?'is-week is-upcoming':'')+'" role="group" aria-label="'+esc(title)+'">';
  if(view==='month'){[1,2,3,4,5,6,0].forEach(function(d){html+='<span class="uwp-weekday">'+weekdays[d].slice(0,3)+'</span>';});}
  for(var j=0;j<days;j++){
   if(view==='upcoming'&&j%7===0)html+=weekDivider(add(first,j));
   var dt=add(first,j),events=byDate(dt),sl=open.filter(function(s){return s.date===dt&&shown(s);});
   html+='<section class="uwp-day '+(dt===state.today?'is-today ':dt<state.today?'is-past ':'is-future ')+(dt===selected?'is-selected ':'')+(view==='month'&&dt.slice(0,7)!==cursor.slice(0,7)?'is-outside':'')+'" data-drop="'+dt+'"><button class="uwp-day-number" data-day="'+dt+'" aria-label="'+esc(pretty(dt))+'" aria-pressed="'+(selected===dt)+'">'+((view==='week'||view==='upcoming')?'<span class="uwp-week-day-name">'+weekdays[parse(dt).getDay()].slice(0,3)+'</span><strong>'+parse(dt).getDate()+'</strong>':parse(dt).getDate())+(dt===state.today?'<span class="uwp-today-label">Today</span>':'')+'</button><div class="uwp-day-cards">'+events.slice(0,view==='week'?20:3).map(function(e){return card(e,false);}).join('')+sl.map(function(e){return card(e,false);}).join('')+'</div>'+((view==='month'||view==='upcoming')&&events.length>3?'<button class="uwp-more" data-day="'+dt+'">+'+(events.length-3)+' more</button>':'')+'</section>';
  }
  html+='</div>';
 }
 html+='<div class="uwp-board-footer"><div><span class="uwp-key scheduled"></span> Scheduled <span class="uwp-key planned"></span> Planned <span class="uwp-key published"></span> Published</div><button data-action="reload">Reload local calendar</button></div></section>';
 html+=bestPanel()+'</div>';
 html+='<aside id="uwp-draft-tray" class="uwp-tray uwp-panel" '+(trayOpen?'':'hidden')+'><div class="uwp-tray-head"><span class="uwp-eyebrow">NEXT UP</span><h2>Ready to schedule</h2><p>Your private uploads, ready for a place in the calendar.</p><label class="uwp-search"><span class="screen-reader-text">Search drafts</span><input id="uwp-search" type="search" placeholder="Search your drafts" value="'+esc(search)+'"></label></div><div id="uwp-drafts">'+draftCards()+'</div><p class="uwp-subtle">Drag to a date or choose Move. Nothing changes on YouTube until you review and confirm.</p></aside></div>';
 app.innerHTML=html;
 if(focusQuery){var nextFocus=app.querySelector(focusQuery);if(nextFocus)nextFocus.focus({preventScroll:true});}
}
function draftCards(){var drafts=state.drafts.filter(shown).filter(function(e){return e.title.toLowerCase().indexOf(search.toLowerCase())>=0;});return drafts.length?drafts.map(function(e){return card(e,true);}).join(''):'<div class="uwp-empty-tray"><strong>'+ (search?'No matching drafts':'All clear for now')+'</strong><p>'+(search?'Try another title.':'Private uploads appear here after your next sync.')+'</p></div>';}
function weekStart(date){var d=parse(date);d.setDate(d.getDate()-((d.getDay()+6)%7));return iso(d);}
function openDialog(title,body){opener=document.activeElement;dialog.innerHTML='<form method="dialog" class="uwp-modal"><div class="uwp-panel-title"><h2 id="uwp-dialog-title">'+esc(title)+'</h2><button type="button" data-close aria-label="Close dialog">×</button></div>'+body+'<p id="uwp-modal-error" role="alert"></p></form>';dialog.showModal();dialog.querySelector('[data-close]').onclick=function(){dialog.close();};}
dialog.addEventListener('close',function(){if(opener&&document.contains(opener))opener.focus();});
function modalError(error){var el=document.getElementById('uwp-modal-error');if(el)el.textContent=error.message;}
function field(label,name,value,type){return '<label>'+esc(label)+'<input name="'+esc(name)+'" type="'+(type||'text')+'" value="'+esc(value)+'" required></label>';}
function futureDate(){return selected>=state.today?selected:state.today;}
function planDialog(e,date,time,type){
 var p=e&&e.plan?e.plan:{},id=p.id||0;
 openDialog(id?'Shape your plan':'Plan something great','<p class="uwp-subtle">An idea today. Your next upload tomorrow. Plans stay in UploadWriter.</p>'+field('Video idea','title',p.title||'')+'<div class="uwp-fields">'+field('Date','date',date||p.date||futureDate(),'date')+field('Time in '+state.tz,'time',time||p.time||state.defaultTime,'time')+'</div><label>Game or series<input name="game" value="'+esc(p.game||'')+'"></label><label>Notes<textarea name="notes" rows="3">'+esc(p.notes||'')+'</textarea></label><div class="uwp-fields"><label>Video type<select name="lane"><option value="long">Long video</option><option value="shorts" '+((type||p.lane)==='shorts'?'selected':'')+'>Short</option></select></label><label>Color tag<select name="color">'+['violet','blue','teal','amber','rose','slate'].map(function(c){return '<option '+(p.color===c?'selected':'')+'>'+c+'</option>';}).join('')+'</select></label></div><label>Link to upload<select name="video_id"><option value="">Keep as an idea</option>'+state.videos.map(function(v){return '<option value="'+esc(v.id)+'" '+(p.video_id===v.id?'selected':'')+'>'+esc(v.title)+'</option>';}).join('')+'</select></label><div class="uwp-modal-actions">'+(id?'<button type="button" id="uwp-remove" class="uwp-danger">Remove local plan</button>':'')+'<button type="submit" class="tw-btn tw-btn--primary">Save plan</button></div>');
 var form=dialog.querySelector('form');form.onsubmit=async function(event){event.preventDefault();var b=form.querySelector('[type="submit"]');b.disabled=true;try{var data=Object.fromEntries(new FormData(form));data.id=id;state=await api('plan_save',data);dialog.close();render();notice('Plan saved.');}catch(error){modalError(error);}finally{b.disabled=false;}};
 if(id)document.getElementById('uwp-remove').onclick=async function(){if(!window.confirm('Remove this local plan? This does not change the video on YouTube.'))return;try{state=await api('plan_remove',{id:id});dialog.close();render();notice('Local plan removed.');}catch(error){modalError(error);}};
}
function blocked(e){if(e.type==='published')return 'Published videos cannot be moved. Choose a draft or scheduled video.';if(e.type==='scheduled'&&e.at<state.now+900)return 'This video goes live in under 15 minutes and cannot be moved.';return '';}
function scheduleDialog(e,date){
 var reason=blocked(e);if(reason){notice(reason,true);return;}
 if(date&&date<state.today){notice('Choose today or a future day. Past days cannot receive videos.',true);return;}
 if(e.type==='planned'){planDialog(e,date,date?rhythmTime(date,e.lane):e.time,e.lane);return;}
 date=date||futureDate();var at=rhythmTime(date,e.lane);var review=null;
 openDialog('Give this video a time','<p class="uwp-modal-video">'+esc(e.title)+'</p><div class="uwp-fields">'+field('Date','date',date,'date')+field('Time in '+state.tz,'time',at,'time')+'</div><p>Only the schedule will change. Your live title, description and tags are kept.</p><p class="uwp-cost">This uses about 51 YouTube units. Review and refresh checks can use a few more.</p><div id="uwp-review"></div><div class="uwp-modal-actions"><button type="submit" class="tw-btn tw-btn--primary">Review schedule</button></div>');
 var form=dialog.querySelector('form'),button=form.querySelector('[type="submit"]');form.elements.date.min=state.today;
 form.addEventListener('input',function(){review=null;button.textContent='Review schedule';document.getElementById('uwp-review').innerHTML='';});
 form.onsubmit=async function(event){event.preventDefault();button.disabled=true;try{
  var data={video:e.id,date:form.elements.date.value,time:form.elements.time.value};
  if(!review){review=await api('schedule_review',data);document.getElementById('uwp-review').innerHTML='<div class="uwp-review"><strong>'+esc(review.label)+'</strong><label><input type="checkbox" id="uwp-confirm-check" required> I have checked this schedule change.</label></div>';button.textContent='Confirm schedule';return;}
  if(!document.getElementById('uwp-confirm-check').checked)return;
  if(!window.confirm('Schedule "'+review.title+'" for '+review.label+'? This changes the schedule on YouTube.'))return;
  Object.assign(data,review,{confirmed:true});var result;
  try{result=await api('schedule_confirm',data);}catch(error){if(error.needsConfirm&&window.confirm(error.message)){data.pace_ack=true;result=await api('schedule_confirm',data);}else throw error;}
  dialog.close();await load();notice('Schedule updated. The previous schedule is backed up in History.');
  var undo=document.createElement('button');undo.textContent='Undo schedule change';undo.className='tw-btn';undo.onclick=async function(){if(!window.confirm('Restore the previous schedule and live details from this backup? Your current details will be backed up first.'))return;undo.disabled=true;try{var f=new FormData();f.set('action','tw_restore');f.set('nonce',TW.nonce);f.set('tw_front','1');f.set('video',e.id);f.set('entry',result.backup);var r=await (await fetch(TW.ajax,{method:'POST',credentials:'same-origin',body:f})).json();if(!r.success)throw Error(typeof r.data==='string'?r.data:r.data.message);if(!r.data.wrote)throw Error('Restore failed. Open History for details.');await load();notice('Previous schedule restored.');}catch(err){notice(err.message,true);undo.disabled=false;}};message.appendChild(undo);
 }catch(error){review=null;button.textContent='Review schedule';var reviewBox=document.getElementById('uwp-review');if(reviewBox)reviewBox.innerHTML='';modalError(error);}finally{button.disabled=false;}};
}
function slotDialog(slot){
 openDialog('Make this slot yours','<p>'+esc(pretty(slot.date))+' at '+esc(timeLabel(slot.time))+' · '+esc(slot.lane==='shorts'?'Shorts':'Long video')+'</p><div class="uwp-modal-actions"><button type="button" class="tw-btn tw-btn--primary" id="uwp-slot-plan">Plan a video</button></div><label>Schedule a draft<select id="uwp-slot-draft"><option value="">Choose a private upload</option>'+state.drafts.filter(function(e){return e.lane===slot.lane;}).map(function(e){return '<option value="'+esc(e.id)+'">'+esc(e.title)+'</option>';}).join('')+'</select></label><button type="button" class="tw-btn" id="uwp-slot-editor">Open editor at Go live</button>');
 document.getElementById('uwp-slot-plan').onclick=function(){dialog.close();planDialog(null,slot.date,slot.time,slot.lane);};
 document.getElementById('uwp-slot-editor').onclick=function(){var id=document.getElementById('uwp-slot-draft').value;if(id)location.href=editUrl(id,slot.date,slot.time);else modalError(Error('Choose a draft first.'));};
}
function rhythmDialog(){
 var body='<p>Give your week a shape. Open slots appear for the next 8 weeks. Leave every day unchecked to turn a rhythm off.</p>';
 ['long','shorts'].forEach(function(type){var r=state.rhythms[type]||{days:[],time:state.defaultTime},tops=bestSlots(type);body+='<fieldset class="uwp-rhythm"><legend>'+(type==='long'?'Long videos':'Shorts')+'</legend><div class="uwp-days">'+[1,2,3,4,5,6,0].map(function(d){return '<label><input type="checkbox" name="'+type+'-day" value="'+d+'" '+(r.days.indexOf(d)>=0?'checked':'')+'><span>'+weekdays[d].slice(0,3)+'</span></label>';}).join('')+'</div>'+field('Time in '+state.tz,type+'-time',r.time,'time')+(tops.length?'<div class="uwp-suggestions"><small>Suggested from your uploads</small>'+tops.map(function(c){return '<button type="button" data-suggestion="'+type+','+c.day+','+c.hour+'">'+weekdays[c.day]+' '+hourLabel(c.hour)+' to '+hourLabel(c.hour+2)+'</button>';}).join('')+'</div>':'')+'</fieldset>';});
 openDialog('Find your posting rhythm',body+'<div class="uwp-modal-actions"><button type="submit" class="tw-btn tw-btn--primary">Save rhythm</button></div>');
 var form=dialog.querySelector('form');dialog.querySelectorAll('[data-suggestion]').forEach(function(b){b.onclick=function(){var parts=b.dataset.suggestion.split(',');form.querySelector('[name="'+parts[0]+'-day"][value="'+parts[1]+'"]').checked=true;form.elements[parts[0]+'-time'].value=parts[2].padStart(2,'0')+':00';};});
 form.onsubmit=async function(event){event.preventDefault();var b=form.querySelector('[type="submit"]');b.disabled=true;try{var data={};['long','shorts'].forEach(function(type){data[type]={days:Array.from(form.querySelectorAll('[name="'+type+'-day"]:checked')).map(function(c){return Number(c.value);}),time:form.elements[type+'-time'].value};});state=await api('rhythms',data);dialog.close();render();notice('Posting rhythm saved.');}catch(error){modalError(error);}finally{b.disabled=false;}};
}
async function load(){state=await api('load');boot.tz=state.tz;if(!workspaceLoaded){cursor=selected=state.today;workspaceLoaded=true;}render();}
root.addEventListener('input',function(event){if(event.target.id==='uwp-search'){search=event.target.value;document.getElementById('uwp-drafts').innerHTML=draftCards();}});
root.addEventListener('click',async function(event){
 var b=event.target.closest('button');
 var day=event.target.closest('.uwp-day[data-drop]');
 if(day&&!event.target.closest('button,a,input,select,textarea')){selectDay(day.dataset.drop);return;}
 if(!b)return;
 try{
  if(b.dataset.view){view=b.dataset.view;render();return;}
  if(b.dataset.lane){lane=b.dataset.lane;render();return;}
  if(b.dataset.best){bestLane=b.dataset.best;render();return;}
  if(b.dataset.heat){var c=state.best.cells[bestLane][Number(b.dataset.heat)];document.getElementById('uwp-heat-detail').textContent=weekdays[c.day]+' '+hourLabel(c.hour)+' to '+hourLabel(c.hour+2)+': '+(c.score===null?'Not enough data ('+c.count+' videos).':c.score.toFixed(2)+'x usual views from '+c.count+' videos.');return;}
  if(b.dataset.day){selectDay(b.dataset.day);return;}
  if(b.dataset.slot){var s=gaps().find(function(g){return g.id===b.dataset.slot;});if(s)slotDialog(s);return;}
  if(b.dataset.open){var e=find(b.dataset.open);if(e.type==='planned'&&!e.plan.video_id)planDialog(e);else location.href=editUrl(e.type==='planned'?e.plan.video_id:e.id);return;}
  if(b.dataset.plan){planDialog(find(b.dataset.plan));return;}
  if(b.dataset.move){scheduleDialog(find(b.dataset.move));return;}
  var action=b.dataset.action;
  if(action==='new'||action==='new-selected'){if(state)planDialog(null,action==='new-selected'?selected:futureDate());return;}
  if(action==='tray'){trayOpen=!trayOpen;render();return;}
  if(action==='rhythm'){rhythmDialog();return;}
  if(action==='today'){goToday();return;}
  if(action==='prev'||action==='next'){var direction=action==='prev'?-1:1;if(view==='week'||view==='upcoming')cursor=add(cursor,direction*7);else{var d=parse(cursor.slice(0,7)+'-01');d.setMonth(d.getMonth()+direction);cursor=iso(d);}render();return;}
  if(action==='reload'){b.disabled=true;await load();notice('Calendar refreshed from your local library.');return;}
  if(action==='sync'){
   if(!window.confirm('Run a full sync from YouTube? This uses about 2 units per 50 uploads plus draft and schedule checks.'))return;
   b.disabled=true;var token='',run='',done=false;
   while(!done){var form=new FormData();form.set('action','tw_sync');form.set('nonce',TW.nonce);form.set('tw_front','1');form.set('mode','full');form.set('token',token);form.set('run',run);var response=await (await fetch(TW.ajax,{method:'POST',credentials:'same-origin',body:form})).json();if(!response.success)throw Error(response.data);token=response.data.token;run=response.data.run;done=response.data.done;notice('Syncing your library. '+response.data.total+' videos indexed.');}
   await load();notice('Full sync complete. Best times updated.');
  }
 }catch(error){notice(error.message,true);b.disabled=false;}
});
root.addEventListener('dragstart',function(event){var el=event.target.closest('[data-card]');if(!el)return;var e=find(el.dataset.card),why=blocked(e);if(why){event.preventDefault();notice(why,true);return;}dragId=e.id;event.dataTransfer.setData('text/plain',e.id);event.dataTransfer.effectAllowed='move';});
root.addEventListener('dragover',function(event){var el=event.target.closest('[data-drop]');if(el&&dragId){event.preventDefault();event.dataTransfer.dropEffect='move';el.classList.add('is-drop');}});
root.addEventListener('dragleave',function(event){var el=event.target.closest('[data-drop]');if(el&&!el.contains(event.relatedTarget))el.classList.remove('is-drop');});
root.addEventListener('dragend',function(){dragId='';root.querySelectorAll('.is-drop').forEach(function(el){el.classList.remove('is-drop');});});
root.addEventListener('drop',function(event){var day=event.target.closest('[data-drop]');if(!day||!dragId)return;event.preventDefault();day.classList.remove('is-drop');var e=find(dragId);dragId='';if(e)scheduleDialog(e,day.dataset.drop);});
window.setInterval(function(){
 if(!state||document.hidden)return;
 app.querySelectorAll('[data-live-at]').forEach(function(node){var text=countdown(node.dataset.liveAt,node.dataset.liveDate);if(node.textContent!==text)node.textContent=text;});
},60000);
(async function(){try{await detection;await load();}catch(error){notice(error.message,true);app.innerHTML='<div class="uwp-empty"><p>The calendar could not load.</p><button data-action="reload" class="tw-btn">Try again</button></div>';}})();
})();

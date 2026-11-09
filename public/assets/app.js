'use strict';
let sessionIndex=Math.max(-1,...Array.from(document.querySelectorAll('#session-list [name]')).map(el=>Number(el.name.match(/sessions\[(\d+)\]/)?.[1]??-1)))+1;
document.querySelector('#add-session')?.addEventListener('click',()=>{
 const template=document.querySelector('#session-template');
 const fragment=template.content.cloneNode(true);
 fragment.querySelectorAll('*').forEach(el=>{for(const attr of Array.from(el.attributes))if(attr.value.includes('INDEX'))el.setAttribute(attr.name,attr.value.replaceAll('INDEX',String(sessionIndex)));});
 sessionIndex++;
 fragment.querySelector('.remove-session').addEventListener('click',e=>e.target.closest('.session-row').remove());
 document.querySelector('#session-list').append(fragment);
});
const librarySelect=document.querySelector('#library-service');
function syncLibrary(){
 document.querySelectorAll('.service-fields').forEach(el=>{const active=el.dataset.service===librarySelect.value;el.hidden=!active;el.disabled=!active;});
}
if(librarySelect){librarySelect.addEventListener('change',syncLibrary);syncLibrary();}

// Conditional fields are also validated by the server.
document.querySelectorAll('[data-conditional]').forEach(group=>{
 const selector=document.querySelector('[name="'+group.dataset.conditional+'"]');
 const sync=()=>{const active=selector.value===group.dataset.value;group.hidden=!active;group.querySelectorAll('input').forEach(input=>{input.disabled=!active;input.required=active&&!group.dataset.optional;});};
 selector?.addEventListener('change',sync);if(selector)sync();
});
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm))event.preventDefault();}));

const boardDisplay=document.querySelector('#board-display');
if(boardDisplay){
 let remaining=0;const image=document.querySelector('#board-qr');const countdown=document.querySelector('#board-countdown');
 async function refreshBoard(){
  image.hidden=true;
  try{
   const response=await fetch('?page=att_board',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'att_qr',csrf:boardDisplay.dataset.csrf})});
   const data=await response.json();if(!response.ok||data.error)throw new Error(data.error||'Unable to load QR');
   image.src=data.image;image.hidden=false;remaining=data.ttl;document.querySelector('#board-class').textContent=data.class;
   setTimeout(refreshBoard,remaining*1000);
  }catch(error){countdown.textContent=error.message;remaining=0;setTimeout(refreshBoard,10000);}
 }
 setInterval(()=>{if(remaining>0){remaining--;countdown.textContent='Refreshing in '+remaining+' seconds';if(remaining===0)image.hidden=true;}},1000);
 document.querySelector('#board-fullscreen')?.addEventListener('click',()=>document.querySelector('.board').requestFullscreen?.());
 refreshBoard();
}
const bookingMethod=document.querySelector('[name="booking_mode"]');
if(bookingMethod){const sync=()=>document.querySelectorAll('[data-booking]').forEach(group=>{const active=group.dataset.booking===bookingMethod.value;group.hidden=!active;group.querySelectorAll('input,select').forEach(input=>{input.disabled=!active;input.required=active;});});bookingMethod.addEventListener('change',sync);sync();}

const menu=document.querySelector('.mobile-menu');
menu?.addEventListener('click',()=>{const open=menu.getAttribute('aria-expanded')!=='true';menu.setAttribute('aria-expanded',String(open));document.querySelector('#main-navigation')?.classList.toggle('mobile-open',open);});
// Keep wide reports within their own horizontally scrollable region.
document.querySelectorAll('main table').forEach(table=>{if(!table.parentElement.classList.contains('table-wrap')){const wrap=document.createElement('div');wrap.className='table-wrap';table.before(wrap);wrap.append(table);}});
const calendarChoice=document.querySelector('#manual-calendar');
if(calendarChoice){const syncCalendar=()=>{const opt=calendarChoice.selectedOptions[0];document.querySelector('#manual-calendar-dates').textContent=opt?.dataset.dates||'Select an academic calendar above.';const semester=document.querySelector('[name="row[Semester]"]');if(opt?.dataset.semester)semester.value=opt.dataset.semester;};calendarChoice.addEventListener('change',syncCalendar);syncCalendar();}

const bulkAttendance=document.querySelector('#bulk-attendance');
if(bulkAttendance){const choices=Array.from(document.querySelectorAll('.attendance-staff-choice:not(:disabled)'));const all=document.querySelector('#select-attendance-staff');const sync=()=>{const count=choices.filter(c=>c.checked).length;document.querySelector('#attendance-selection-count').textContent=count+' selected';all.checked=count>0&&count===choices.length;all.indeterminate=count>0&&count<choices.length;bulkAttendance.querySelectorAll('button').forEach(b=>b.disabled=count===0);};all.addEventListener('change',()=>{choices.forEach(c=>c.checked=all.checked);sync();});choices.forEach(c=>c.addEventListener('change',sync));bulkAttendance.addEventListener('submit',e=>{if(!choices.some(c=>c.checked)){e.preventDefault();return;}if(e.submitter?.value==='0'&&!window.confirm('Disable teaching attendance for the selected staff? Their registered phones will be revoked; accounts and history remain.'))e.preventDefault();});sync();}

const addParticipant=document.getElementById('add-participant');
if(addParticipant){let participantIndex=100;addParticipant.addEventListener('click',()=>{document.getElementById('participant-groups').insertAdjacentHTML('beforeend',document.getElementById('participant-template').innerHTML.replaceAll('__INDEX__',String(participantIndex++)));});document.getElementById('participant-groups').addEventListener('click',event=>{if(event.target.classList.contains('remove-participant'))event.target.closest('fieldset').remove();});}

const substitutionForm=document.getElementById('substitution-form');
if(substitutionForm){
 const choice=substitutionForm.elements.substitution_required,classes=document.getElementById('class-arrangements'),reason=document.getElementById('no-substitution');
 const sync=()=>{classes.hidden=choice.value!=='Yes';classes.disabled=choice.value!=='Yes';reason.hidden=choice.value!=='No';const input=reason.querySelector('textarea');input.disabled=choice.value!=='No';input.required=choice.value==='No';};choice.addEventListener('change',sync);sync();
 document.querySelectorAll('#session-list .remove-session').forEach(b=>b.addEventListener('click',()=>b.closest('.session-row').remove()));
 const summary=document.getElementById('sub-errors');
 substitutionForm.addEventListener('input',event=>{const el=event.target;el.removeAttribute('aria-invalid');for(const msg of substitutionForm.querySelectorAll('[data-error-for]'))if(msg.dataset.errorFor===el.name)msg.textContent='';});
 function clearErrors(){substitutionForm.querySelectorAll('[aria-invalid]').forEach(e=>e.removeAttribute('aria-invalid'));substitutionForm.querySelectorAll('[data-error-for]').forEach(e=>e.textContent='');summary.hidden=true;}
 function showErrors(errors,message){summary.textContent=message;summary.hidden=false;for(const [name,msg]of Object.entries(errors)){const el=substitutionForm.elements.namedItem(name);if(el)el.setAttribute('aria-invalid','true');for(const label of substitutionForm.querySelectorAll('[data-error-for]'))if(label.dataset.errorFor===name)label.textContent=msg;}summary.focus();}
 substitutionForm.addEventListener('submit',async event=>{event.preventDefault();clearErrors();const errors={};for(const el of substitutionForm.elements){if(el.willValidate&&!el.validity.valid)errors[el.name]=el.validity.valueMissing?'Complete this field.':el.validity.typeMismatch?'Enter a valid '+(el.type==='email'?'email address.':'value.'):'Check this value.';}if(Object.keys(errors).length){showErrors(errors,'Please correct the highlighted fields. Your entries have been kept.');return;}
 const button=substitutionForm.querySelector('button:not([type=button])');button.disabled=true;button.textContent='Submitting…';
 try{const response=await fetch(location.href,{method:'POST',body:new FormData(substitutionForm),headers:{'X-CNR-Form':'substitution'}});const result=await response.json();if(result.redirect){location.assign(result.redirect);return;}showErrors(result.errors||{},result.message||'Unable to save. Your entries have been kept.');}catch(error){showErrors({},'The server could not confirm submission. Your entries have been kept. Check Academic Services before trying again to avoid a duplicate.');}finally{button.disabled=false;button.textContent='Submit request';}
 });
}

// Keep question-paper programmes within the selected academic department.
for (const form of document.querySelectorAll('form')) {
  if (form.querySelector('input[name="action"]')?.value !== 'exam_submit') continue;
  const department = form.querySelector('select[name="department"]');
  const programme = form.querySelector('select[name="programme"]');
  if (!department || !programme) continue;
  const refreshProgrammes = () => {
    for (const option of programme.options) {
      if (!option.value) continue;
      option.hidden = option.dataset.department !== department.value;
      option.disabled = option.hidden;
      if (option.selected && option.disabled) programme.value = '';
    }
  };
  department.addEventListener('change', refreshProgrammes);
  refreshProgrammes();
}
for (const select of document.querySelectorAll('[data-exam-status-filters] select')) {
  select.addEventListener('change', () => select.form.requestSubmit());
}

document.addEventListener('keydown',event=>{if(event.key==='Escape'&&menu?.getAttribute('aria-expanded')==='true'){menu.click();menu.focus();}});

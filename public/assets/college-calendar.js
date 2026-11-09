'use strict';
document.getElementById('cc-print')?.addEventListener('click',()=>window.print());
document.querySelectorAll('.cc-delete').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm('Delete this event from the calendar? Its audit history will be retained.'))event.preventDefault();}));

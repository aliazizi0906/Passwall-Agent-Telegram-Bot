'use strict';
(function(){
  var button=document.getElementById('revealPassword');
  var input=document.getElementById('password');
  if(!button||!input)return;
  button.addEventListener('click',function(){
    var showing=input.type==='password';
    input.type=showing?'text':'password';
    button.textContent=showing?'پنهان':'نمایش';
    button.setAttribute('aria-pressed',showing?'true':'false');
  });
})();

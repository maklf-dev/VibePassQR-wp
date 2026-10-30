(function () {
 'use strict';
 const encoder = new TextEncoder();
 function bytes(s){return encoder.encode(s);}
 function jpeg(canvas){const raw=atob(canvas.toDataURL('image/jpeg',0.96).split(',')[1]);return Uint8Array.from(raw,c=>c.charCodeAt(0));}
 function pdf(canvases,scale=.5){
  const parts=[bytes('%PDF-1.4\n')],offsets=[0];let length=parts[0].length;
  function push(part){parts.push(part);length+=part.length;}
  function object(id,items){offsets[id]=length;push(bytes(id+' 0 obj\n'));items.forEach(push);push(bytes('\nendobj\n'));}
  object(1,[bytes('<< /Type /Catalog /Pages 2 0 R >>')]);
  object(2,[bytes('<< /Type /Pages /Count '+canvases.length+' /Kids ['+canvases.map((_,i)=>(3+i*3)+' 0 R').join(' ')+'] >>')]);
  canvases.forEach((canvas,i)=>{
   const id=3+i*3;const w=canvas.width*scale,h=canvas.height*scale;const img=jpeg(canvas);const content=bytes('q '+w+' 0 0 '+h+' 0 0 cm /Im0 Do Q');
   object(id,[bytes('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '+w+' '+h+'] /Resources << /XObject << /Im0 '+(id+1)+' 0 R >> >> /Contents '+(id+2)+' 0 R >>')]);
   object(id+1,[bytes('<< /Type /XObject /Subtype /Image /Width '+canvas.width+' /Height '+canvas.height+' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '+img.length+' >>\nstream\n'),img,bytes('\nendstream')]);
   object(id+2,[bytes('<< /Length '+content.length+' >>\nstream\n'),content,bytes('\nendstream')]);
  });
  const xref=length;push(bytes('xref\n0 '+offsets.length+'\n0000000000 65535 f \n'));
  for(let i=1;i<offsets.length;i++)push(bytes(String(offsets[i]).padStart(10,'0')+' 00000 n \n'));
  push(bytes('trailer\n<< /Size '+offsets.length+' /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF'));
  return new Blob(parts,{type:'application/pdf'});
 }
 function download(blob,name){const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),30000);}
 function canvas(w,h){const c=document.createElement('canvas');c.width=w;c.height=h;const ctx=c.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h);ctx.direction='rtl';ctx.textAlign='right';ctx.textBaseline='top';return c;}
 function lines(ctx,text,width){
  const output=[];
  String(text).split('\n').forEach(p=>{let line='';p.split(/\s+/).forEach(word=>{if(!word)return;if(ctx.measureText(word).width>width){if(line){output.push(line);line='';}let fragment='';for(const char of word){if(fragment&&ctx.measureText(fragment+char).width>width){output.push(fragment);fragment=char;}else fragment+=char;}line=fragment;return;}const candidate=line?line+' '+word:word;if(line&&ctx.measureText(candidate).width>width){output.push(line);line=word;}else line=candidate;});output.push(line);});return output;
 }
 function blocks(element){
  if(!element)return [];
  const copy=element.cloneNode(true);copy.querySelectorAll('script,style,iframe,img').forEach(n=>n.remove());
  copy.querySelectorAll('br').forEach(n=>n.replaceWith(document.createTextNode('\n')));
  copy.querySelectorAll('p,div,li,h1,h2,h3,h4,blockquote').forEach(n=>n.append(document.createTextNode('\n')));
  return copy.textContent.trim().split(/\n+/).filter(Boolean);
 }
 async function cardCanvas(target){
  await document.fonts.ready;const pass=target||document.querySelector('[data-ecm-card]');if(!pass)throw Error('card_missing');
  const mode=document.querySelector('[data-print-mode]').dataset.printMode;const font=getComputedStyle(pass).fontFamily||'Tahoma';
  const measure=canvas(1000,1000).getContext('2d');
  const printBlocks=mode==='page'?blocks(document.querySelector('.ecm-page-copy')):mode==='custom'?blocks(pass.querySelector('.ecm-pass__custom')):[];
  measure.font='bold 38px '+font;const titleLines=lines(measure,pass.querySelector('.ecm-pass__top').textContent.trim(),850);
  measure.font='bold 40px '+font;const name=pass.querySelector('h2');const nameLines=name?lines(measure,name.textContent,850):[];
  measure.font='32px '+font;const instruction=pass.querySelector('.ecm-card-instruction');const instructionLines=instruction?lines(measure,instruction.textContent,850):[];
  measure.font='34px '+font;const textLines=printBlocks.flatMap(p=>lines(measure,p,850));
  const height=64+titleLines.length*54+24+630+nameLines.length*56+55+instructionLines.length*46+textLines.length*50+80;
  if(height>16000)throw Error('card_text_too_long');
  const c=canvas(mode==='qr'?800:1000,mode==='qr'?800:Math.max(1100,height)),ctx=c.getContext('2d');let y=mode==='qr'?100:64;ctx.fillStyle=getComputedStyle(pass).color||'#0B1F46';ctx.font='bold 38px '+font;
  if(mode!=='qr') {for(const l of titleLines){ctx.fillText(l,925,y);y+=54;}y+=24;}
  const svg=pass.querySelector('svg');if(!svg)throw Error('qr_missing');
  const xml=new XMLSerializer().serializeToString(svg);const url=URL.createObjectURL(new Blob([xml],{type:'image/svg+xml'}));const img=new Image();
   try{await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;img.src=url;});ctx.imageSmoothingEnabled=false;ctx.drawImage(img,mode==='qr'?100:200,y,600,600);}finally{URL.revokeObjectURL(url);}y+=630;
  if(mode!=='qr'){
   ctx.textAlign='center';ctx.font='bold 40px '+font;for(const l of nameLines){ctx.fillText(l,500,y);y+=56;}
   ctx.direction='ltr';ctx.font='28px monospace';ctx.fillText(pass.querySelector('code').textContent,500,y);y+=55;ctx.direction='rtl';ctx.font='28px '+font;
   ctx.font='32px '+font;for(const l of instructionLines){ctx.fillText(l,500,y);y+=46;}
   ctx.textAlign='right';ctx.font='34px '+font;
  }
  // Measure first; no text is painted beyond the canvas. Width is 90 mm on paper.
  if(mode!=='qr')textLines.forEach(l=>{ctx.fillText(l,925,y);y+=50;});
  return c;
 }
 async function card(target){const c=await cardCanvas(target);return pdf([c],(90/25.4*72)/c.width);}
 async function cards(targets){const canvases=[];for(const target of targets){canvases.push(await cardCanvas(target));}if(!canvases.length)throw Error('card_missing');return pdf(canvases,(90/25.4*72)/canvases[0].width);}
 async function report(){
  await document.fonts.ready;const table=document.querySelector('[data-export-table]');const headers=Array.from(table.querySelectorAll('thead th'),x=>x.textContent.trim());
  const rows=Array.from(table.querySelectorAll('tbody tr'),tr=>Array.from(tr.cells,td=>td.textContent.trim()));
  const w=Math.min(14000,Math.max(1600,headers.length*220)),column=(w-100)/headers.length,pageHeight=1800,canvases=[];let c,ctx,y;
  function newPage(){c=canvas(w,pageHeight);ctx=c.getContext('2d');ctx.fillStyle='#0B1F46';ctx.font='bold 30px Tahoma';ctx.fillText(document.querySelector('h1').textContent,w-50,30);y=95;ctx.font='bold 22px Tahoma';const hl=headers.map(t=>lines(ctx,t,column-20));const h=Math.max(...hl.map(l=>l.length))*30+24;drawRow(hl,h,true);canvases.push(c);}
  function drawRow(cells,h,header){cells.forEach((ls,i)=>{const x=w-50-(i+1)*column;ctx.fillStyle=header?'#EAF3F8':'#fff';ctx.fillRect(x,y,column,h);ctx.strokeStyle='#cbd5e1';ctx.strokeRect(x,y,column,h);ctx.fillStyle='#0B1F46';ls.forEach((l,j)=>ctx.fillText(l,x+column-10,y+10+j*30));});y+=h;}
  newPage();
  for(const row of rows){ctx.font='22px Tahoma';let ls=row.map(t=>lines(ctx,t,column-20));const fullHeight=Math.max(...ls.map(a=>a.length),1)*30+20;if(fullHeight<pageHeight-300 && y+fullHeight>pageHeight-50)newPage();while(ls.some(a=>a.length)){if(y+55>pageHeight-50)newPage();ctx.font='22px Tahoma';const available=Math.max(1,Math.floor((pageHeight-y-65)/30));const chunk=ls.map(a=>a.splice(0,available));const height=Math.max(...chunk.map(a=>a.length),1)*30+20;drawRow(chunk,height,false);if(ls.some(a=>a.length))newPage();}}
  return pdf(canvases);
 }
 window.ECMPDF={pdf,download,card,cards,report,lines};
 document.querySelectorAll('[data-export-pdf]').forEach(button=>button.addEventListener('click',async()=>{const text=button.textContent;button.disabled=true;button.textContent='در حال ساخت PDF…';try{download(await report(),'event-report.pdf');}catch(e){alert('ساخت PDF انجام نشد. از چاپ مستقل استفاده کنید.');}finally{button.disabled=false;button.textContent=text;}}));
})();

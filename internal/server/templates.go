package server

const swaggerUIHTML = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Office Converter — API Docs</title>
<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
<script>
SwaggerUIBundle({
  url: "/api/openapi.json",
  dom_id: "#swagger-ui",
  presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
  layout: "BaseLayout",
  deepLinking: true,
});
</script>
</body>
</html>`

const indexHTML = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Spreadsheet Converter</title>
<style>
  * { box-sizing: border-box; border-radius: 0; }
  body {
    font-family: ui-monospace, "Cascadia Code", monospace;
    background: #f5f5f5; color: #171717;
    min-height: 100vh; margin: 0;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  main { width: 100%; max-width: 560px; border: 2px solid #171717; background: #fff; }
  header { border-bottom: 2px solid #171717; background: #171717; padding: 16px 24px; }
  header h1 { margin: 0; font-size: 18px; text-transform: uppercase; color: #fff; }
  header p { margin: 4px 0 0; font-size: 12px; color: #a3a3a3; }
  .body { padding: 24px; }
  label.drop {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    border: 2px dashed #a3a3a3; padding: 48px 24px; cursor: pointer;
    text-align: center; transition: border-color .15s, background .15s;
  }
  label.drop:hover, label.drop.over { border-color: #171717; background: #fafafa; }
  .drop strong { font-size: 14px; }
  .drop span { font-size: 12px; color: #737373; }
  input[type=file] { display: none; }
  button {
    width: 100%; margin-top: 16px; border: 2px solid #171717; background: #171717;
    color: #fff; padding: 12px 16px; font: inherit; font-weight: bold;
    text-transform: uppercase; font-size: 13px; cursor: pointer;
    transition: background .15s, color .15s;
  }
  button:hover:not(:disabled) { background: #fff; color: #171717; }
  button:disabled { opacity: .3; cursor: not-allowed; }
  #status { margin: 12px 0 0; font-size: 12px; color: #525252; min-height: 16px; }
  .hint { font-size: 11px; color: #737373; margin-top: 8px; }
  .api-note { font-size: 10px; color: #525252; margin-top: 12px; line-height: 1.3; }
  .api-note code { background: #f5f5f5; padding: 1px 4px; }
</style>
</head>
<body>
<main>
  <header>
    <h1>Spreadsheet Converter</h1>
    <p>XLSB &rarr; XLSX | XLSX &rarr; ODS | ODS &rarr; XLSX</p>
  </header>
  <div class="body">
    <label class="drop" id="drop" for="file">
      <strong>Click or drop a file</strong>
      <span id="fname">no file selected</span>
      <input id="file" type="file" accept=".xlsb,.xlsx,.ods">
    </label>
    <div class="hint">Formats: .xlsb, .xlsx, .ods — the UI uses the matching explicit endpoint</div>
    <button id="btn" disabled>Convert</button>
    <p id="status"></p>
    <div class="api-note">Explicit endpoints: <code>/api/convert/xlsb-to-xlsx</code>, <code>/api/convert/xlsx-to-ods</code>, <code>/api/convert/ods-to-xlsx</code><br>JSON API: send <code>Content-Type: application/json</code> with <code>{"file": "base64..."}</code> or append <code>?format=json</code></div>
  </div>
</main>
<script>
var file=document.getElementById('file'),fname=document.getElementById('fname'),
    btn=document.getElementById('btn'),status=document.getElementById('status'),
    drop=document.getElementById('drop');
function getTargetExt(name){
  var n=name.toLowerCase();
  if(n.endsWith('.xlsb')||n.endsWith('.ods')) return '.xlsx';
  if(n.endsWith('.xlsx')) return '.ods';
  return null;
}
function getConvertEndpoint(name){
  var n=name.toLowerCase();
  if(n.endsWith('.xlsb')) return '/api/convert/xlsb-to-xlsx';
  if(n.endsWith('.xlsx')) return '/api/convert/xlsx-to-ods';
  if(n.endsWith('.ods')) return '/api/convert/ods-to-xlsx';
  return '/api/convert';
}
function pick(f){
  if(!f)return;
  var tgt=getTargetExt(f.name);
  if(!tgt){status.textContent='Error: select .xlsb, .xlsx or .ods';btn.disabled=true;return;}
  var dt=new DataTransfer();dt.items.add(f);file.files=dt.files;
  fname.textContent=f.name;btn.disabled=false;
  var ep = getConvertEndpoint(f.name);
  status.textContent = 'Ready to convert via ' + ep;
}
file.addEventListener('change',function(){pick(file.files[0]);});
drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('over');});
drop.addEventListener('dragleave',function(){drop.classList.remove('over');});
drop.addEventListener('drop',function(e){e.preventDefault();drop.classList.remove('over');pick(e.dataTransfer.files[0]);});
btn.addEventListener('click',async function(){
  if(!file.files[0])return;
  var f=file.files[0];
  var tgt=getTargetExt(f.name);
  var ep=getConvertEndpoint(f.name);
  btn.disabled=true;status.textContent='Converting via '+ep+' ... (LibreOffice may take a few seconds)';
  var form=new FormData();form.append('file',f);
  try{
    var res=await fetch(ep,{method:'POST',body:form});
    if(!res.ok){var m=await res.text().catch(function(){return res.statusText;});status.textContent='Failure: '+m;btn.disabled=false;return;}
    var blob=await res.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download=f.name.replace(/\.[^.]+$/i, tgt);a.click();URL.revokeObjectURL(url);
    status.textContent='Ready. Download started.';btn.disabled=false;
  }catch(err){status.textContent='Network error: '+err.message;btn.disabled=false;}
});
</script>
</body>
</html>`

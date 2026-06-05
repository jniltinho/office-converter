# Axios Examples (JavaScript / Node.js)

Install dependencies:

```bash
npm install axios form-data
```

Base URL: `http://localhost:8080`

## Multipart upload — download the converted file

```js
import axios from 'axios';
import FormData from 'form-data';
import fs from 'fs';

const form = new FormData();
form.append('file', fs.createReadStream('planilha.xlsb'), 'planilha.xlsb');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx',
  form,
  { headers: form.getHeaders(), responseType: 'arraybuffer' }
);

fs.writeFileSync('planilha.xlsx', response.data);
```

## Multipart upload — get JSON envelope (`?format=json`)

```js
import axios from 'axios';
import FormData from 'form-data';
import fs from 'fs';

const form = new FormData();
form.append('file', fs.createReadStream('planilha.xlsb'), 'planilha.xlsb');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json',
  form,
  { headers: form.getHeaders() }
);

const converted = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', converted);
```

## Pure JSON API (base64)

```js
import axios from 'axios';
import fs from 'fs';

const fileBuffer = fs.readFileSync('planilha.xlsb');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx',
  {
    file: fileBuffer.toString('base64'),
    filename: 'planilha.xlsb',
  },
  { headers: { 'Content-Type': 'application/json' } }
);

const converted = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', converted);
```

## Smart endpoint (auto-detects format)

```js
import axios from 'axios';
import FormData from 'form-data';
import fs from 'fs';

const form = new FormData();
form.append('file', fs.createReadStream('dados.xlsx'), 'dados.xlsx');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert',
  form,
  { headers: form.getHeaders(), responseType: 'arraybuffer' }
);

fs.writeFileSync('dados.ods', response.data);
```

## Error handling

```js
import axios from 'axios';
import FormData from 'form-data';
import fs from 'fs';

try {
  const form = new FormData();
  form.append('file', fs.createReadStream('planilha.xlsb'), 'planilha.xlsb');

  const response = await axios.post(
    'http://localhost:8080/api/v1/convert/xlsb-to-xlsx',
    form,
    { headers: form.getHeaders(), responseType: 'arraybuffer' }
  );

  fs.writeFileSync('planilha.xlsx', response.data);
  console.log('Conversion successful');
} catch (err) {
  if (err.response) {
    const body = Buffer.from(err.response.data).toString();
    console.error('API error:', JSON.parse(body).error);
  } else {
    console.error('Request error:', err.message);
  }
}
```

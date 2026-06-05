# Exemplos com Axios (JavaScript / Node.js)

Instale as dependências:

```bash
npm install axios form-data
```

URL base: `http://localhost:8080`

## Upload multipart — baixar o arquivo convertido

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

## Upload multipart — obter envelope JSON (`?format=json`)

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

const convertido = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', convertido);
```

## API JSON pura (base64)

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

const convertido = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', convertido);
```

## Endpoint inteligente (detecta o formato automaticamente)

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

## Tratamento de erros

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
  console.log('Conversão concluída com sucesso');
} catch (err) {
  if (err.response) {
    const body = Buffer.from(err.response.data).toString();
    console.error('Erro da API:', JSON.parse(body).error);
  } else {
    console.error('Erro de rede:', err.message);
  }
}
```

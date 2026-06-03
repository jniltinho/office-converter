# office-converter

Servidor HTTP para conversão de planilhas entre os formatos **XLSB**, **XLSX** e **ODS** usando o LibreOffice em modo headless.

Oferece uma interface web simples e uma API REST flexível que aceita tanto uploads tradicionais (multipart) quanto requisições puras em JSON (base64).

## Funcionalidades

- Detecção automática de formato (endpoint inteligente)
- Endpoints explícitos e tipados para integrações confiáveis
- Interface web com arrastar e soltar
- **Dois estilos de API**:
  - `multipart/form-data` → retorna o arquivo diretamente (ou JSON com `?format=json`)
  - `application/json` com base64 → sempre responde em JSON
- Encerramento gracioso (graceful shutdown) em SIGINT/SIGTERM
- Porta e interface de bind configuráveis
- Limite de conversões concorrentes (seguro para máquinas pequenas)
- Tamanho máximo de upload: 100 MiB
- Pronto para Docker e orquestradores (endpoint de health check)

## Requisitos

- **LibreOffice** (comando `soffice` deve estar no `$PATH`) — necessário para realizar as conversões.
- Go 1.26+ (apenas se for compilar a partir do código-fonte).

A imagem oficial do Docker já inclui o LibreOffice.

## Instalação

### Compilando a partir do código

```bash
git clone https://github.com/.../office-converter.git
cd office-converter

make build          # Binário estático otimizado (recomendado)
# ou
go build -o office-converter .
```

Exemplos de cross-compilation estão disponíveis no Makefile (`make build-linux-amd64`, `make build-all`, etc.).

### Docker

```bash
docker build -t office-converter .
# ou
make docker-build
```

A imagem usa build em dois estágios: um builder `golang:1.26-bookworm` gera um binário estático, e o estágio final é `debian:12-slim` com apenas `libreoffice-calc` e fontes mínimas (~350 MB). O servidor roda como usuário sem privilégios (`uid 10001`).

## Executando

### Binário

```bash
./office-converter
```

**Flags:**

| Flag     | Descrição                              | Padrão             |
|----------|----------------------------------------|--------------------|
| `--port` | Porta TCP para escutar                 | `8080`             |
| `--host` | Host/interface para fazer o bind       | (todas interfaces) |

Exemplos:

```bash
./office-converter --port 9000
./office-converter --host 127.0.0.1 --port 8080
```

### Usando Make

```bash
make run                    # executa com `go run`
make run ARGS="--port 9000"
```

### Docker

#### Execução básica

```bash
docker run --rm -p 8080:8080 office-converter
```

#### Porta customizada via variável de ambiente

```bash
docker run --rm -p 9000:9000 -e OFFICE_PORT=9000 office-converter
# ou usando o Make
PORT=9000 make docker-run
```

#### Variáveis de ambiente suportadas

| Variável              | Descrição                                 | Padrão    |
|-----------------------|-------------------------------------------|-----------|
| `OFFICE_PORT`         | Porta HTTP                                | `8080`    |
| `OFFICE_HOST`         | Endereço de bind                          | (todas)   |
| `OFFICE_TLS_ENABLED`  | Habilitar HTTPS (`true`/`false`)          | `false`   |
| `OFFICE_TLS_CERT`     | Caminho para o certificado TLS            | —         |
| `OFFICE_TLS_KEY`      | Caminho para a chave privada TLS          | —         |
| `OFFICE_CONFIG`       | Caminho para um arquivo `config.toml`     | —         |

#### Montar um `config.toml`

```bash
docker run --rm -p 8080:8080 \
  -v "$(pwd)/config.toml:/home/appuser/config.toml:ro" \
  -e OFFICE_CONFIG=/home/appuser/config.toml \
  office-converter
```

#### Habilitar TLS

```bash
docker run --rm -p 8443:8443 \
  -v /etc/ssl/certs/server.crt:/certs/server.crt:ro \
  -v /etc/ssl/private/server.key:/certs/server.key:ro \
  -e OFFICE_PORT=8443 \
  -e OFFICE_TLS_ENABLED=true \
  -e OFFICE_TLS_CERT=/certs/server.crt \
  -e OFFICE_TLS_KEY=/certs/server.key \
  office-converter
```

#### Docker Compose

```yaml
services:
  office-converter:
    image: office-converter:latest
    build: .
    ports:
      - "8080:8080"
    environment:
      OFFICE_PORT: "8080"
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/healthz"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
```

Execute com:

```bash
docker compose up -d
docker compose logs -f
```

#### Health check

O endpoint `/healthz` é adequado para Docker, probes de liveness/readiness do Kubernetes e load balancers:

```bash
# Exemplo de liveness probe no Kubernetes (spec do Pod)
livenessProbe:
  httpGet:
    path: /healthz
    port: 8080
  initialDelaySeconds: 10
  periodSeconds: 30
```

O container roda como usuário sem privilégios (`uid 10001`).

## Interface Web

Acesse `http://localhost:8080` no navegador.

- Arraste e solte ou clique para selecionar o arquivo.
- Formatos suportados: `.xlsb`, `.xlsx`, `.ods`
- A interface escolhe automaticamente o endpoint correto de conversão.

## Referência da API

### Endpoints

| Método | Caminho                              | Descrição                                      |
|--------|--------------------------------------|------------------------------------------------|
| POST   | `/api/v1/convert`                    | Endpoint inteligente (detecta a direção)       |
| POST   | `/api/v1/convert/xlsb-to-xlsx`       | Converte `.xlsb` → `.xlsx`                     |
| POST   | `/api/v1/convert/xlsx-to-ods`        | Converte `.xlsx` → `.ods`                      |
| POST   | `/api/v1/convert/ods-to-xlsx`        | Converte `.ods` → `.xlsx`                      |
| GET    | `/healthz`                           | Health check (para load balancers / Kubernetes)|

### Exemplos com curl

Aqui estão exemplos práticos e prontos para copiar usando `curl`.

#### Converter e baixar o arquivo diretamente (recomendado)

```bash
# Endpoint explícito: .xlsb → .xlsx
curl -F "file=@planilha.xlsb" \
     http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
     -o planilha.xlsx

# Endpoint inteligente (detecta automaticamente o formato de saída)
curl -F "file=@dados.xlsb" \
     http://localhost:8080/api/v1/convert \
     -o dados.xlsx
```

#### Obter resposta em JSON usando upload multipart (`?format=json`)

```bash
# Ver a resposta JSON completa
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" | jq .

# Extrair e salvar o arquivo em um único comando
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" \
     | jq -r '.data' | base64 -d > planilha_convertida.xlsx
```

#### API JSON pura (enviando o arquivo em base64)

Aqui vão exemplos focados na API JSON pura.

**Converter .xlsb → .xlsx usando a API JSON**

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 planilha.xlsb)\",\"filename\":\"planilha.xlsb\"}" \
  | jq -r '.data' | base64 -d > planilha.xlsx
```

**Usando o endpoint inteligente `/api/convert` (detecta a direção automaticamente)**

```bash
curl -X POST http://localhost:8080/api/v1/convert \
  -H "Content-Type: application/json" \
  -d @- <<EOF | jq -r '.data' | base64 -d > saida.ods
{
  "file": "$(base64 -w0 planilha.xlsx)",
  "filename": "planilha.xlsx"
}
EOF
```

**Converter .xlsx → .ods**

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsx-to-ods \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 dados.xlsx)\",\"filename\":\"dados.xlsx\"}" \
  | jq -r '.data' | base64 -d > dados.ods
```

**Ver a resposta completa (útil para debug)**

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" | jq .
```

**Com host/porta customizada**

```bash
curl -X POST http://127.0.0.1:9000/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 input.xlsb)\",\"filename\":\"input.xlsb\"}" \
  | jq -r '.data' | base64 -d > output.xlsx
```

#### Usando uma porta diferente

```bash
curl -F "file=@planilha.xlsb" http://localhost:9000/api/v1/convert -o out.xlsx
```

#### Health check

```bash
curl -s http://localhost:8080/healthz
# Requisição HEAD (sem corpo)
curl -sI http://localhost:8080/healthz
```

> **Dica**: Instale o `jq` (`sudo apt install jq` ou `brew install jq`). Ele facilita muito o uso da API JSON.

### Exemplos com Axios (JavaScript/Node.js)

Instale: `npm install axios form-data`

#### Upload multipart — baixar o arquivo convertido

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

#### Upload multipart — obter envelope JSON (`?format=json`)

```js
const response = await axios.post(
  'http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json',
  form,
  { headers: form.getHeaders() }
);

const convertido = Buffer.from(response.data.data, 'base64');
fs.writeFileSync('planilha.xlsx', convertido);
```

#### API JSON pura (base64)

```js
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

#### Endpoint inteligente (detecta o formato automaticamente)

```js
const form = new FormData();
form.append('file', fs.createReadStream('dados.xlsx'), 'dados.xlsx');

const response = await axios.post(
  'http://localhost:8080/api/v1/convert',
  form,
  { headers: form.getHeaders(), responseType: 'arraybuffer' }
);

fs.writeFileSync('dados.ods', response.data);
```

---

### Postman

#### Importar a collection

1. Abra o Postman → **Import** → cole o JSON abaixo ou salve como `office-converter.postman_collection.json`.

```json
{
  "info": {
    "name": "office-converter",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "variable": [
    { "key": "base_url", "value": "http://localhost:8080" }
  ],
  "item": [
    {
      "name": "Converter XLSB → XLSX (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsb-to-xlsx",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/caminho/para/planilha.xlsb" }]
        }
      }
    },
    {
      "name": "Converter XLSX → ODS (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsx-to-ods",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/caminho/para/dados.xlsx" }]
        }
      }
    },
    {
      "name": "Converter ODS → XLSX (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/ods-to-xlsx",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/caminho/para/tabela.ods" }]
        }
      }
    },
    {
      "name": "Endpoint inteligente (multipart)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert",
        "body": {
          "mode": "formdata",
          "formdata": [{ "key": "file", "type": "file", "src": "/caminho/para/arquivo.xlsb" }]
        }
      }
    },
    {
      "name": "Converter XLSB → XLSX (JSON/base64)",
      "request": {
        "method": "POST",
        "url": "{{base_url}}/api/v1/convert/xlsb-to-xlsx",
        "header": [{ "key": "Content-Type", "value": "application/json" }],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"file\": \"<conteúdo em base64>\",\n  \"filename\": \"planilha.xlsb\"\n}"
        }
      }
    },
    {
      "name": "Health Check",
      "request": {
        "method": "GET",
        "url": "{{base_url}}/healthz"
      }
    }
  ]
}
```

#### Configuração manual (sem importar)

1. Defina a variável **base URL**: `http://localhost:8080`
2. Crie uma requisição **POST** para `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
3. Aba **Body** → selecione **form-data**
4. Adicione a chave `file`, mude o tipo para **File** e selecione o arquivo `.xlsb`
5. Clique em **Send** — o corpo da resposta é o arquivo `.xlsx` convertido (use **Save to a file** no painel de resposta)

Para JSON/base64:
1. Aba **Body** → selecione **raw** → tipo **JSON**
2. Cole: `{ "file": "<base64>", "filename": "planilha.xlsb" }`
3. A resposta JSON contém o arquivo convertido no campo `data` (base64)

---

### Formatos de requisição e resposta da API JSON pura (base64)

Use `Content-Type: application/json`.

**Corpo da requisição (`ConvertRequest`):**

```json
{
  "file": "<conteúdo do arquivo codificado em base64>",
  "filename": "planilha.xlsb"
}
```

- `file` (obrigatório): string Base64 do conteúdo da planilha original.
- `filename` (recomendado): Usado para detectar o formato no endpoint inteligente e para nomear o arquivo de saída.

**Resposta de sucesso (`ConvertResponse`):**

```json
{
  "success": true,
  "filename": "planilha.xlsx",
  "content_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "size": 45231,
  "data": "<arquivo convertido em base64>"
}
```

**Resposta de erro:**

```json
{
  "error": "campo 'file' ausente no formulário"
}
```

### Health Check

```bash
curl http://localhost:8080/healthz
# ou
curl -I http://localhost:8080/healthz
```

Retorna `200 OK` com o corpo `ok`.

## Limites e Comportamento

- **Tamanho máximo do arquivo**: 100 MiB (aplicado pelo middleware).
- **Conversões concorrentes**: Limitado a 2 por padrão. Cada conversão usa um perfil de usuário temporário próprio do LibreOffice.
- **Timeouts**: 60 segundos por conversão (após adquirir um slot de worker).
- **Encerramento gracioso**: 15 segundos para finalizar requisições em andamento.

## Alvos do Makefile

```bash
make build              # Compila o binário otimizado (padrão)
make run                # Executa com go run (ARGS="--port 9000")
make docker-build
make docker-run         # PORT=9000 make docker-run
make docker-up
make fmt
make clean
make build-all          # Cross-compile para plataformas comuns
make help
```

## Desenvolvimento

```bash
make fmt
make build-debug        # Binário com símbolos de debug
```

## Observações

- O servidor precisa que `soffice` (LibreOffice Calc) esteja instalado e acessível no PATH.
- Arquivos temporários são criados em `/tmp` e removidos automaticamente.
- A interface web embutida é autocontida (único arquivo HTML + CSS + JS).

---

Este projeto foi originalmente escrito com comentários e interface em português brasileiro, depois totalmente traduzido para inglês com documentação no estilo godoc e suporte completo a API JSON.
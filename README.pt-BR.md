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

### Exemplos por cliente

Os exemplos completos estão na pasta [`DOCUMENTS/`](DOCUMENTS/):

| Guia | Descrição |
|------|-----------|
| [DOCUMENTS/curl.pt-BR.md](DOCUMENTS/curl.pt-BR.md) | Exemplos `curl` — multipart, JSON/base64, endpoint inteligente, health check |
| [DOCUMENTS/axios.pt-BR.md](DOCUMENTS/axios.pt-BR.md) | Axios (Node.js) — multipart, JSON/base64, tratamento de erros |
| [DOCUMENTS/postman.pt-BR.md](DOCUMENTS/postman.pt-BR.md) | Guia de configuração do Postman |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Collection do Postman pronta para importar |

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
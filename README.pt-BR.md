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

```bash
docker run --rm -p 8080:8080 office-converter

# Porta personalizada
PORT=9000 make docker-run
# ou
docker run --rm -p 9000:9000 office-converter --port 9000
```

O container roda como usuário sem privilégios (non-root).

## Interface Web

Acesse `http://localhost:8080` no navegador.

- Arraste e solte ou clique para selecionar o arquivo.
- Formatos suportados: `.xlsb`, `.xlsx`, `.ods`
- A interface escolhe automaticamente o endpoint correto de conversão.

## Referência da API

### Endpoints

| Método | Caminho                              | Descrição                                      |
|--------|--------------------------------------|------------------------------------------------|
| POST   | `/api/convert`                       | Endpoint inteligente (detecta a direção)       |
| POST   | `/api/convert/xlsb-to-xlsx`          | Converte `.xlsb` → `.xlsx`                     |
| POST   | `/api/convert/xlsx-to-ods`           | Converte `.xlsx` → `.ods`                      |
| POST   | `/api/convert/ods-to-xlsx`           | Converte `.ods` → `.xlsx`                      |
| GET    | `/healthz`                           | Health check (para load balancers / Kubernetes)|

### Exemplos com curl

Aqui estão exemplos práticos e prontos para copiar usando `curl`.

#### Converter e baixar o arquivo diretamente (recomendado)

```bash
# Endpoint explícito: .xlsb → .xlsx
curl -F "file=@planilha.xlsb" \
     http://localhost:8080/api/convert/xlsb-to-xlsx \
     -o planilha.xlsx

# Endpoint inteligente (detecta automaticamente o formato de saída)
curl -F "file=@dados.xlsb" \
     http://localhost:8080/api/convert \
     -o dados.xlsx
```

#### Obter resposta em JSON usando upload multipart (`?format=json`)

```bash
# Ver a resposta JSON completa
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/convert/xlsb-to-xlsx?format=json" | jq .

# Extrair e salvar o arquivo em um único comando
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/convert/xlsb-to-xlsx?format=json" \
     | jq -r '.data' | base64 -d > planilha_convertida.xlsx
```

#### API JSON pura (enviando o arquivo em base64)

Aqui vão exemplos focados na API JSON pura.

**Converter .xlsb → .xlsx usando a API JSON**

```bash
curl -X POST http://localhost:8080/api/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 planilha.xlsb)\",\"filename\":\"planilha.xlsb\"}" \
  | jq -r '.data' | base64 -d > planilha.xlsx
```

**Usando o endpoint inteligente `/api/convert` (detecta a direção automaticamente)**

```bash
curl -X POST http://localhost:8080/api/convert \
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
curl -X POST http://localhost:8080/api/convert/xlsx-to-ods \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 dados.xlsx)\",\"filename\":\"dados.xlsx\"}" \
  | jq -r '.data' | base64 -d > dados.ods
```

**Ver a resposta completa (útil para debug)**

```bash
curl -X POST http://localhost:8080/api/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" | jq .
```

**Com host/porta customizada**

```bash
curl -X POST http://127.0.0.1:9000/api/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 input.xlsb)\",\"filename\":\"input.xlsb\"}" \
  | jq -r '.data' | base64 -d > output.xlsx
```

#### Usando uma porta diferente

```bash
curl -F "file=@planilha.xlsb" http://localhost:9000/api/convert -o out.xlsx
```

#### Health check

```bash
curl -s http://localhost:8080/healthz
# Requisição HEAD (sem corpo)
curl -sI http://localhost:8080/healthz
```

> **Dica**: Instale o `jq` (`sudo apt install jq` ou `brew install jq`). Ele facilita muito o uso da API JSON.

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
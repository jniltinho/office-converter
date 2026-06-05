# curl Examples

Base URL: `http://localhost:8080`

> **Tip**: Install `jq` (`sudo apt install jq` or `brew install jq`) — it makes working with the JSON API much easier.

## Multipart upload — download the converted file directly

```bash
# Explicit endpoint: .xlsb → .xlsx
curl -F "file=@planilha.xlsb" \
     http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
     -o planilha.xlsx

# .xlsx → .ods
curl -F "file=@dados.xlsx" \
     http://localhost:8080/api/v1/convert/xlsx-to-ods \
     -o dados.ods

# .ods → .xlsx
curl -F "file=@tabela.ods" \
     http://localhost:8080/api/v1/convert/ods-to-xlsx \
     -o tabela.xlsx

# Smart endpoint (auto-detects output format from filename)
curl -F "file=@dados.xlsb" \
     http://localhost:8080/api/v1/convert \
     -o dados.xlsx
```

## Multipart upload — get JSON envelope (`?format=json`)

```bash
# See the full JSON response
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" | jq .

# Extract and save the converted file in one command
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" \
     | jq -r '.data' | base64 -d > planilha_convertida.xlsx
```

## Pure JSON API (base64)

### Convert .xlsb → .xlsx

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 planilha.xlsb)\",\"filename\":\"planilha.xlsb\"}" \
  | jq -r '.data' | base64 -d > planilha.xlsx
```

### Convert .xlsx → .ods

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsx-to-ods \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 dados.xlsx)\",\"filename\":\"dados.xlsx\"}" \
  | jq -r '.data' | base64 -d > dados.ods
```

### Convert .ods → .xlsx

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" \
  | jq -r '.data' | base64 -d > tabela.xlsx
```

### Smart endpoint (auto-detects direction)

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

### See the full response (useful for debugging)

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" | jq .
```

### Custom host/port

```bash
curl -X POST http://127.0.0.1:9000/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 input.xlsb)\",\"filename\":\"input.xlsb\"}" \
  | jq -r '.data' | base64 -d > output.xlsx

# Multipart on a custom port
curl -F "file=@planilha.xlsb" http://localhost:9000/api/v1/convert -o out.xlsx
```

## Health check

```bash
curl -s http://localhost:8080/healthz
# HEAD request (no body)
curl -sI http://localhost:8080/healthz
```

# Exemplos com curl

URL base: `http://localhost:8080`

> **Dica**: Instale o `jq` (`sudo apt install jq` ou `brew install jq`). Ele facilita muito o uso da API JSON.

## Upload multipart — baixar o arquivo convertido diretamente

```bash
# Endpoint explícito: .xlsb → .xlsx
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

# Endpoint inteligente (detecta o formato de saída pelo nome do arquivo)
curl -F "file=@dados.xlsb" \
     http://localhost:8080/api/v1/convert \
     -o dados.xlsx
```

## Upload multipart — obter envelope JSON (`?format=json`)

```bash
# Ver a resposta JSON completa
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" | jq .

# Extrair e salvar o arquivo convertido em um único comando
curl -F "file=@planilha.xlsb" \
     "http://localhost:8080/api/v1/convert/xlsb-to-xlsx?format=json" \
     | jq -r '.data' | base64 -d > planilha_convertida.xlsx
```

## API JSON pura (base64)

### Converter .xlsb → .xlsx

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 planilha.xlsb)\",\"filename\":\"planilha.xlsb\"}" \
  | jq -r '.data' | base64 -d > planilha.xlsx
```

### Converter .xlsx → .ods

```bash
curl -X POST http://localhost:8080/api/v1/convert/xlsx-to-ods \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 dados.xlsx)\",\"filename\":\"dados.xlsx\"}" \
  | jq -r '.data' | base64 -d > dados.ods
```

### Converter .ods → .xlsx

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" \
  | jq -r '.data' | base64 -d > tabela.xlsx
```

### Endpoint inteligente (detecta a direção automaticamente)

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

### Ver a resposta completa (útil para debug)

```bash
curl -X POST http://localhost:8080/api/v1/convert/ods-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 tabela.ods)\",\"filename\":\"tabela.ods\"}" | jq .
```

### Host/porta customizada

```bash
curl -X POST http://127.0.0.1:9000/api/v1/convert/xlsb-to-xlsx \
  -H "Content-Type: application/json" \
  -d "{\"file\":\"$(base64 -w0 input.xlsb)\",\"filename\":\"input.xlsb\"}" \
  | jq -r '.data' | base64 -d > output.xlsx

# Multipart em porta customizada
curl -F "file=@planilha.xlsb" http://localhost:9000/api/v1/convert -o out.xlsx
```

## Health check

```bash
curl -s http://localhost:8080/healthz
# Requisição HEAD (sem corpo)
curl -sI http://localhost:8080/healthz
```

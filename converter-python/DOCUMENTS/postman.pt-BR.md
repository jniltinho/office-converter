# Guia do Postman

## Importar a collection

1. Abra o Postman → **Import**
2. Selecione o arquivo [`postman_collection.json`](postman_collection.json) desta pasta
3. A collection inclui a variável `base_url` configurada como `http://localhost:8080` — altere conforme o seu servidor

A collection cobre todos os endpoints nos modos multipart e JSON/base64.

## Configuração manual (sem importar)

### Upload multipart

1. Crie uma requisição **POST** para `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
2. Aba **Body** → selecione **form-data**
3. Adicione a chave `file`, mude o tipo para **File** e selecione o arquivo `.xlsb`
4. Clique em **Send**
5. No painel de resposta clique em **Save to a file** para baixar o `.xlsx` convertido

### JSON / base64

1. Crie uma requisição **POST** para `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
2. Aba **Body** → selecione **raw** → tipo **JSON**
3. Cole o corpo:

```json
{
  "file": "<conteúdo do arquivo em base64>",
  "filename": "planilha.xlsb"
}
```

4. Clique em **Send** — a resposta JSON contém o arquivo convertido no campo `data` (base64)

### Obter resposta JSON de uma requisição multipart

Adicione `?format=json` à URL de qualquer endpoint e o servidor retorna um envelope JSON em vez de download binário:

```
POST {{base_url}}/api/v1/convert/xlsb-to-xlsx?format=json
```

Ou adicione o header:

```
Accept: application/json
```

## Variável de ambiente

Configure a variável de ambiente `base_url` no Postman para alternar entre servidores sem editar cada requisição:

| Ambiente     | `base_url`                       |
|--------------|----------------------------------|
| Local        | `http://localhost:8080`          |
| Homologação  | `https://staging.example.com`    |
| Produção     | `https://api.example.com`        |

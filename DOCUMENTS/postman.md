# Postman Guide

## Import the collection

1. Open Postman → **Import**
2. Select the file [`postman_collection.json`](postman_collection.json) from this folder
3. The collection includes a `base_url` variable set to `http://localhost:8080` — change it to match your server

The collection covers all endpoints in both multipart and JSON/base64 modes.

## Manual setup (without importing)

### Multipart upload

1. Create a new **POST** request to `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
2. **Body** tab → select **form-data**
3. Add key `file`, change type to **File**, pick your `.xlsb` file
4. Click **Send**
5. In the response panel click **Save to a file** to download the converted `.xlsx`

### JSON / base64

1. Create a new **POST** request to `{{base_url}}/api/v1/convert/xlsb-to-xlsx`
2. **Body** tab → select **raw** → set type to **JSON**
3. Paste the body:

```json
{
  "file": "<base64-encoded file content>",
  "filename": "planilha.xlsb"
}
```

4. Click **Send** — the response JSON contains the converted file in the `data` field (base64)

### Get JSON response from a multipart request

Append `?format=json` to any endpoint URL and the server returns a JSON envelope instead of a binary download:

```
POST {{base_url}}/api/v1/convert/xlsb-to-xlsx?format=json
```

Or add the header:

```
Accept: application/json
```

## Environment variable

Set a Postman environment variable `base_url` to switch between servers without editing every request:

| Environment  | `base_url`                   |
|--------------|------------------------------|
| Local        | `http://localhost:8080`      |
| Staging      | `https://staging.example.com`|
| Production   | `https://api.example.com`    |

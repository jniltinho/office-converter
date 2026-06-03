# office-converter

Servidor HTTP para conversão de planilhas entre os formatos **XLSB**, **XLSX** e **ODS** usando o LibreOffice em modo headless.

![Interface Web](DOCUMENTS/screenshot/screenshot_home.png)

## Funcionalidades

- Endpoint inteligente com detecção automática de formato
- Endpoints explícitos e tipados para integrações confiáveis
- Interface web com arrastar e soltar
- Dois estilos de API: `multipart/form-data` (retorna o arquivo) e `application/json` com base64 (retorna JSON)
- Suporte a TLS/HTTPS
- Configurável via flags, variáveis de ambiente ou `config.toml`
- Pronto para Docker e Kubernetes (endpoint de health check)
- Encerramento gracioso, limite de 100 MiB, 2 conversões concorrentes

## Requisitos

- **LibreOffice** (`soffice` no `$PATH`) — a imagem Docker oficial já inclui.
- Go 1.26+ (apenas para compilar a partir do código-fonte).

## Quick Start

### Binário

```bash
make build
./office-converter serve
```

### Docker

```bash
docker build -t office-converter .
docker run --rm -p 8080:8080 office-converter
```

Acesse `http://localhost:8080` no navegador.

## Endpoints da API

| Método | Caminho                         | Descrição                                    |
|--------|---------------------------------|----------------------------------------------|
| POST   | `/api/v1/convert`               | Endpoint inteligente (detecta a direção)     |
| POST   | `/api/v1/convert/xlsb-to-xlsx`  | `.xlsb` → `.xlsx`                            |
| POST   | `/api/v1/convert/xlsx-to-ods`   | `.xlsx` → `.ods`                             |
| POST   | `/api/v1/convert/ods-to-xlsx`   | `.ods` → `.xlsx`                             |
| GET    | `/healthz`                      | Health check                                 |

## Documentação

| Guia | Descrição |
|------|-----------|
| [DOCUMENTS/README.md](DOCUMENTS/README.md) | Referência completa: flags, env vars, Docker, config, Makefile |
| [DOCUMENTS/curl.pt-BR.md](DOCUMENTS/curl.pt-BR.md) | Exemplos com `curl` |
| [DOCUMENTS/axios.pt-BR.md](DOCUMENTS/axios.pt-BR.md) | Exemplos com Axios (Node.js) |
| [DOCUMENTS/postman.pt-BR.md](DOCUMENTS/postman.pt-BR.md) | Guia de configuração do Postman |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Collection do Postman pronta para importar |
| [DOCUMENTS/scripts/README.md](DOCUMENTS/scripts/README.md) | Scripts de testes de integração |

---

Also available in [English](README.md).

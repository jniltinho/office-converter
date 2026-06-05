# office-converter

Servidor HTTP para conversão de planilhas entre os formatos **XLSB**, **XLSX** e **ODS** usando o LibreOffice em modo headless.

Implementação em PHP executando sob o [FrankenPHP](https://frankenphp.dev/) em modo worker dentro de uma imagem Docker baseada em `debian:12-slim`, ou sob o servidor embutido do PHP (`php -S`) para desenvolvimento local.

![Interface Web](DOCUMENTS/screenshot/screenshot_home.png)

## Funcionalidades

- Endpoint inteligente com detecção automática de formato
- Endpoints explícitos e tipados para integrações confiáveis
- Interface web com arrastar e soltar
- Dois estilos de API: `multipart/form-data` (retorna o arquivo) e `application/json` com base64 (retorna JSON)
- Totalmente configurável via variáveis de ambiente — veja [`env.example`](env.example) para um modelo documentado
- Pronto para Docker e Kubernetes (endpoint de health check)
- Limite de 100 MiB, 2 slots de conversão concorrente (concorrência efetiva é 2 por padrão sob os workers do FrankenPHP; múltiplos workers executam em paralelo)
- Sem Swagger UI (funcionalidade removida)

## Quick Start

### Docker (recomendado)

```bash
docker build -t office-converter .
docker run --rm -p 8080:8080 office-converter
```

O container inicia o FrankenPHP em modo worker (app Slim):

```bash
frankenphp run --config /tmp/Caddyfile --adapter caddyfile
# (Caddyfile carrega o worker em /app/api/public/index.php)
```

Acesse `http://localhost:8080` no navegador.

### FrankenPHP (desenvolvimento local — modo worker)

Se você tiver o [binário do FrankenPHP](https://frankenphp.dev/docs/install/) instalado localmente, pode rodar a aplicação no mesmo modo worker usado em produção.

Crie um `Caddyfile` local:

```
{
    auto_https off
}

:8080 {
    php_server {
        worker {
            file api/public/index.php
            match *
        }
    }
}
```

Em seguida inicie o servidor:

```bash
frankenphp run --config Caddyfile --adapter caddyfile
```

Ou use o subcomando php-server para um modo mais simples (sem loop worker — um processo por requisição):

```bash
frankenphp php-server --listen :8080 --root api/public/
```

### Servidor embutido do PHP (alternativa)

```bash
# requer soffice no PATH
php -d upload_max_filesize=100M -d post_max_size=101M -S 0.0.0.0:8080 api/public/index.php
```

Ou simplesmente:

```bash
make serve
```

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
| [DOCUMENTS/README.md](DOCUMENTS/README.md) | Referência completa: variáveis de ambiente, Docker, Makefile |
| [DOCUMENTS/curl.pt-BR.md](DOCUMENTS/curl.pt-BR.md) | Exemplos com `curl` |
| [DOCUMENTS/axios.pt-BR.md](DOCUMENTS/axios.pt-BR.md) | Exemplos com Axios (Node.js) |
| [DOCUMENTS/postman.pt-BR.md](DOCUMENTS/postman.pt-BR.md) | Guia de configuração do Postman |
| [DOCUMENTS/postman_collection.json](DOCUMENTS/postman_collection.json) | Collection do Postman pronta para importar |
| [DOCUMENTS/scripts/README.md](DOCUMENTS/scripts/README.md) | Scripts de testes de integração |

---

Also available in [English](README.md).

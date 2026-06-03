# Test Scripts for office-converter

Bash-based integration tests that exercise the HTTP API using real spreadsheet files and `curl`.

## Quick start

```bash
# 1. Generate (or refresh) sample .xlsx / .ods files
./scripts/generate-samples.sh

# 2. Run everything (starts the local binary on a test port, runs tests, stops it)
./scripts/run-integration-tests.sh
```

## Scripts

| Script                        | Purpose |
|-------------------------------|---------|
| `generate-samples.sh`         | Creates `testdata/sample.xlsx` and `sample.ods` using LibreOffice from a CSV. For `.xlsb` see notes below. |
| `test-health.sh`              | Minimal health check (GET + HEAD on `/healthz`). |
| `test-api.sh`                 | Full matrix: multipart direct download, `?format=json`, pure JSON (base64), smart endpoint, error cases. |
| `run-integration-tests.sh`    | Orchestrator. Starts server (binary or docker), waits for `/healthz`, runs the above, cleans up. |

## Common usage

```bash
# Use a different port
PORT=19000 ./scripts/run-integration-tests.sh

# Test against an already running server
./scripts/run-integration-tests.sh --url http://localhost:8080

# Or via env
SERVER_URL=http://127.0.0.1:9000 ./scripts/test-api.sh

# Run inside Docker (builds image first)
./scripts/run-integration-tests.sh --docker

# Leave the server running after tests (for manual inspection)
./scripts/run-integration-tests.sh --keep

# Verbose (shows bodies on failures)
VERBOSE=1 ./scripts/test-api.sh
```

## .xlsb test coverage

LibreOffice headless does not expose an export filter for `.xlsb` in most distros.

- `generate-samples.sh` will create a `testdata/README-xlsb.txt` with instructions.
- If you place any valid `.xlsb` at `testdata/sample.xlsb`, the full test suite will automatically include xlsb→xlsx conversions (multipart + JSON + smart endpoint).
- You can also do:
  ```bash
  SAMPLE_XLSB=/path/to/real.xlsb ./scripts/generate-samples.sh
  ```

Without a sample the xlsb-specific tests are skipped (the rest of the matrix still runs and covers the shared conversion logic).

## Makefile integration

After the scripts were added, the following targets were added:

```bash
make test-integration          # runs via local binary on a free-ish port
make test-integration-docker   # runs via docker
make generate-samples
```

See the root Makefile for details.

## Requirements (host or container)

- `curl`
- `python3` (used for JSON parsing when `jq` is absent)
- `soffice` (LibreOffice) only needed for `generate-samples.sh`
- `jq` is optional but nice to have (`apt install jq`)

The test scripts are intentionally self-contained and do not depend on `go test`.

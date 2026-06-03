#!/usr/bin/env bash
#
# generate-samples.sh
# Generates realistic test spreadsheet files (xlsx, ods) using LibreOffice.
# For .xlsb, since LibreOffice headless often lacks an export filter,
# the script will look for an existing testdata/sample.xlsb or warn the user.
#
# Usage:
#   ./scripts/generate-samples.sh
#   SAMPLE_XLSB=/path/to/real.xlsb ./scripts/generate-samples.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
TESTDATA_DIR="$ROOT_DIR/testdata"

mkdir -p "$TESTDATA_DIR"

CSV_FILE="$(mktemp --suffix=.csv)"
cat > "$CSV_FILE" <<'EOF'
Name,Value,Date,Notes
"Item Alpha",100,2024-01-15,"First row, basic"
"Item Beta",250,2024-02-20,"Second row with, comma inside"
"Item Gamma",175,2024-03-10,"Third row with ""double quotes"""
"Item Delta",300,2024-04-05,"Fourth row - special chars: & < >"
"Subtotal",825,2024-12-31,"Should survive roundtrip"
EOF

echo "==> Generating sample.xlsx from CSV..."
soffice --headless --nologo --nofirststartwizard \
  --convert-to xlsx --outdir "$TESTDATA_DIR" "$CSV_FILE" 2>&1 | tail -1

echo "==> Generating sample.ods from CSV..."
soffice --headless --nologo --nofirststartwizard \
  --convert-to ods --outdir "$TESTDATA_DIR" "$CSV_FILE" 2>&1 | tail -1

# Rename to canonical names (soffice keeps the csv basename)
mv -f "$TESTDATA_DIR/$(basename "$CSV_FILE" .csv).xlsx" "$TESTDATA_DIR/sample.xlsx" 2>/dev/null || true
mv -f "$TESTDATA_DIR/$(basename "$CSV_FILE" .csv).ods"   "$TESTDATA_DIR/sample.ods"   2>/dev/null || true

rm -f "$CSV_FILE"

echo "==> Verifying generated files..."
for f in "$TESTDATA_DIR/sample.xlsx" "$TESTDATA_DIR/sample.ods"; do
  if [[ -f "$f" ]]; then
    echo "    OK: $(ls -lh "$f" | awk '{print $5, $9}')"
  else
    echo "    FAIL: $f was not created" >&2
    exit 1
  fi
done

# .xlsb handling
XLSB_TARGET="$TESTDATA_DIR/sample.xlsb"
if [[ -n "${SAMPLE_XLSB:-}" && -f "$SAMPLE_XLSB" ]]; then
  cp -f "$SAMPLE_XLSB" "$XLSB_TARGET"
  echo "==> Copied custom .xlsb: $(ls -lh "$XLSB_TARGET" | awk '{print $5, $9}')"
elif [[ -f "$XLSB_TARGET" ]]; then
  echo "==> Using existing: $(ls -lh "$XLSB_TARGET" | awk '{print $5, $9}')"
else
  cat > "$TESTDATA_DIR/README-xlsb.txt" <<'EOM'
XLSB sample file not present.

LibreOffice (soffice --headless) does not provide an "export to xlsb" filter
in most installations, so we cannot auto-generate sample.xlsb.

To enable full xlsb->xlsx tests:

1. Provide any small valid .xlsb file you have, e.g.:
     cp /path/to/your/file.xlsb testdata/sample.xlsb

2. Or set the env var when (re)generating:
     SAMPLE_XLSB=/path/to/file.xlsb ./scripts/generate-samples.sh

Without sample.xlsb the integration test script will SKIP xlsb-specific tests
and still cover xlsx<->ods conversions (which is the majority of the logic).
EOM
  echo "==> NOTE: sample.xlsb not generated (see testdata/README-xlsb.txt)"
fi

echo
echo "Samples ready in testdata/:"
ls -lh "$TESTDATA_DIR"/sample.* 2>/dev/null || true

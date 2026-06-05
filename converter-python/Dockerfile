# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Runtime image
#
# Base:     debian:12-slim
# Python:   python3 + venv (Debian 12 enforces PEP 668; venv avoids the restriction)
# Convert:  LibreOffice Calc in headless mode
# Server:   uvicorn serving FastAPI (app/main.py)
# ---------------------------------------------------------------------------
FROM debian:12-slim

# ----------------------------------------------------------------------------
# OS packages:
#   - libreoffice-calc : the conversion engine (no writer/impress/java).
#   - fonts-dejavu-core: fonts commonly needed by spreadsheets.
#   - ca-certificates  : for HTTPS outbound connections.
#   - coreutils        : provides `timeout`, used to kill runaway soffice processes.
#   - python3-venv     : python3 + venv support (includes pip inside the venv).
# ----------------------------------------------------------------------------
RUN apt-get update \
 && apt-get install --no-install-recommends -y \
      libreoffice-calc \
      fonts-dejavu-core \
      ca-certificates \
      coreutils \
      python3-venv \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Create the venv as root in a system path; install deps before switching user.
RUN python3 -m venv /opt/venv
COPY requirements.txt /tmp/requirements.txt
RUN /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt

# Create the same non-root user as the original image (uid 10001).
RUN useradd --create-home --uid 10001 appuser

USER appuser
WORKDIR /app

# Copy startup script and application source.
COPY --chown=appuser:appuser entrypoint.sh .
COPY --chown=appuser:appuser app/ ./app/

RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

# All configuration via OFFICE_* environment variables (see entrypoint.sh).
ENTRYPOINT ["/app/entrypoint.sh"]

# syntax=docker/dockerfile:1

# ---------- Stage 1: Go binary build ----------
FROM golang:1.26-bookworm AS builder

WORKDIR /src
COPY go.mod go.sum* ./
RUN go mod download

COPY . .
# Static binary (no CGO) -> does not depend on libs from the build stage.
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/office-converter .

# ---------- Stage 2: slim runtime with LibreOffice ----------
FROM debian:12-slim AS runtime

# libreoffice-calc + minimal fonts; --no-install-recommends trims Writer/Impress/Java.
RUN apt-get update \
 && apt-get install --no-install-recommends -y \
      libreoffice-calc \
      fonts-dejavu-core \
      ca-certificates \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Non-root user (soffice runs fine without privileges).
RUN useradd --create-home --uid 10001 appuser
USER appuser
WORKDIR /home/appuser

COPY --from=builder /out/office-converter /usr/local/bin/office-converter

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/office-converter", "serve"]

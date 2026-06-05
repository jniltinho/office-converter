# syntax=docker/dockerfile:1

# Single-stage image using the requested base.
# Base:     debian:12-slim
# PHP:      FrankenPHP standalone binary (frankenphp-linux-x86_64-gnu v1.12.4)
#           which embeds PHP 8.5 and Caddy 2.11. The "gnu" build uses the
#           system glibc from the Debian base, so we don't need a static
#           binary nor a separate php apt package.
#           The binary is UPX-compressed at build time (~165 MB -> ~41 MB)
#           to reduce the final image size.
# Convert:  LibreOffice Calc in headless mode.
#
# Reference: https://frankenphp.dev/docs/

FROM debian:12-slim

# ----------------------------------------------------------------------------
# FrankenPHP release we pin against. The expected SHA256 of the linux-x86_64-gnu
# binary is verified at install time. Bump both together when upgrading.
# ----------------------------------------------------------------------------
ARG FRANKENPHP_VERSION=1.12.4
ARG FRANKENPHP_SHA256=334bb3d0570ec1f54ca6c9cd2e1452e4d316bb6fa26641d6c2dc5753719b0599
ARG FRANKENPHP_URL=https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64-gnu

# ----------------------------------------------------------------------------
# UPX (Ultimate Packer for eXecutables) is used **at build time only** to
# compress the FrankenPHP binary. We download a static UPX binary from its
# official GitHub release, run it, then discard it. This cuts ~120 MB from
# the binary that ends up in the image layers.
# Pin version + SHA256 of the tarball for reproducibility.
# ----------------------------------------------------------------------------
ARG UPX_VERSION=5.1.1
ARG UPX_SHA256=1ff660454227861e00772f743f66b900072116b9dc24f6ee28b97cce88a7828a
ARG UPX_URL=https://github.com/upx/upx/releases/download/v${UPX_VERSION}/upx-${UPX_VERSION}-amd64_linux.tar.xz

# ----------------------------------------------------------------------------
# OS packages:
#   - libreoffice-calc : the conversion engine (no writer/impress/java pulled
#                        because of --no-install-recommends + the calc meta).
#   - fonts-dejavu-core: fonts commonly needed by spreadsheets.
#   - ca-certificates  : for HTTPS during the FrankenPHP/UPX downloads.
#   - coreutils        : provides `timeout`, used by the conversion logic to kill
#                        runaway soffice processes.
#   - curl             : (build only) used to download FrankenPHP + UPX;
#                        purged from the final image after use.
#   - xz-utils         : (build only) needed to extract the UPX .tar.xz;
#                        purged from the final image after use.
#   - libcap2-bin      : provides `setcap`, so the binary can bind to low
#                        ports (e.g. 80/443) without running as root.
# ----------------------------------------------------------------------------
RUN apt-get update \
 && apt-get install --no-install-recommends -y \
      libreoffice-calc \
      fonts-dejavu-core \
      ca-certificates \
      coreutils \
      curl \
      xz-utils \
      libcap2-bin \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# ----------------------------------------------------------------------------
# Download, verify, UPX-compress and install the FrankenPHP binary.
# We download a static UPX, pack the binary in-place (reduces ~165 MB to ~41 MB),
# discard UPX, then remove curl + xz (build-only tools).
# We do not use the static (musl) build: the Debian base gives us a perfectly
# good glibc, the gnu build matches the glibc-based distro, and UPX works well.
#
# PHP dependencies are pre-installed using the official `composer` Docker image:
#   docker run --rm -i -v $PWD:/app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -d /app/api
# The resulting api/vendor/ (and composer.lock) is included via the COPY api/ below.
# No composer installation or `composer install` runs during the app image build.
# ----------------------------------------------------------------------------
RUN curl -fsSL -o /tmp/frankenphp "${FRANKENPHP_URL}" \
 && echo "${FRANKENPHP_SHA256}  /tmp/frankenphp" | sha256sum -c - \
 && curl -fsSL -o /tmp/upx.tar.xz "${UPX_URL}" \
 && echo "${UPX_SHA256}  /tmp/upx.tar.xz" | sha256sum -c - \
 && tar -xJf /tmp/upx.tar.xz -C /tmp \
 && install -m 0755 /tmp/upx-${UPX_VERSION}-amd64_linux/upx /usr/local/bin/upx \
 && install -m 0755 /tmp/frankenphp /usr/local/bin/frankenphp \
 && /usr/local/bin/upx --best --lzma /usr/local/bin/frankenphp \
 && rm -rf /tmp/frankenphp /tmp/upx.tar.xz /tmp/upx-${UPX_VERSION}-amd64_linux /usr/local/bin/upx \
 && apt-get purge -y --auto-remove curl xz-utils \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/* \
 && /usr/local/bin/frankenphp --version

# Allow the binary to bind to privileged ports (< 1024) without running as
# root. The capability is on the file, not on the user, so the non-root
# appuser below can still use it.
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp

# Create the same non-root user as the original image (uid 10001).
RUN useradd --create-home --uid 10001 appuser

USER appuser
WORKDIR /app

# Copy the startup wrapper and the entire Slim-based application tree
# (including pre-installed vendor/ from `composer` Docker image).
COPY --chown=appuser:appuser entrypoint.sh /app/
COPY --chown=appuser:appuser api/ /app/api/

# Make the entrypoint executable (in case the host checkout had it without +x).
RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

# Default command starts FrankenPHP in worker mode.
# All configuration is done via OFFICE_* environment variables (see entrypoint.sh).
ENTRYPOINT ["/app/entrypoint.sh"]

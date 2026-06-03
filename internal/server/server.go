package server

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"github.com/labstack/echo/v5"
	"github.com/labstack/echo/v5/middleware"
)

// Serve configures and starts the Echo HTTP(S) server, blocking until shutdown.
func Serve(cfg Config) error {
	address := fmt.Sprintf(":%d", cfg.Server.Port)
	if cfg.Server.Host != "" {
		address = fmt.Sprintf("%s:%d", cfg.Server.Host, cfg.Server.Port)
	}

	e := echo.New()
	e.Use(middleware.RequestLoggerWithConfig(middleware.RequestLoggerConfig{
		LogLatency:       true,
		LogRemoteIP:      true,
		LogHost:          true,
		LogMethod:        true,
		LogURI:           true,
		LogRequestID:     true,
		LogUserAgent:     true,
		LogStatus:        true,
		LogContentLength: true,
		LogResponseSize:  true,
		HandleError:      true,
		LogValuesFunc: func(c *echo.Context, v middleware.RequestLoggerValues) error {
			logger := c.Logger()
			if v.Error != nil && v.Status >= 500 {
				logger.LogAttrs(context.Background(), slog.LevelError, "REQUEST_ERROR",
					slog.String("method", v.Method),
					slog.String("uri", v.URI),
					slog.Int("status", v.Status),
					slog.Duration("latency", v.Latency),
					slog.String("host", v.Host),
					slog.String("bytes_in", v.ContentLength),
					slog.Int64("bytes_out", v.ResponseSize),
					slog.String("user_agent", v.UserAgent),
					slog.String("remote_ip", v.RemoteIP),
					slog.String("request_id", v.RequestID),
					slog.String("error", v.Error.Error()),
				)
				return nil
			}
			logger.LogAttrs(context.Background(), slog.LevelInfo, "REQUEST",
				slog.String("method", v.Method),
				slog.String("uri", v.URI),
				slog.Int("status", v.Status),
				slog.Duration("latency", v.Latency),
				slog.String("host", v.Host),
				slog.String("bytes_in", v.ContentLength),
				slog.Int64("bytes_out", v.ResponseSize),
				slog.String("user_agent", v.UserAgent),
				slog.String("remote_ip", v.RemoteIP),
				slog.String("request_id", v.RequestID),
			)
			return nil
		},
	}))
	e.Use(middleware.Recover())
	e.Use(middleware.BodyLimit(maxUploadBytes))

	e.HTTPErrorHandler = func(c *echo.Context, err error) {
		if er, _ := echo.UnwrapResponse(c.Response()); er != nil && er.Committed {
			return
		}

		code := http.StatusInternalServerError
		msg := "internal server error"

		if err != nil {
			if s := echo.StatusCode(err); s != 0 {
				code = s
			}
			var he *echo.HTTPError
			if errors.As(err, &he) {
				if he.Message != "" {
					msg = he.Message
				} else {
					msg = http.StatusText(code)
				}
			} else {
				msg = err.Error()
			}
		}

		reqPath := c.Request().URL.Path
		if strings.HasPrefix(reqPath, "/api/") || wantsJSONResponse(c) {
			_ = c.JSON(code, ErrorResponse{Error: msg})
			return
		}
		_ = c.String(code, msg)
	}

	registerRoutes(e, cfg)

	ctx, cancel := signalContext()
	defer cancel()

	sc := echo.StartConfig{
		Address:         address,
		GracefulTimeout: cfg.Server.GracefulTimeout.Duration,
	}

	if cfg.TLS.Enabled {
		if cfg.TLS.CertFile == "" || cfg.TLS.KeyFile == "" {
			return fmt.Errorf("TLS requires both tls.cert_file and tls.key_file")
		}
		slog.Info("starting server", "addr", address, "tls", true)
		if err := sc.StartTLS(ctx, e, cfg.TLS.CertFile, cfg.TLS.KeyFile); err != nil && !errors.Is(err, http.ErrServerClosed) {
			return fmt.Errorf("server error: %w", err)
		}
		return nil
	}

	slog.Info("starting server", "addr", address, "tls", false)
	if err := sc.Start(ctx, e); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return fmt.Errorf("server error: %w", err)
	}
	return nil
}

// signalContext returns a context that is cancelled when the process receives
// SIGINT or SIGTERM, enabling graceful shutdown.
func signalContext() (context.Context, context.CancelFunc) {
	return signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
}

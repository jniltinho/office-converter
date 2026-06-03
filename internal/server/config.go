// Package server implements the HTTP server, request handlers, and configuration
// for office-converter. The public surface is intentionally small:
// callers only need [Serve]; configuration is built by package cmd via Viper.
package server

import "time"

// Config holds all runtime configuration for the server.
// Values are populated by Viper in package cmd, which resolves them from
// (highest to lowest priority): CLI flag > environment variable > config file > default.
type Config struct {
	Server  ServerConfig  `mapstructure:"server"`
	TLS     TLSConfig     `mapstructure:"tls"`
	Swagger SwaggerConfig `mapstructure:"swagger"`
}

// ServerConfig controls the HTTP listener address and shutdown behaviour.
type ServerConfig struct {
	Host                   string        `mapstructure:"host"`
	Port                   int           `mapstructure:"port"`
	GracefulTimeout        time.Duration `mapstructure:"graceful_timeout"`
	ConversionTimeout      time.Duration `mapstructure:"conversion_timeout"`
	MaxUploadSize          int64         `mapstructure:"max_upload_size"`
	MaxConcurrentConversions int         `mapstructure:"max_concurrent_conversions"`
}

// TLSConfig controls HTTPS termination.
// Both CertFile and KeyFile must be set when Enabled is true.
type TLSConfig struct {
	Enabled  bool   `mapstructure:"enabled"`
	CertFile string `mapstructure:"cert_file"`
	KeyFile  string `mapstructure:"key_file"`
}

// SwaggerConfig controls the /docs and /api/v1/openapi.json endpoints.
// When Enabled is false both routes redirect to /.
type SwaggerConfig struct {
	Enabled bool `mapstructure:"enabled"`
}

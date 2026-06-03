package server

import (
	"fmt"
	"os"
	"time"

	"github.com/BurntSushi/toml"
)

// Config holds all runtime configuration for the server.
type Config struct {
	Server ServerConfig `toml:"server"`
	TLS    TLSConfig    `toml:"tls"`
}

// ServerConfig controls the HTTP listener.
type ServerConfig struct {
	Host            string   `toml:"host"`
	Port            int      `toml:"port"`
	GracefulTimeout Duration `toml:"graceful_timeout"`
}

// TLSConfig controls HTTPS. Both CertFile and KeyFile are required when Enabled is true.
type TLSConfig struct {
	Enabled  bool   `toml:"enabled"`
	CertFile string `toml:"cert_file"`
	KeyFile  string `toml:"key_file"`
}

// Duration wraps time.Duration to support TOML text values like "15s" or "1m30s".
type Duration struct{ time.Duration }

func (d *Duration) UnmarshalText(b []byte) error {
	var err error
	d.Duration, err = time.ParseDuration(string(b))
	return err
}

func (d Duration) MarshalText() ([]byte, error) {
	return []byte(d.Duration.String()), nil
}

func defaultConfig() Config {
	return Config{
		Server: ServerConfig{
			Host:            "",
			Port:            8080,
			GracefulTimeout: Duration{15 * time.Second},
		},
	}
}

// LoadConfig reads a TOML config file into a Config, starting from defaults.
// If path is empty, it tries to load "config.toml" from the working directory;
// if that file is absent, the defaults are returned unchanged.
// An explicitly provided path that does not exist is an error.
func LoadConfig(path string) (Config, error) {
	cfg := defaultConfig()

	autoDetect := path == ""
	if autoDetect {
		path = "config.toml"
	}

	if _, err := os.Stat(path); os.IsNotExist(err) {
		if autoDetect {
			return cfg, nil // no config.toml present — use defaults
		}
		return cfg, fmt.Errorf("config file not found: %s", path)
	}

	if _, err := toml.DecodeFile(path, &cfg); err != nil {
		return cfg, fmt.Errorf("loading config %s: %w", path, err)
	}
	return cfg, nil
}

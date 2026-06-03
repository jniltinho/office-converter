package cmd

import (
	"fmt"
	"os"
	"strconv"

	"office-converter/internal/server"

	"github.com/spf13/cobra"
)

var (
	host       string
	port       int
	tlsEnabled bool
	tlsCert    string
	tlsKey     string
)

var serveCmd = &cobra.Command{
	Use:          "serve",
	Short:        "Start the HTTP server",
	SilenceUsage: true,
	RunE: func(cmd *cobra.Command, _ []string) error {
		// Config path: --config > OFFICE_CONFIG env > auto-detect config.toml
		configPath := cfgFile
		if configPath == "" {
			configPath = os.Getenv("OFFICE_CONFIG")
		}

		cfg, err := server.LoadConfig(configPath)
		if err != nil {
			return err
		}

		// Env vars override config file (skipped when the corresponding CLI flag was set).
		if err := applyEnv(cmd, &cfg); err != nil {
			return err
		}

		// CLI flags have the highest priority.
		if cmd.Flags().Changed("host") {
			cfg.Server.Host = host
		}
		if cmd.Flags().Changed("port") {
			cfg.Server.Port = port
		}
		if cmd.Flags().Changed("tls") {
			cfg.TLS.Enabled = tlsEnabled
		}
		if cmd.Flags().Changed("tls-cert") {
			cfg.TLS.CertFile = tlsCert
		}
		if cmd.Flags().Changed("tls-key") {
			cfg.TLS.KeyFile = tlsKey
		}

		return server.Serve(cfg)
	},
}

// applyEnv reads OFFICE_* environment variables and merges them into cfg.
// A variable is only applied when the corresponding CLI flag was not explicitly set.
func applyEnv(cmd *cobra.Command, cfg *server.Config) error {
	if v := os.Getenv("OFFICE_HOST"); v != "" && !cmd.Flags().Changed("host") {
		cfg.Server.Host = v
	}
	if v := os.Getenv("OFFICE_PORT"); v != "" && !cmd.Flags().Changed("port") {
		n, err := strconv.Atoi(v)
		if err != nil {
			return fmt.Errorf("invalid OFFICE_PORT %q: %w", v, err)
		}
		cfg.Server.Port = n
	}
	if v := os.Getenv("OFFICE_TLS_ENABLED"); v != "" && !cmd.Flags().Changed("tls") {
		b, err := strconv.ParseBool(v)
		if err != nil {
			return fmt.Errorf("invalid OFFICE_TLS_ENABLED %q: %w", v, err)
		}
		cfg.TLS.Enabled = b
	}
	if v := os.Getenv("OFFICE_TLS_CERT"); v != "" && !cmd.Flags().Changed("tls-cert") {
		cfg.TLS.CertFile = v
	}
	if v := os.Getenv("OFFICE_TLS_KEY"); v != "" && !cmd.Flags().Changed("tls-key") {
		cfg.TLS.KeyFile = v
	}
	return nil
}

func init() {
	rootCmd.AddCommand(serveCmd)

	f := serveCmd.Flags()
	f.StringVar(&host, "host", "", "bind address (env: OFFICE_HOST)")
	f.IntVar(&port, "port", 8080, "HTTP port (env: OFFICE_PORT)")
	f.BoolVar(&tlsEnabled, "tls", false, "enable TLS/HTTPS (env: OFFICE_TLS_ENABLED)")
	f.StringVar(&tlsCert, "tls-cert", "", "TLS certificate file (env: OFFICE_TLS_CERT)")
	f.StringVar(&tlsKey, "tls-key", "", "TLS private key file (env: OFFICE_TLS_KEY)")
}

// Package cmd is declared in root.go; this file adds the "serve" sub-command.
package cmd

import (
	"fmt"
	"os"
	"time"

	"office-converter/internal/server"

	"github.com/spf13/cobra"
	"github.com/spf13/viper"
)

var serveCmd = &cobra.Command{
	Use:          "serve",
	Short:        "Start the HTTP server",
	SilenceUsage: true,
	RunE: func(cmd *cobra.Command, _ []string) error {
		v := viper.New()

		// Defaults — mirror what the config file would set when absent.
		v.SetDefault("server.host", "")
		v.SetDefault("server.port", 8080)
		v.SetDefault("server.graceful_timeout", "15s")
		v.SetDefault("server.conversion_timeout", "60s")
		v.SetDefault("server.max_upload_size", int64(100<<20))
		v.SetDefault("server.max_concurrent_conversions", 2)
		v.SetDefault("tls.enabled", false)
		v.SetDefault("tls.cert_file", "")
		v.SetDefault("tls.key_file", "")
		v.SetDefault("swagger.enabled", false)

		// Config file: --config flag > OFFICE_CONFIG env > auto-detect config.toml.
		configPath := cfgFile
		if configPath == "" {
			configPath = os.Getenv("OFFICE_CONFIG")
		}
		if configPath != "" {
			v.SetConfigFile(configPath)
			if err := v.ReadInConfig(); err != nil {
				return fmt.Errorf("loading config %s: %w", configPath, err)
			}
		} else {
			v.SetConfigName("config")
			v.SetConfigType("toml")
			v.AddConfigPath(".")
			if err := v.ReadInConfig(); err != nil {
				if _, ok := err.(viper.ConfigFileNotFoundError); !ok {
					return fmt.Errorf("loading config: %w", err)
				}
				// No config.toml found — proceed with defaults and env/flags.
			}
		}

		// Environment variable bindings (explicit to preserve existing names).
		v.BindEnv("server.host", "OFFICE_HOST")                                          //nolint:errcheck
		v.BindEnv("server.port", "OFFICE_PORT")                                          //nolint:errcheck
		v.BindEnv("server.conversion_timeout", "OFFICE_CONVERSION_TIMEOUT")              //nolint:errcheck
		v.BindEnv("server.max_upload_size", "OFFICE_MAX_UPLOAD_SIZE")                    //nolint:errcheck
		v.BindEnv("server.max_concurrent_conversions", "OFFICE_MAX_CONCURRENT_CONVERSIONS") //nolint:errcheck
		v.BindEnv("tls.enabled", "OFFICE_TLS_ENABLED")                                   //nolint:errcheck
		v.BindEnv("tls.cert_file", "OFFICE_TLS_CERT")                                    //nolint:errcheck
		v.BindEnv("tls.key_file", "OFFICE_TLS_KEY")                                      //nolint:errcheck
		v.BindEnv("swagger.enabled", "OFFICE_SWAGGER_ENABLED")                           //nolint:errcheck

		// CLI flag bindings — highest priority, applied only when the flag was set.
		v.BindPFlag("server.host", cmd.Flags().Lookup("host"))                                             //nolint:errcheck
		v.BindPFlag("server.port", cmd.Flags().Lookup("port"))                                             //nolint:errcheck
		v.BindPFlag("server.conversion_timeout", cmd.Flags().Lookup("conversion-timeout"))                 //nolint:errcheck
		v.BindPFlag("server.max_upload_size", cmd.Flags().Lookup("max-upload-size"))                       //nolint:errcheck
		v.BindPFlag("server.max_concurrent_conversions", cmd.Flags().Lookup("max-concurrent-conversions")) //nolint:errcheck
		v.BindPFlag("tls.enabled", cmd.Flags().Lookup("tls"))                                              //nolint:errcheck
		v.BindPFlag("tls.cert_file", cmd.Flags().Lookup("tls-cert"))                                       //nolint:errcheck
		v.BindPFlag("tls.key_file", cmd.Flags().Lookup("tls-key"))                                         //nolint:errcheck
		v.BindPFlag("swagger.enabled", cmd.Flags().Lookup("swagger"))                                      //nolint:errcheck

		var cfg server.Config
		if err := v.Unmarshal(&cfg); err != nil {
			return fmt.Errorf("invalid configuration: %w", err)
		}

		return server.Serve(cfg)
	},
}

func init() {
	rootCmd.AddCommand(serveCmd)

	f := serveCmd.Flags()
	f.String("host", "", "bind address (env: OFFICE_HOST)")
	f.Int("port", 8080, "HTTP port (env: OFFICE_PORT)")
	f.Duration("conversion-timeout", 60*time.Second, "per-conversion deadline after acquiring a slot (env: OFFICE_CONVERSION_TIMEOUT)")
	f.Int64("max-upload-size", 100<<20, "maximum upload size in bytes (env: OFFICE_MAX_UPLOAD_SIZE)")
	f.Int("max-concurrent-conversions", 2, "maximum parallel LibreOffice instances (env: OFFICE_MAX_CONCURRENT_CONVERSIONS)")
	f.Bool("tls", false, "enable TLS/HTTPS (env: OFFICE_TLS_ENABLED)")
	f.String("tls-cert", "", "TLS certificate file (env: OFFICE_TLS_CERT)")
	f.String("tls-key", "", "TLS private key file (env: OFFICE_TLS_KEY)")
	f.Bool("swagger", false, "enable /docs and /api/v1/openapi.json (env: OFFICE_SWAGGER_ENABLED)")
}

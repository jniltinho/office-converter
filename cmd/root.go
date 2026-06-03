// Package cmd implements the office-converter CLI using Cobra.
// It exposes a "serve" sub-command that starts the HTTP conversion server
// and a "version" sub-command that prints build metadata.
package cmd

import (
	"os"

	"github.com/spf13/cobra"
)

// cfgFile holds the path to the TOML configuration file supplied via --config.
var cfgFile string

var rootCmd = &cobra.Command{
	Use:   "office-converter",
	Short: "HTTP server for converting XLSB/XLSX/ODS spreadsheet files",
}

// Execute is the entry point called by main. It runs the root Cobra command
// and exits with code 1 on failure.
func Execute() {
	if err := rootCmd.Execute(); err != nil {
		os.Exit(1)
	}
}

func init() {
	rootCmd.PersistentFlags().StringVar(&cfgFile, "config", "", "path to config.toml (env: OFFICE_CONFIG, default: config.toml in cwd)")
}

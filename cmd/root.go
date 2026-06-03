package cmd

import (
	"os"

	"github.com/spf13/cobra"
)

var cfgFile string

var rootCmd = &cobra.Command{
	Use:   "office-converter",
	Short: "HTTP server for converting XLSB/XLSX/ODS spreadsheet files",
}

// Execute runs the root command.
func Execute() {
	if err := rootCmd.Execute(); err != nil {
		os.Exit(1)
	}
}

func init() {
	rootCmd.PersistentFlags().StringVar(&cfgFile, "config", "", "path to config.toml (env: OFFICE_CONFIG, default: config.toml in cwd)")
}

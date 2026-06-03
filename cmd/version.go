package cmd

import (
	"fmt"

	"github.com/spf13/cobra"
)

// Build metadata injected at link time via -ldflags (see Makefile).
// Defaults are used when the binary is built without the production flags.
var (
	Version   = "dev"
	BuildDate = "unknown"
	GitCommit = "unknown"
)

var versionCmd = &cobra.Command{
	Use:   "version",
	Short: "Print version information",
	Run: func(cmd *cobra.Command, args []string) {
		fmt.Printf("Office Converter\n")
		fmt.Printf("  Version:    %s\n", Version)
		fmt.Printf("  Build Time: %s\n", BuildDate)
		fmt.Printf("  Git Commit: %s\n", GitCommit)
	},
}

func init() {
	rootCmd.AddCommand(versionCmd)
}

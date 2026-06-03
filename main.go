// Command office-converter starts an HTTP server that converts spreadsheet
// files (XLSB, XLSX, ODS) using LibreOffice running in headless mode.
// Use the "serve" sub-command to start the server; run with --help for options.
package main

import "office-converter/cmd"

func main() {
	cmd.Execute()
}

package server

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

// maxConcurrentConversions limits how many LibreOffice instances may run
// simultaneously. Each instance is RAM/CPU heavy; 1 is the safest default
// for small machines. Increase according to available resources.
const maxConcurrentConversions = 2

// sofficeSemaphore serializes access to LibreOffice according to the limit above.
var sofficeSemaphore = make(chan struct{}, maxConcurrentConversions)

// ConvertTo converts a spreadsheet file (xlsb/xlsx/ods) to the target format
// ("xlsx" or "ods") using LibreOffice --headless.
// It returns the path of the generated file inside outDir.
//
// It is safe for concurrent use: a semaphore limits how many LibreOffice
// instances run at the same time, and each call uses its own user profile
// (-env:UserInstallation), avoiding the "instance already running" error.
func ConvertTo(ctx context.Context, src, outDir, toFormat string) (string, error) {
	if toFormat != "xlsx" && toFormat != "ods" {
		return "", fmt.Errorf("unsupported target format: %s (use xlsx or ods)", toFormat)
	}

	// Wait for a slot, respecting request cancellation.
	select {
	case sofficeSemaphore <- struct{}{}:
		defer func() { <-sofficeSemaphore }()
	case <-ctx.Done():
		return "", ctx.Err()
	}

	// The timeout applies only to the conversion itself, after acquiring a slot.
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()

	// Unique user profile per call avoids LibreOffice concurrent-instance errors.
	profile, err := os.MkdirTemp("", "lo-profile-")
	if err != nil {
		return "", fmt.Errorf("could not create temporary profile: %w", err)
	}
	defer os.RemoveAll(profile)

	cmd := exec.CommandContext(ctx,
		"soffice", "--headless",
		"--nologo",
		"--nofirststartwizard",
		"-env:UserInstallation=file://"+profile,
		"--convert-to", toFormat,
		"--outdir", outDir,
		src,
	)

	out, err := cmd.CombinedOutput()
	if ctx.Err() == context.DeadlineExceeded {
		return "", fmt.Errorf("conversion exceeded time limit")
	}
	if err != nil {
		return "", fmt.Errorf("conversion failed: %w (output: %s)", err, out)
	}

	base := filepath.Base(src)
	ext := filepath.Ext(base)
	dst := filepath.Join(outDir, base[:len(base)-len(ext)]+"."+toFormat)
	if _, err := os.Stat(dst); err != nil {
		return "", fmt.Errorf("expected %s was not generated: %s", toFormat, dst)
	}
	return dst, nil
}

// ConvertXLSB is a compatibility wrapper for .xlsb -> .xlsx conversion.
func ConvertXLSB(ctx context.Context, src, outDir string) (string, error) {
	return ConvertTo(ctx, src, outDir, "xlsx")
}

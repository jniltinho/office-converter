//go:build tools

// Package tools pins tool-only dependencies so they are tracked by go.mod
// without being included in the production binary.
// Run "go generate ./tools/..." or invoke the tools directly via "go run".
package tools

import (
	_ "github.com/oapi-codegen/oapi-codegen/v2/cmd/oapi-codegen"
)

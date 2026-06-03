// This file contains the Echo HTTP handlers and supporting helpers.
// Each handler follows the pattern: validate input → call [ConvertTo] → write response.
package server

import (
	"encoding/base64"
	"fmt"
	"io"
	"log/slog"
	"mime"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"office-converter/internal/api"

	"github.com/labstack/echo/v5"
)

// maxUploadBytes is the maximum allowed size for uploaded files. Initialized by Serve.
var maxUploadBytes int64 = 100 << 20 // 100 MiB default

type convertRequest struct {
	File     string `json:"file"`
	Filename string `json:"filename,omitempty"`
}

type convertResponse struct {
	Success     bool   `json:"success"`
	Filename    string `json:"filename"`
	ContentType string `json:"content_type"`
	Size        int64  `json:"size"`
	Data        string `json:"data"` // base64-encoded output file
}

// ErrorResponse is the JSON body returned for all API error responses.
type ErrorResponse struct {
	Error string `json:"error"`
}

// convertRequest is the JSON body accepted by the conversion endpoints.
// File must be a standard base64-encoded representation of the source file.

func handleConvert(c *echo.Context) error {
	if isJSONContentType(c) {
		return performJSONConversion(c, "", "", "convert-req-")
	}

	fileHeader, err := c.FormFile("file")
	if err != nil {
		return jsonError(c, http.StatusBadRequest, "missing 'file' form field")
	}

	origName := fileHeader.Filename
	origExt := filepath.Ext(origName)
	inputExt := strings.ToLower(origExt)

	var toFormat string
	switch inputExt {
	case ".xlsb", ".ods":
		toFormat = "xlsx"
	case ".xlsx":
		toFormat = "ods"
	default:
		return jsonError(c, http.StatusUnsupportedMediaType, "supported formats: .xlsb, .xlsx, .ods")
	}

	return performConversion(c, fileHeader, toFormat, "convert-req-")
}

func performConversion(c *echo.Context, fileHeader *multipart.FileHeader, toFormat, tempPrefix string) error {
	origName := fileHeader.Filename
	origExt := filepath.Ext(origName)
	inputExt := strings.ToLower(origExt)

	workDir, err := os.MkdirTemp("", tempPrefix)
	if err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to prepare workspace")
	}
	defer os.RemoveAll(workDir)

	srcPath := filepath.Join(workDir, "input"+inputExt)
	if err := saveUpload(fileHeader, srcPath); err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to save upload")
	}

	outDir := filepath.Join(workDir, "out")
	if err := os.MkdirAll(outDir, 0o755); err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to create output directory")
	}

	dstPath, err := ConvertTo(c.Request().Context(), srcPath, outDir, toFormat)
	if err != nil {
		slog.Error("conversion failed", "file", fileHeader.Filename, "error", err)
		return jsonError(c, http.StatusUnprocessableEntity, "could not convert the file")
	}

	dlName := strings.TrimSuffix(origName, origExt) + "." + toFormat

	if wantsJSONResponse(c) {
		data, err := os.ReadFile(dstPath)
		if err != nil {
			return jsonError(c, http.StatusInternalServerError, "failed to read conversion result")
		}
		return c.JSON(http.StatusOK, convertResponse{
			Success:     true,
			Filename:    dlName,
			ContentType: contentTypeFor(toFormat),
			Size:        int64(len(data)),
			Data:        base64.StdEncoding.EncodeToString(data),
		})
	}

	data, err := os.ReadFile(dstPath)
	if err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to read conversion result for download")
	}
	c.Response().Header().Set(echo.HeaderContentDisposition, "attachment; filename="+dlName)
	return c.Blob(http.StatusOK, contentTypeFor(toFormat), data)
}

func performJSONConversion(c *echo.Context, toFormat, expectedExt, tempPrefix string) error {
	var req convertRequest
	if err := c.Bind(&req); err != nil {
		return jsonError(c, http.StatusBadRequest, "invalid JSON body")
	}
	if req.File == "" {
		return jsonError(c, http.StatusBadRequest, "missing 'file' field in JSON body")
	}

	data, err := base64.StdEncoding.DecodeString(req.File)
	if err != nil {
		return jsonError(c, http.StatusBadRequest, "invalid base64 in 'file' field")
	}

	origName := req.Filename
	if origName == "" {
		if expectedExt != "" {
			origName = "input" + expectedExt
		} else {
			return jsonError(c, http.StatusBadRequest, "filename is required in JSON body for the smart /api/v1/convert endpoint")
		}
	}

	origExt := filepath.Ext(origName)
	inputExt := strings.ToLower(origExt)

	finalToFormat := toFormat
	if finalToFormat == "" {
		switch inputExt {
		case ".xlsb", ".ods":
			finalToFormat = "xlsx"
		case ".xlsx":
			finalToFormat = "ods"
		default:
			return jsonError(c, http.StatusUnsupportedMediaType, "supported formats: .xlsb, .xlsx, .ods")
		}
	}

	if expectedExt != "" && !strings.EqualFold(origExt, expectedExt) {
		return jsonError(c, http.StatusUnsupportedMediaType,
			fmt.Sprintf("this route accepts only %s files (use the appropriate /api/v1/convert/... endpoint)", expectedExt))
	}

	workDir, err := os.MkdirTemp("", tempPrefix)
	if err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to prepare workspace")
	}
	defer os.RemoveAll(workDir)

	srcPath := filepath.Join(workDir, "input"+inputExt)
	if err := os.WriteFile(srcPath, data, 0o600); err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to write uploaded file")
	}

	outDir := filepath.Join(workDir, "out")
	if err := os.MkdirAll(outDir, 0o755); err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to create output directory")
	}

	dstPath, err := ConvertTo(c.Request().Context(), srcPath, outDir, finalToFormat)
	if err != nil {
		slog.Error("conversion failed", "file", origName, "error", err)
		return jsonError(c, http.StatusUnprocessableEntity, "could not convert the file")
	}

	dlName := strings.TrimSuffix(filepath.Base(origName), origExt) + "." + finalToFormat

	outData, err := os.ReadFile(dstPath)
	if err != nil {
		return jsonError(c, http.StatusInternalServerError, "failed to read conversion result")
	}

	return c.JSON(http.StatusOK, convertResponse{
		Success:     true,
		Filename:    dlName,
		ContentType: contentTypeFor(finalToFormat),
		Size:        int64(len(outData)),
		Data:        base64.StdEncoding.EncodeToString(outData),
	})
}

func handleConvertXlsbToXlsx(c *echo.Context) error {
	if isJSONContentType(c) {
		return performJSONConversion(c, "xlsx", ".xlsb", "xlsb-to-xlsx-req-")
	}
	fileHeader, err := c.FormFile("file")
	if err != nil {
		return jsonError(c, http.StatusBadRequest, "missing 'file' form field")
	}
	if !strings.EqualFold(filepath.Ext(fileHeader.Filename), ".xlsb") {
		return jsonError(c, http.StatusUnsupportedMediaType, "this route accepts only .xlsb files (use /api/v1/convert/xlsb-to-xlsx)")
	}
	return performConversion(c, fileHeader, "xlsx", "xlsb-to-xlsx-req-")
}

func handleConvertXlsxToOds(c *echo.Context) error {
	if isJSONContentType(c) {
		return performJSONConversion(c, "ods", ".xlsx", "xlsx-to-ods-req-")
	}
	fileHeader, err := c.FormFile("file")
	if err != nil {
		return jsonError(c, http.StatusBadRequest, "missing 'file' form field")
	}
	if !strings.EqualFold(filepath.Ext(fileHeader.Filename), ".xlsx") {
		return jsonError(c, http.StatusUnsupportedMediaType, "this route accepts only .xlsx files (use /api/v1/convert/xlsx-to-ods)")
	}
	return performConversion(c, fileHeader, "ods", "xlsx-to-ods-req-")
}

func handleConvertOdsToXlsx(c *echo.Context) error {
	if isJSONContentType(c) {
		return performJSONConversion(c, "xlsx", ".ods", "ods-to-xlsx-req-")
	}
	fileHeader, err := c.FormFile("file")
	if err != nil {
		return jsonError(c, http.StatusBadRequest, "missing 'file' form field")
	}
	if !strings.EqualFold(filepath.Ext(fileHeader.Filename), ".ods") {
		return jsonError(c, http.StatusUnsupportedMediaType, "this route accepts only .ods files (use /api/v1/convert/ods-to-xlsx)")
	}
	return performConversion(c, fileHeader, "xlsx", "ods-to-xlsx-req-")
}

// saveUpload copies a multipart-uploaded file to dst on disk.
func saveUpload(fh *multipart.FileHeader, dst string) error {
	src, err := fh.Open()
	if err != nil {
		return err
	}
	defer src.Close()

	out, err := os.Create(dst)
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, src)
	return err
}

// jsonError writes a JSON [ErrorResponse] with the given HTTP status code.
func jsonError(c *echo.Context, status int, msg string) error {
	return c.JSON(status, ErrorResponse{Error: msg})
}

// contentTypeFor returns the MIME type for a given spreadsheet format extension
// ("xlsx" or "ods"). Falls back to [mime.TypeByExtension] for unknown formats.
func contentTypeFor(format string) string {
	switch strings.ToLower(format) {
	case "xlsx":
		return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
	case "ods":
		return "application/vnd.oasis.opendocument.spreadsheet"
	default:
		return mime.TypeByExtension("." + format)
	}
}

// wantsJSONResponse reports whether the client prefers a JSON response,
// either via the ?format=json query param or an Accept: application/json header.
func wantsJSONResponse(c *echo.Context) bool {
	if c.QueryParam("format") == "json" {
		return true
	}
	return strings.Contains(c.Request().Header.Get("Accept"), "application/json")
}

// isJSONContentType reports whether the request carries a JSON body.
func isJSONContentType(c *echo.Context) bool {
	return strings.HasPrefix(c.Request().Header.Get("Content-Type"), "application/json")
}

func healthz(c *echo.Context) error {
	if c.Request().Method == http.MethodHead {
		return c.NoContent(http.StatusOK)
	}
	return c.String(http.StatusOK, "ok")
}

func handleIndex(c *echo.Context) error {
	return c.HTMLBlob(http.StatusOK, []byte(indexHTML))
}

func handleOpenAPISpec(c *echo.Context) error {
	data, err := api.GetSpecJSON()
	if err != nil {
		return jsonError(c, http.StatusInternalServerError, "could not load OpenAPI spec")
	}
	return c.Blob(http.StatusOK, "application/json", data)
}

func handleSwaggerUI(c *echo.Context) error {
	return c.HTMLBlob(http.StatusOK, []byte(swaggerUIHTML))
}

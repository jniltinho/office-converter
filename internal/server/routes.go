package server

import "github.com/labstack/echo/v5"

func registerRoutes(e *echo.Echo) {
	// Web UI
	e.GET("/", handleIndex)

	// Conversion API
	e.POST("/api/convert", handleConvert)
	e.POST("/api/convert/xlsb-to-xlsx", handleConvertXlsbToXlsx)
	e.POST("/api/convert/xlsx-to-ods", handleConvertXlsxToOds)
	e.POST("/api/convert/ods-to-xlsx", handleConvertOdsToXlsx)

	// API documentation
	e.GET("/api/openapi.json", handleOpenAPISpec)
	e.GET("/docs", handleSwaggerUI)

	// Health check
	e.GET("/healthz", healthz)
	e.HEAD("/healthz", healthz)
}

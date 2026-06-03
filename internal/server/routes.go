// This file wires URL paths to their handler functions.
package server

import (
	"net/http"

	"github.com/labstack/echo/v5"
)

// registerRoutes attaches all application routes to e.
// Swagger routes are only registered when cfg.Swagger.Enabled is true;
// otherwise they redirect to the home page.
func registerRoutes(e *echo.Echo, cfg Config) {
	// Web UI
	e.GET("/", handleIndex)

	// Conversion API
	e.POST("/api/v1/convert", handleConvert)
	e.POST("/api/v1/convert/xlsb-to-xlsx", handleConvertXlsbToXlsx)
	e.POST("/api/v1/convert/xlsx-to-ods", handleConvertXlsxToOds)
	e.POST("/api/v1/convert/ods-to-xlsx", handleConvertOdsToXlsx)

	// API documentation
	if cfg.Swagger.Enabled {
		e.GET("/api/v1/openapi.json", handleOpenAPISpec)
		e.GET("/docs", handleSwaggerUI)
	} else {
		redirectHome := func(c *echo.Context) error {
			return c.Redirect(http.StatusFound, "/")
		}
		e.GET("/api/v1/openapi.json", redirectHome)
		e.GET("/docs", redirectHome)
	}

	// Health check
	e.GET("/healthz", healthz)
	e.HEAD("/healthz", healthz)
}

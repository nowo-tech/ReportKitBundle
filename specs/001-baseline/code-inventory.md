# Code inventory — 100% traceability

**Specs**: [`spec.md`](spec.md) (S0) and [`../002-s1-model-renderers/spec.md`](../002-s1-model-renderers/spec.md) (S1)
**Package**: `nowo-tech/report-kit-bundle`
**Last audited**: 2026-11-22 (S1)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **67**.

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Brand/DefaultReportBrandResolver.php` | FR-BRAND-001 |
| `src/Application/Chart/ReportChartSvg.php` | FR-CHART-001 |
| `src/Application/ConfigReportSettingsProvider.php` | FR-EXPORT-001, FR-CONFIG-003 |
| `src/Application/Export/RenderBudget.php` | FR-EXPORT-001 |
| `src/Application/Export/RenderLimits.php` | FR-EXPORT-001, FR-RENDER-PDF |
| `src/Application/Export/ReportExporter.php` | FR-EXPORT-001, FR-WORKER-002 |
| `src/Application/Export/ReportFileName.php` | FR-EXPORT-001, FR-PARAM-002 |
| `src/Application/Export/ReportRendererInterface.php` | FR-EXPORT-001 |
| `src/Application/ReportParametersResolver.php` | FR-PARAM-002 |
| `src/Application/ReportTypeRegistry.php` | FR-TYPES-001 |
| `src/Contract/AbstractReportType.php` | FR-TYPES-002, FR-PARAM-002 |
| `src/Contract/ContextRef.php` | FR-MODEL-002 |
| `src/Contract/Exception/FormatNotEnabledException.php` | FR-EXPORT-001 |
| `src/Contract/Exception/InvalidReportArgumentException.php` | FR-MODEL-001, FR-PARAM-001 |
| `src/Contract/Exception/ReportKitExceptionInterface.php` | FR-MODEL-001 |
| `src/Contract/Exception/ReportLimitExceededException.php` | FR-EXPORT-001, FR-PDF-001 |
| `src/Contract/Guard.php` | FR-MODEL-002, FR-TYPES-001 |
| `src/Contract/OwnerRef.php` | FR-MODEL-002 |
| `src/Contract/ParameterDefinition.php` | FR-PARAM-001 |
| `src/Contract/ParameterSchema.php` | FR-PARAM-001 |
| `src/Contract/ParameterType.php` | FR-PARAM-001 |
| `src/Contract/PdfDocumentRendererInterface.php` | FR-PDF-001 |
| `src/Contract/PdfOptions.php` | FR-PDF-001 |
| `src/Contract/RenderedFile.php` | FR-EXPORT-001, FR-HTTP-001 |
| `src/Contract/Report.php` | FR-MODEL-001 |
| `src/Contract/ReportBrandResolverInterface.php` | FR-BRAND-001 |
| `src/Contract/ReportExporterInterface.php` | FR-EXPORT-001 |
| `src/Contract/ReportFormat.php` | FR-EXPORT-001 |
| `src/Contract/ReportParameters.php` | FR-PARAM-002 |
| `src/Contract/ReportParametersResolverInterface.php` | FR-PARAM-002 |
| `src/Contract/ReportRequest.php` | FR-PARAM-003 |
| `src/Contract/ReportSection.php` | FR-MODEL-001 |
| `src/Contract/ReportSettingsProviderInterface.php` | FR-EXPORT-001, FR-CONFIG-003 |
| `src/Contract/ReportTypeInterface.php` | FR-TYPES-001 |
| `src/Contract/ReportTypeRegistryInterface.php` | FR-TYPES-001 |
| `src/Contract/ReportWindow.php` | FR-PARAM-003 |
| `src/DependencyInjection/Compiler/RenderingRequirementsPass.php` | FR-CONFIG-003 |
| `src/DependencyInjection/ConfigNodes.php` | FR-CONFIG-001, FR-CONFIG-003 |
| `src/DependencyInjection/Configuration.php` | FR-CONFIG-001, FR-CONFIG-003 |
| `src/DependencyInjection/NowoReportKitExtension.php` | FR-CONFIG-001, FR-CONFIG-002, FR-CONFIG-003 |
| `src/Infrastructure/Csv/BuiltInCsvCellGuard.php` | FR-RENDER-CSV |
| `src/Infrastructure/Csv/ControllerKitCsvCellGuard.php` | FR-RENDER-CSV |
| `src/Infrastructure/Csv/CsvCellGuardInterface.php` | FR-RENDER-CSV |
| `src/Infrastructure/Filesystem/PrivateTempDirectory.php` | FR-PDF-001, FR-RENDER-XLSX, FR-WORKER-002 |
| `src/Infrastructure/Pdf/ClosedDompdfOptions.php` | FR-PDF-001 |
| `src/Infrastructure/Pdf/DompdfDeadline.php` | FR-PDF-001, FR-EXPORT-001 |
| `src/Infrastructure/Pdf/DompdfDocumentRenderer.php` | FR-PDF-001, FR-WORKER-002 |
| `src/Infrastructure/Pdf/DompdfFontCacheWarmer.php` | FR-PDF-001 |
| `src/Infrastructure/Pdf/DompdfPhpState.php` | FR-PDF-001, FR-WORKER-002 |
| `src/Infrastructure/Pdf/DompdfWarnings.php` | FR-PDF-001, FR-WORKER-002 |
| `src/Infrastructure/Rendering/CsvReportRenderer.php` | FR-RENDER-CSV |
| `src/Infrastructure/Rendering/JsonReportRenderer.php` | FR-RENDER-JSON |
| `src/Infrastructure/Rendering/PdfReportRenderer.php` | FR-RENDER-PDF, FR-BRAND-001 |
| `src/Infrastructure/Rendering/PdfSectionView.php` | FR-RENDER-PDF, FR-CHART-001 |
| `src/Infrastructure/Rendering/XlsxReportRenderer.php` | FR-RENDER-XLSX |
| `src/Infrastructure/Twig/TemplateCheckWarmer.php` | FR-CONFIG-003 |
| `src/NowoReportKitBundle.php` | FR-BUNDLE-001, FR-CONFIG-003 |
| `src/Presentation/Http/RenderedFileResponder.php` | FR-HTTP-001 |
| `src/Resources/config/doctrine/README.md` | FR-DB-001 |
| `src/Resources/translations/NowoReportKitBundle.de.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.en.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.es.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.fr.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.it.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.nl.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/translations/NowoReportKitBundle.pt.yaml` | FR-I18N-001, FR-I18N-002 |
| `src/Resources/views/pdf/report.html.twig` | FR-RENDER-PDF |

| Total production sources | 67 |
| --- | --- |

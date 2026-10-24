<?php
declare(strict_types=1);

/** Apply the organisation logo and a subtle logo watermark to a rendered Dompdf document. */
function ims_brand_pdf(object $pdf): void
{
    if (!method_exists($pdf, 'getCanvas')) {
        return;
    }

    // Prefer the uploaded site logo. JPEGs work even on servers without PHP GD.
    $publicRoot = dirname(__DIR__, 2) . '/public';
    $branding = $GLOBALS['IMS_SITE_BRANDING'] ?? [];
    $logo = $publicRoot . '/images/logo-pdf.jpg';
    $siteLogo = (string)($branding['logo'] ?? '');
    if (preg_match('#^/uploads/site-branding/[a-f0-9]{32}\.(png|jpe?g|webp)$#i', $siteLogo, $matches)) {
        $customLogo = $publicRoot . substr($siteLogo, 1);
        $extension = strtolower($matches[1]);
        if (is_file($customLogo) && (in_array($extension, ['jpg','jpeg'], true) || extension_loaded('gd'))) {
            $logo = $customLogo;
        }
    }
    if (!is_file($logo)) {
        error_log('PDF branding skipped: public/images/logo-pdf.jpg was not found.');
        return;
    }
    $dimensions = @getimagesize($logo);
    $aspectRatio = is_array($dimensions) && !empty($dimensions[0]) && !empty($dimensions[1])
        ? (float)$dimensions[0] / (float)$dimensions[1]
        : 3.0;

    $canvas = $pdf->getCanvas();
    $canvas->page_script(static function ($pageNumber, $pageCount, $canvas) use ($logo, $aspectRatio): void {
        $pageWidth = $canvas->get_width();
        $pageHeight = $canvas->get_height();
        $watermarkWidth = min(320.0, 170.0 * $aspectRatio);
        $watermarkHeight = $watermarkWidth / $aspectRatio;
        $headerWidth = min(120.0, 34.0 * $aspectRatio);
        $headerHeight = $headerWidth / $aspectRatio;

        try {
            // Keep the watermark light enough that report content remains readable.
            $canvas->set_opacity(0.075);
            $canvas->image($logo, ($pageWidth - $watermarkWidth) / 2, ($pageHeight - $watermarkHeight) / 2, $watermarkWidth, $watermarkHeight, 'normal');
            $canvas->set_opacity(1.0);

            // A compact header mark is repeated on every page.
            $canvas->image($logo, 28, 16, $headerWidth, $headerHeight, 'normal');
        } catch (\Throwable $error) {
            // An unavailable image extension must not make an otherwise valid report fail.
            error_log('PDF logo could not be embedded: ' . $error->getMessage());
        }
    });
}

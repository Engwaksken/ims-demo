<?php
declare(strict_types=1);

/** Apply the organisation logo and a subtle logo watermark to a rendered Dompdf document. */
function ims_brand_pdf(object $pdf): void
{
    if (!method_exists($pdf, 'getCanvas')) {
        return;
    }

    // JPEG keeps Dompdf's CPDF renderer compatible even where PHP GD is not enabled.
    $logo = dirname(__DIR__, 2) . '/public/images/logo-pdf.jpg';
    if (!is_file($logo)) {
        error_log('PDF branding skipped: public/images/logo-pdf.jpg was not found.');
        return;
    }

    $canvas = $pdf->getCanvas();
    $canvas->page_script(static function ($pageNumber, $pageCount, $canvas) use ($logo): void {
        $pageWidth = $canvas->get_width();
        $pageHeight = $canvas->get_height();

        try {
            // Keep the watermark light enough that report content remains readable.
            $canvas->set_opacity(0.075);
            $canvas->image($logo, ($pageWidth - 300) / 2, ($pageHeight - 94) / 2, 300, 94, 'normal');
            $canvas->set_opacity(1.0);

            // A compact header mark is repeated on every page.
            $canvas->image($logo, 28, 16, 92, 29, 'normal');
        } catch (\Throwable $error) {
            // An unavailable image extension must not make an otherwise valid report fail.
            error_log('PDF logo could not be embedded: ' . $error->getMessage());
        }
    });
}

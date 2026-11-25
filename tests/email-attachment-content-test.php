<?php
require dirname(__DIR__) . '/includes/email-ingest-functions.php';
require dirname(__DIR__) . '/includes/upload-functions.php';
if (!function_exists('email_ingest_attachment_metadata')) throw new RuntimeException('Content-based mail attachment validation missing.');
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$pdf = "%PDF-1.4\nTEST-document\n%%EOF\n";
$bom_pdf = "\xEF\xBB\xBF" . $pdf;
$assert(email_ingest_attachment_metadata(['filename' => 'TEST.pdf', 'data' => $bom_pdf], 1024) === null, 'PDF preambles remain outside the strict accepted signature policy.');
$meta = email_ingest_attachment_metadata(['filename' => 'TEST.pdf', 'mime' => 'text/html', 'size' => 1, 'data' => $pdf], 1024);
$assert($meta !== null && $meta['size'] === strlen($pdf) && $meta['mime'] === 'application/pdf', 'PDF signature and measured size must override declared metadata.');
$assert(email_ingest_attachment_metadata(['filename' => 'TEST.pdf', 'mime' => 'application/pdf', 'data' => '<html>TEST</html>'], 1024) === null, 'HTML disguised as PDF must be rejected.');
$assert(email_ingest_attachment_metadata(['filename' => 'TEST.png', 'mime' => 'image/png', 'data' => '<svg onload="alert(1)"></svg>'], 1024) === null, 'Fake raster image must be rejected.');
$assert(email_ingest_attachment_metadata(['filename' => 'TEST.txt', 'size' => 1, 'data' => str_repeat('T', 1025)], 1024) === null, 'Actual bytes must enforce size limit.');
$assert(attachment_mime_can_render_inline('image/png'), 'Raster image must remain inline.');
$assert(!attachment_mime_can_render_inline('image/svg+xml') && !attachment_mime_can_render_inline('text/html'), 'Active formats must download.');
$source = file_get_contents(dirname(__DIR__) . '/attachment.php');
$assert(str_contains($source, 'attachment_mime_can_render_inline($mime)'), 'Attachment endpoint must use safe inline policy.');
echo "Mail attachment content and safe inline disposition regression OK\n";

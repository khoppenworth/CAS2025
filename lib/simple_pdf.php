<?php

declare(strict_types=1);

class SimplePdfDocument
{
    private const PAGE_TOKEN = '__PDF_PAGE__';
    private const PAGE_TOTAL_TOKEN = '__PDF_PAGE_TOTAL__';

    private float $width = 595.28; // A4 width in points
    private float $height = 841.89; // A4 height in points
    private float $marginLeft = 50.0;
    private float $marginRight = 50.0;
    private float $marginTop = 50.0;
    private float $marginBottom = 60.0;
    private float $cursorY;
    private array $pages = [];
    private array $currentOps = [];
    private bool $pageOpen = false;
    private array $imageResources = [];
    private int $imageCounter = 0;
    private ?array $headerConfig = null;
    private float $headerSpacing = 0.0;
    private int $pageNumber = 0;

    public function __construct()
    {
        $this->cursorY = 0.0;
    }

    public function registerImageResource(
        string $data,
        int $pixelWidth,
        int $pixelHeight,
        string $filter,
        string $colorSpace,
        int $bitsPerComponent
    ): string {
        $name = 'Im' . (++$this->imageCounter);
        $this->imageResources[$name] = [
            'data' => $data,
            'width' => max(1, $pixelWidth),
            'height' => max(1, $pixelHeight),
            'filter' => $filter,
            'colorSpace' => $colorSpace,
            'bits' => max(1, $bitsPerComponent),
        ];

        return $name;
    }

    public function registerJpegImage(string $data, int $pixelWidth, int $pixelHeight): string
    {
        return $this->registerImageResource($data, $pixelWidth, $pixelHeight, 'DCTDecode', '/DeviceRGB', 8);
    }

    public function setHeader(?string $title, ?string $subtitle = null, ?array $imageSpec = null, ?array $style = null): void
    {
        $normalizedTitle = $this->normalizeHeaderText($title);
        $normalizedSubtitle = $this->normalizeHeaderText($subtitle);
        $image = null;

        if (is_array($imageSpec) && isset($imageSpec['name'], $imageSpec['width'], $imageSpec['height'])) {
            $image = [
                'name' => (string)$imageSpec['name'],
                'width' => max(0.0, (float)$imageSpec['width']),
                'height' => max(0.0, (float)$imageSpec['height']),
            ];
        }

        $barColor = [32, 115, 191];
        $textColor = [255, 255, 255];
        if (is_array($style)) {
            if (isset($style['bar_color']) && is_array($style['bar_color']) && count($style['bar_color']) === 3) {
                $barColor = [
                    max(0, min(255, (int)$style['bar_color'][0])),
                    max(0, min(255, (int)$style['bar_color'][1])),
                    max(0, min(255, (int)$style['bar_color'][2])),
                ];
            }
            if (isset($style['text_color']) && is_array($style['text_color']) && count($style['text_color']) === 3) {
                $textColor = [
                    max(0, min(255, (int)$style['text_color'][0])),
                    max(0, min(255, (int)$style['text_color'][1])),
                    max(0, min(255, (int)$style['text_color'][2])),
                ];
            }
        }

        if ($normalizedTitle === null && $normalizedSubtitle === null && $image === null) {
            $this->headerConfig = null;
            $this->headerSpacing = 0.0;
            return;
        }

        $titleFontSize = 16.0;
        $subtitleFontSize = 11.0;
        $lineGap = 4.0;
        $topBarHeight = 8.0;
        $topPadding = 8.0;
        $bottomPadding = 14.0;
        $ruleGap = 6.0;
        $contentGap = 14.0;
        $textGap = $image !== null ? 12.0 : 0.0;
        $topBarGap = 8.0;

        $textHeight = 0.0;
        if ($normalizedTitle !== null) {
            $textHeight += $titleFontSize;
        }
        if ($normalizedSubtitle !== null) {
            if ($textHeight > 0.0) {
                $textHeight += $lineGap;
            }
            $textHeight += $subtitleFontSize;
        }

        $imageHeight = $image['height'] ?? 0.0;
        $contentHeight = max($textHeight, $imageHeight);
        $this->headerSpacing = $topBarHeight + $topBarGap + $topPadding + $contentHeight + $bottomPadding + $ruleGap + $contentGap;

        $this->headerConfig = [
            'title' => $normalizedTitle,
            'subtitle' => $normalizedSubtitle,
            'title_font_size' => $titleFontSize,
            'subtitle_font_size' => $subtitleFontSize,
            'line_gap' => $lineGap,
            'top_padding' => $topPadding,
            'bottom_padding' => $bottomPadding,
            'rule_gap' => $ruleGap,
            'content_gap' => $contentGap,
            'text_gap' => $textGap,
            'image' => $image,
            'bar_color' => $barColor,
            'text_color' => $textColor,
            'top_bar_height' => $topBarHeight,
            'top_bar_gap' => $topBarGap,
        ];
    }

    public function addHeading(string $text): void
    {
        $this->addTextBlock($text, 18.0, 'F2', 1.5);
        $this->addSpacer(6.0);
    }

    public function addSubheading(string $text): void
    {
        $this->addTextBlock($text, 14.0, 'F2', 1.4);
        $this->addSpacer(4.0);
    }

    public function addParagraph(string $text, float $fontSize = 11.0): void
    {
        $this->addTextBlock($text, $fontSize, 'F1', 1.35);
        $this->addSpacer(4.0);
    }

    public function addBulletList(array $lines, float $fontSize = 11.0): void
    {
        foreach ($lines as $line) {
            $this->addTextBlock('• ' . $line, $fontSize, 'F1', 1.35, false);
        }
        $this->addSpacer(4.0);
    }

    public function addSignatureFields(array $rows): void
    {
        $labelFontSize = 10.0;
        $rowHeight = $labelFontSize + 28.0;
        $columnSpacing = 28.0;
        $minimumLineLength = 90.0;

        $rendered = false;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $labels = [];
            foreach ($row as $label) {
                $text = trim((string)$label);
                if ($text !== '') {
                    $labels[] = $text;
                }
            }

            if ($labels === []) {
                continue;
            }

            $this->ensureSpace($rowHeight);
            $baselineY = $this->cursorY;
            $availableWidth = $this->width - $this->marginLeft - $this->marginRight;
            $columns = count($labels);
            $lineWidth = $columns > 0
                ? ($availableWidth - $columnSpacing * max(0, $columns - 1)) / $columns
                : $availableWidth;
            $lineWidth = max($minimumLineLength, min($lineWidth, 230.0));

            $x = $this->marginLeft;
            foreach ($labels as $label) {
                $this->drawText($label, 'F1', $labelFontSize, $x, $baselineY);

                $lineY = $baselineY - ($labelFontSize + 6.0);
                $lineStartX = $x;
                $lineEndX = min($lineStartX + $lineWidth, $this->width - $this->marginRight);
                $this->drawLine($lineStartX, $lineY, $lineEndX, $lineY, 0.6);

                $x = $lineEndX + $columnSpacing;
            }

            $this->cursorY = $baselineY - $rowHeight;
            $rendered = true;
        }

        if ($rendered) {
            $this->cursorY -= 4.0;
        }
    }

    public function addSpacer(float $points): void
    {
        $this->cursorY -= $points;
        if ($this->cursorY <= $this->marginBottom) {
            $this->startNewPage();
        }
    }

    public function addTable(array $headers, array $rows, array $columnWidths, float $fontSize = 10.0): void
    {
        $this->ensurePage();
        $resolvedWidths = $this->resolveTableColumnWidthsInPoints($columnWidths);
        $lineHeight = $this->lineHeight($fontSize, 1.3);
        $cellPaddingX = 6.0;
        $cellPaddingY = 4.0;
        $headerFill = [237, 242, 247];
        $rowFill = [250, 252, 255];
        $borderColor = [203, 213, 225];

        $renderRow = function (array $row, bool $isHeader, bool $alternate = false) use (
            $resolvedWidths,
            $fontSize,
            $lineHeight,
            $cellPaddingX,
            $cellPaddingY,
            $headerFill,
            $rowFill,
            $borderColor
        ): void {
            $cellLines = [];
            $maxLines = 1;
            foreach ($resolvedWidths as $index => $width) {
                $value = trim((string)($row[$index] ?? ''));
                $availableTextWidth = max(20.0, $width - ($cellPaddingX * 2));
                $wrapped = $this->wrapTextToWidth($value, $fontSize, $availableTextWidth);
                if ($wrapped === []) {
                    $wrapped = [''];
                }
                $cellLines[$index] = $wrapped;
                $maxLines = max($maxLines, count($wrapped));
            }

            $rowHeight = ($maxLines * $lineHeight) + ($cellPaddingY * 2);
            $this->ensureSpace($rowHeight + 2.0);

            $x = $this->marginLeft;
            $rowTopY = $this->cursorY;
            $rowBottomY = $rowTopY - $rowHeight;
            foreach ($resolvedWidths as $index => $width) {
                if ($isHeader) {
                    $this->drawFilledRect($x, $rowBottomY, $width, $rowHeight, $headerFill);
                } elseif ($alternate) {
                    $this->drawFilledRect($x, $rowBottomY, $width, $rowHeight, $rowFill);
                }
                $this->drawRectOutline($x, $rowBottomY, $width, $rowHeight, $borderColor, 0.5);

                $textY = $rowTopY - $cellPaddingY - $fontSize;
                foreach ($cellLines[$index] as $line) {
                    $font = $isHeader ? 'F2' : 'F1';
                    $this->drawText($line, $font, $fontSize, $x + $cellPaddingX, $textY);
                    $textY -= $lineHeight;
                }

                $x += $width;
            }

            $this->cursorY = $rowBottomY;
        };

        $renderRow($headers, true);
        foreach (array_values($rows) as $rowIndex => $row) {
            $renderRow((array)$row, false, ($rowIndex % 2) === 1);
        }
        $this->addSpacer(6.0);
    }

    public function addImageBlock(string $binary, int $pixelWidth, int $pixelHeight, ?float $maxDisplayWidth = null): void
    {
        if ($binary === '' || $pixelWidth <= 0 || $pixelHeight <= 0) {
            return;
        }

        $this->ensurePage();

        $availableWidth = $this->width - $this->marginLeft - $this->marginRight;
        $targetWidth = $maxDisplayWidth !== null ? min($maxDisplayWidth, $availableWidth) : $availableWidth;
        $targetWidth = max(120.0, $targetWidth);
        $scale = $targetWidth / $pixelWidth;
        $renderWidth = $pixelWidth * $scale;
        $renderHeight = $pixelHeight * $scale;

        $this->ensureSpace($renderHeight + 16.0);

        $x = max($this->marginLeft, ($this->width - $renderWidth) / 2);
        $y = $this->cursorY - $renderHeight;
        $resource = $this->registerJpegImage($binary, $pixelWidth, $pixelHeight);
        $this->drawImage($resource, $x, $y, $renderWidth, $renderHeight);
        $this->cursorY = $y - 12.0;
    }

    public function addRightAlignedText(array $lines, float $fontSize = 10.0, float $lineSpacing = 1.3): void
    {
        $content = [];
        $maxBlockWidth = ($this->width - $this->marginLeft - $this->marginRight) * 0.46;
        if ($maxBlockWidth <= 0.0) {
            $maxBlockWidth = $this->width - $this->marginLeft - $this->marginRight;
        }

        foreach ($lines as $line) {
            $text = trim((string)$line);
            if ($text !== '') {
                foreach ($this->wrapTextToWidth($text, $fontSize, $maxBlockWidth) as $wrappedLine) {
                    $content[] = $wrappedLine;
                }
            }
        }

        if ($content === []) {
            return;
        }

        $this->ensurePage();
        $lineHeight = $this->lineHeight($fontSize, $lineSpacing);
        $totalHeight = $lineHeight * count($content);
        $this->ensureSpace($totalHeight);

        $currentY = $this->cursorY;
        foreach ($content as $text) {
            $textWidth = $this->estimateTextWidth($text, $fontSize);
            $x = max($this->marginLeft, $this->width - $this->marginRight - $textWidth);
            $this->drawText($text, 'F1', $fontSize, $x, $currentY);
            $currentY -= $lineHeight;
        }

        $this->cursorY = $currentY - 2.0;
    }

    private function wrapTextToWidth(string $text, float $fontSize, float $availableWidth): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));
        if ($normalized === '') {
            return [''];
        }

        if ($availableWidth <= 0.0) {
            return [$normalized];
        }

        $words = preg_split('/\s+/u', $normalized);
        if (!is_array($words)) {
            $words = [$normalized];
        }

        $spaceWidth = $this->estimateTextWidth(' ', $fontSize);
        $result = [];
        $current = '';
        $currentWidth = 0.0;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $segments = $this->segmentWordToFit($word, $availableWidth, $fontSize);
            foreach ($segments as $index => $segment) {
                $segmentWidth = $this->estimateTextWidth($segment, $fontSize);

                if ($current === '') {
                    $current = $segment;
                    $currentWidth = $segmentWidth;
                    continue;
                }

                $needsSpace = ($index === 0);
                $candidateWidth = $currentWidth + ($needsSpace ? $spaceWidth : 0.0) + $segmentWidth;

                if ($needsSpace && $candidateWidth <= $availableWidth) {
                    $current .= ' ' . $segment;
                    $currentWidth = $candidateWidth;
                } else {
                    $result[] = $current;
                    $current = $segment;
                    $currentWidth = $segmentWidth;
                }
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result ?: [''];
    }

    public function output(): string
    {
        if ($this->pageOpen) {
            $this->pages[] = $this->currentOps;
            $this->currentOps = [];
            $this->pageOpen = false;
        }

        if (!$this->pages) {
            $this->startNewPage();
            $this->pages[] = $this->currentOps;
            $this->currentOps = [];
            $this->pageOpen = false;
        }

        $objects = [];
        $pageReferences = [];
        $objectIndex = 6; // reserve 1-5 for catalog, pages, fonts

        $imageReferences = [];
        foreach ($this->imageResources as $name => $image) {
            $stream = '<< '
                . '/Type /XObject '
                . '/Subtype /Image '
                . '/Width ' . max(1, (int)$image['width']) . ' '
                . '/Height ' . max(1, (int)$image['height']) . ' '
                . '/ColorSpace ' . $image['colorSpace'] . ' '
                . '/BitsPerComponent ' . max(1, (int)$image['bits']) . ' '
                . '/Filter /' . $image['filter'] . ' '
                . '/Length ' . strlen($image['data']) . " >>\nstream\n"
                . $image['data'] . 'endstream';
            $objNum = $objectIndex++;
            $objects[$objNum] = $stream;
            $imageReferences[$name] = $objNum;
        }

        $totalPages = count($this->pages);
        foreach ($this->pages as $pageIndex => $ops) {
            $resolvedOps = [];
            foreach ($ops as $op) {
                $resolvedOps[] = str_replace(
                    [self::PAGE_TOKEN, self::PAGE_TOTAL_TOKEN],
                    [(string)($pageIndex + 1), (string)$totalPages],
                    $op
                );
            }
            $content = implode("\n", $resolvedOps) . "\n";
            $contentObjNum = $objectIndex++;
            $objects[$contentObjNum] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $pageObjNum = $objectIndex++;
            $pageReferences[] = $pageObjNum;
            $resources = '/Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>';
            if ($imageReferences !== []) {
                $resources .= ' /XObject <<';
                foreach ($imageReferences as $resourceName => $ref) {
                    $resources .= ' /' . $resourceName . ' ' . $ref . ' 0 R';
                }
                $resources .= ' >>';
            }
            $resources .= ' >> ';
            $pageObject = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->formatFloat($this->width) . ' ' . $this->formatFloat($this->height) . '] '
                . $resources
                . '/Contents ' . $contentObjNum . ' 0 R >>';
            $objects[$pageObjNum] = $pageObject;
        }

        $pagesObject = '<< /Type /Pages /Kids [';
        foreach ($pageReferences as $ref) {
            $pagesObject .= ' ' . $ref . ' 0 R';
        }
        $pagesObject .= ' ] /Count ' . count($pageReferences) . ' >>';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = $pagesObject;
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Calibri >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        ksort($objects);

        $output = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $num => $content) {
            $offsets[$num] = strlen($output);
            $output .= $num . " 0 obj\n" . $content . "\nendobj\n";
        }

        $xrefPosition = strlen($output);
        $maxObject = max(array_keys($objects));
        $output .= 'xref\n0 ' . ($maxObject + 1) . "\n";
        $output .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $offset = $offsets[$i] ?? 0;
            $output .= sprintf("%010d 00000 n \n", $offset);
        }

        $output .= 'trailer << /Size ' . ($maxObject + 1) . ' /Root 1 0 R >>' . "\n";
        $output .= 'startxref' . "\n" . $xrefPosition . "\n%%EOF";

        return $output;
    }

    private function addTextBlock(string $text, float $fontSize, string $fontKey, float $lineSpacing, bool $addSpacer = true): void
    {
        $this->ensurePage();
        $lines = $this->wrapText($text, $fontSize);
        $lineHeight = $this->lineHeight($fontSize, $lineSpacing);
        foreach ($lines as $line) {
            $this->ensureSpace($lineHeight);
            $this->writeTextLine($line, $fontKey, $fontSize);
            $this->cursorY -= $lineHeight;
        }
        if ($addSpacer) {
            $this->cursorY -= 2.0;
        }
    }

    private function ensurePage(): void
    {
        if (!$this->pageOpen) {
            $this->startNewPage();
        }
    }

    private function startNewPage(): void
    {
        if ($this->pageOpen) {
            $this->pages[] = $this->currentOps;
        }
        $this->pageNumber = count($this->pages) + 1;
        $this->currentOps = [];
        $this->pageOpen = true;
        $this->cursorY = $this->height - $this->marginTop;
        $this->renderHeader();
        $this->renderFooter();
    }

    private function ensureSpace(float $lineHeight): void
    {
        if ($this->cursorY - $lineHeight <= $this->marginBottom) {
            $this->startNewPage();
        }
    }

    private function writeTextLine(string $text, string $fontKey, float $fontSize): void
    {
        $escaped = $this->escapeText($text);
        $x = $this->marginLeft;
        $y = $this->cursorY;
        $this->currentOps[] = sprintf('BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $fontKey, $fontSize, $x, $y, $escaped);
    }

    private function writeMonospaceLine(string $text, float $fontSize): void
    {
        $escaped = $this->escapeText($text);
        $x = $this->marginLeft;
        $y = $this->cursorY;
        $this->currentOps[] = sprintf('BT /F3 %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET', $fontSize, $x, $y, $escaped);
    }

    private function drawText(string $text, string $fontKey, float $fontSize, float $x, float $y): void
    {
        $escaped = $this->escapeText($text);
        $this->currentOps[] = sprintf(
            'BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET',
            $fontKey,
            $this->formatFloat($fontSize),
            $this->formatFloat($x),
            $this->formatFloat($y),
            $escaped
        );
    }

    private function drawImage(string $name, float $x, float $y, float $width, float $height): void
    {
        if (!isset($this->imageResources[$name]) || $width <= 0.0 || $height <= 0.0) {
            return;
        }

        $this->currentOps[] = sprintf(
            'q %s 0 0 %s %s %s cm /%s Do Q',
            $this->formatFloat($width),
            $this->formatFloat($height),
            $this->formatFloat($x),
            $this->formatFloat($y),
            $name
        );
    }

    private function drawLine(float $startX, float $startY, float $endX, float $endY, float $lineWidth = 1.0): void
    {
        $this->currentOps[] = sprintf(
            'q %s w %s %s m %s %s l S Q',
            $this->formatFloat($lineWidth),
            $this->formatFloat($startX),
            $this->formatFloat($startY),
            $this->formatFloat($endX),
            $this->formatFloat($endY)
        );
    }

    private function drawLineWithColor(float $startX, float $startY, float $endX, float $endY, array $rgb, float $lineWidth = 1.0): void
    {
        $r = $this->formatFloat(max(0, min(255, (float)($rgb[0] ?? 0))) / 255);
        $g = $this->formatFloat(max(0, min(255, (float)($rgb[1] ?? 0))) / 255);
        $b = $this->formatFloat(max(0, min(255, (float)($rgb[2] ?? 0))) / 255);

        $this->currentOps[] = sprintf(
            'q %s %s %s RG %s w %s %s m %s %s l S Q',
            $r,
            $g,
            $b,
            $this->formatFloat($lineWidth),
            $this->formatFloat($startX),
            $this->formatFloat($startY),
            $this->formatFloat($endX),
            $this->formatFloat($endY)
        );
    }

    private function drawFilledRect(float $x, float $y, float $width, float $height, array $rgb): void
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }
        $r = $this->formatFloat(max(0, min(255, (float)($rgb[0] ?? 0))) / 255);
        $g = $this->formatFloat(max(0, min(255, (float)($rgb[1] ?? 0))) / 255);
        $b = $this->formatFloat(max(0, min(255, (float)($rgb[2] ?? 0))) / 255);

        $this->currentOps[] = sprintf(
            'q %s %s %s rg %s %s %s %s re f Q',
            $r,
            $g,
            $b,
            $this->formatFloat($x),
            $this->formatFloat($y),
            $this->formatFloat($width),
            $this->formatFloat($height)
        );
    }

    private function drawRectOutline(float $x, float $y, float $width, float $height, array $rgb, float $lineWidth = 0.5): void
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }
        $r = $this->formatFloat(max(0, min(255, (float)($rgb[0] ?? 0))) / 255);
        $g = $this->formatFloat(max(0, min(255, (float)($rgb[1] ?? 0))) / 255);
        $b = $this->formatFloat(max(0, min(255, (float)($rgb[2] ?? 0))) / 255);
        $this->currentOps[] = sprintf(
            'q %s %s %s RG %s w %s %s %s %s re S Q',
            $r,
            $g,
            $b,
            $this->formatFloat($lineWidth),
            $this->formatFloat($x),
            $this->formatFloat($y),
            $this->formatFloat($width),
            $this->formatFloat($height)
        );
    }

    private function renderHeader(): void
    {
        $topY = $this->height - $this->marginTop;
        if ($this->headerConfig === null) {
            $this->cursorY = $topY;
            return;
        }

        $config = $this->headerConfig;
        $image = $config['image'] ?? null;
        $textX = $this->marginLeft;
        $imageHeight = 0.0;
        $barColor = is_array($config['bar_color'] ?? null) ? $config['bar_color'] : [32, 115, 191];
        $textColor = is_array($config['text_color'] ?? null) ? $config['text_color'] : [32, 41, 59];
        $topBarHeight = max(2.0, (float)($config['top_bar_height'] ?? 8.0));
        $topBarGap = max(0.0, (float)($config['top_bar_gap'] ?? 8.0));

        $titleHeight = $config['title'] !== null ? (float)$config['title_font_size'] : 0.0;
        $subtitleHeight = $config['subtitle'] !== null ? (float)$config['subtitle_font_size'] : 0.0;
        $lineGap = ($config['title'] !== null && $config['subtitle'] !== null) ? (float)$config['line_gap'] : 0.0;
        $textContentHeight = $titleHeight + $subtitleHeight + $lineGap;
        $headerBarY = $topY - $topBarHeight;
        $barWidth = $this->width - $this->marginLeft - $this->marginRight;
        $this->drawFilledRect($this->marginLeft, $headerBarY, $barWidth, $topBarHeight, $barColor);

        $contentTopY = $headerBarY - $topBarGap - (float)$config['top_padding'];

        if (is_array($image) && $image['width'] > 0.0 && $image['height'] > 0.0) {
            $imageX = $this->marginLeft;
            $imageY = $contentTopY - $image['height'];
            $this->drawImage($image['name'], $imageX, $imageY, $image['width'], $image['height']);
            $textX += $image['width'] + $config['text_gap'];
            $imageHeight = $image['height'];
        } else {
            $textX += $config['text_gap'];
        }

        $this->setFillColor($textColor);

        $availableTextWidth = max(80.0, ($this->width - $this->marginRight) - $textX);
        $textBottomY = $contentTopY;
        $currentTop = $contentTopY;
        if ($config['title'] !== null) {
            $titleLines = $this->wrapTextToWidth((string)$config['title'], (float)$config['title_font_size'], $availableTextWidth);
            if (count($titleLines) > 2) {
                $titleLines = array_slice($titleLines, 0, 2);
                $titleLines[1] = rtrim($titleLines[1]) . '…';
            }
            foreach ($titleLines as $lineIndex => $titleLine) {
                $currentTop -= $config['title_font_size'];
                $titleX = $this->width - $this->marginRight;
                $titleWidth = $this->estimateTextWidth($titleLine, (float)$config['title_font_size']);
                $titleX = max($textX, $titleX - $titleWidth);
                $this->drawText($titleLine, 'F2', $config['title_font_size'], $titleX, $currentTop);
                $textBottomY = min($textBottomY, $currentTop - (float)$config['title_font_size']);
                if ($lineIndex < count($titleLines) - 1) {
                    $currentTop -= max(2.0, $config['line_gap'] * 0.5);
                }
            }
            $currentTop -= $config['line_gap'];
        }

        if ($config['subtitle'] !== null) {
            $subtitleLines = $this->wrapTextToWidth((string)$config['subtitle'], (float)$config['subtitle_font_size'], $availableTextWidth);
            if (count($subtitleLines) > 2) {
                $subtitleLines = array_slice($subtitleLines, 0, 2);
                $subtitleLines[1] = rtrim($subtitleLines[1]) . '…';
            }
            foreach ($subtitleLines as $lineIndex => $subtitleLine) {
                $currentTop -= $config['subtitle_font_size'];
                $subtitleX = $this->width - $this->marginRight;
                $subtitleWidth = $this->estimateTextWidth($subtitleLine, (float)$config['subtitle_font_size']);
                $subtitleX = max($textX, $subtitleX - $subtitleWidth);
                $this->drawText($subtitleLine, 'F1', $config['subtitle_font_size'], $subtitleX, $currentTop);
                $textBottomY = min($textBottomY, $currentTop - (float)$config['subtitle_font_size']);
                if ($lineIndex < count($subtitleLines) - 1) {
                    $currentTop -= max(2.0, $config['line_gap'] * 0.4);
                }
            }
        }
        $this->resetFillColor();

        $contentGap = (float)($config['content_gap'] ?? 10.0);
        $ruleGap = (float)($config['rule_gap'] ?? 6.0);
        $headerContentBottomY = min($contentTopY - $imageHeight, $textBottomY);
        $ruleY = $headerContentBottomY - (float)$config['bottom_padding'] - $ruleGap;
        $this->drawLineWithColor(
            $this->marginLeft,
            $ruleY,
            $this->width - $this->marginRight,
            $ruleY,
            $barColor,
            0.8
        );

        $this->cursorY = $ruleY - $contentGap;
    }

    private function renderFooter(): void
    {
        if ($this->pageNumber <= 0) {
            return;
        }

        $barColor = [32, 115, 191];
        if ($this->headerConfig !== null && is_array($this->headerConfig['bar_color'] ?? null)) {
            $barColor = $this->headerConfig['bar_color'];
        }
        $barWidth = $this->width - $this->marginLeft - $this->marginRight;
        $barY = $this->marginBottom - 8.0;
        $this->drawFilledRect($this->marginLeft, $barY, $barWidth, 3.0, $barColor);

        $label = 'Page ' . self::PAGE_TOKEN . ' of ' . self::PAGE_TOTAL_TOKEN;
        $fontSize = 9.0;
        $textWidth = $this->estimateTextWidth($label, $fontSize);
        $x = ($this->width / 2) - ($textWidth / 2);
        $y = max(20.0, $barY - 12.0);
        $this->drawText($label, 'F1', $fontSize, $x, $y);
    }

    private function setFillColor(array $rgb): void
    {
        $r = $this->formatFloat(max(0, min(255, (float)($rgb[0] ?? 0))) / 255);
        $g = $this->formatFloat(max(0, min(255, (float)($rgb[1] ?? 0))) / 255);
        $b = $this->formatFloat(max(0, min(255, (float)($rgb[2] ?? 0))) / 255);
        $this->currentOps[] = sprintf('%s %s %s rg', $r, $g, $b);
    }

    private function resetFillColor(): void
    {
        $this->currentOps[] = '0 0 0 rg';
    }

    private function renderFooter(): void
    {
        if ($this->pageNumber <= 0) {
            return;
        }

        $barColor = [32, 115, 191];
        if ($this->headerConfig !== null && is_array($this->headerConfig['bar_color'] ?? null)) {
            $barColor = $this->headerConfig['bar_color'];
        }
        $barWidth = $this->width - $this->marginLeft - $this->marginRight;
        $barY = $this->marginBottom - 8.0;
        $this->drawFilledRect($this->marginLeft, $barY, $barWidth, 3.0, $barColor);

        $label = 'Page ' . self::PAGE_TOKEN . ' of ' . self::PAGE_TOTAL_TOKEN;
        $fontSize = 9.0;
        $textWidth = $this->estimateTextWidth($label, $fontSize);
        $x = ($this->width / 2) - ($textWidth / 2);
        $y = max(20.0, $barY - 12.0);
        $this->drawText($label, 'F1', $fontSize, $x, $y);
    }

    private function setFillColor(array $rgb): void
    {
        $r = $this->formatFloat(max(0, min(255, (float)($rgb[0] ?? 0))) / 255);
        $g = $this->formatFloat(max(0, min(255, (float)($rgb[1] ?? 0))) / 255);
        $b = $this->formatFloat(max(0, min(255, (float)($rgb[2] ?? 0))) / 255);
        $this->currentOps[] = sprintf('%s %s %s rg', $r, $g, $b);
    }

    private function resetFillColor(): void
    {
        $this->currentOps[] = '0 0 0 rg';
    }

    private function normalizeHeaderText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $value);
        $trimmed = trim($normalized ?? '');
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function wrapText(string $text, float $fontSize): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));
        if ($normalized === '') {
            return [''];
        }

        $availableWidth = $this->width - $this->marginLeft - $this->marginRight - 4.0;
        if ($availableWidth <= 0.0) {
            return [$normalized];
        }

        $words = preg_split('/\s+/u', $normalized);
        if (!is_array($words)) {
            $words = [$normalized];
        }

        $spaceWidth = $this->estimateTextWidth(' ', $fontSize);
        $lines = [];
        $currentLine = '';
        $currentWidth = 0.0;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $segments = $this->segmentWordToFit($word, $availableWidth, $fontSize);
            foreach ($segments as $index => $segment) {
                $segmentWidth = $this->estimateTextWidth($segment, $fontSize);

                if ($currentLine === '') {
                    $currentLine = $segment;
                    $currentWidth = $segmentWidth;
                    continue;
                }

                $requiresSpace = ($index === 0);
                $candidateWidth = $currentWidth + ($requiresSpace ? $spaceWidth : 0.0) + $segmentWidth;

                if ($requiresSpace && $candidateWidth <= $availableWidth) {
                    $currentLine .= ' ' . $segment;
                    $currentWidth = $candidateWidth;
                } else {
                    $lines[] = $currentLine;
                    $currentLine = $segment;
                    $currentWidth = $segmentWidth;
                }
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines ?: [''];
    }

    private function segmentWordToFit(string $word, float $availableWidth, float $fontSize): array
    {
        $wordWidth = $this->estimateTextWidth($word, $fontSize);
        if ($wordWidth <= $availableWidth) {
            return [$word];
        }

        $characters = $this->utf8Characters($word);
        if ($characters === []) {
            return [$word];
        }

        $segments = [];
        $current = '';
        $currentWidth = 0.0;

        foreach ($characters as $char) {
            $charWidth = $this->characterWidth($char, $fontSize);
            if ($current !== '' && $currentWidth + $charWidth > $availableWidth) {
                $segments[] = $current;
                $current = $char;
                $currentWidth = $charWidth;
                continue;
            }

            $current .= $char;
            $currentWidth += $charWidth;
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments ?: [$word];
    }

    private function estimateTextWidth(string $text, float $fontSize): float
    {
        if ($text === '') {
            return 0.0;
        }

        $width = 0.0;
        foreach ($this->utf8Characters($text) as $char) {
            $width += $this->characterWidth($char, $fontSize);
        }

        return max($width, $fontSize * 0.5);
    }

    private function characterWidth(string $char, float $fontSize): float
    {
        if ($char === ' ') {
            return $fontSize * 0.32;
        }

        if (preg_match("/[ilI1!\\.,:'`]/u", $char)) {
            return $fontSize * 0.36;
        }

        if (preg_match('/[mwMW@#%&0-9]/u', $char)) {
            return $fontSize * 0.72;
        }

        if (preg_match('/\p{Lu}/u', $char)) {
            return $fontSize * 0.64;
        }

        return $fontSize * 0.55;
    }

    private function utf8Characters(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($characters)) {
            return $characters;
        }

        return str_split($text);
    }

    private function lineHeight(float $fontSize, float $multiplier): float
    {
        return $fontSize * $multiplier;
    }

    private function escapeText(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace("/[\r\n]+/", ' ', $text);
    }

    private function formatTableRow(array $row, array $columnWidths): string
    {
        $cells = [];
        $count = min(count($row), count($columnWidths));
        for ($i = 0; $i < $count; $i++) {
            $cell = (string)$row[$i];
            $width = max(1, (int)round($columnWidths[$i]));
            $cell = mb_strimwidth($cell, 0, $width, '', 'UTF-8');
            $cells[] = str_pad($cell, $width);
        }
        return implode('  ', $cells);
    }

    private function formatTableSeparator(array $columnWidths): string
    {
        $parts = [];
        foreach ($columnWidths as $width) {
            $parts[] = str_repeat('-', max(1, (int)round($width)));
        }
        return implode('  ', $parts);
    }

    private function resolveTableColumnWidths(array $columnWidths, float $fontSize): array
    {
        $count = count($columnWidths);
        if ($count === 0) {
            return [];
        }

        $availableWidth = max(0.0, $this->width - $this->marginLeft - $this->marginRight);
        if ($availableWidth <= 0.0) {
            return array_fill(0, $count, 1);
        }

        $charWidth = $this->monospaceCharacterWidth($fontSize);
        $availableChars = max($count, (int)floor($availableWidth / $charWidth));
        $spacingChars = 2 * max(0, $count - 1);
        $usableChars = max($count, $availableChars - $spacingChars);

        $weights = [];
        foreach ($columnWidths as $width) {
            $weights[] = max(1.0, (float)$width);
        }

        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0.0) {
            $weights = array_fill(0, $count, 1.0);
            $weightTotal = (float)$count;
        }

        $assignments = [];
        $fractions = [];
        $allocated = 0;
        foreach ($weights as $index => $weight) {
            $ideal = $usableChars * ($weight / $weightTotal);
            $rawFloor = (int)floor($ideal);
            $fraction = $ideal - $rawFloor;
            $value = max(1, $rawFloor);
            $assignments[$index] = $value;
            $fractions[$index] = $fraction;
            $allocated += $value;
        }

        if ($allocated > $usableChars) {
            $excess = $allocated - $usableChars;
            $order = array_keys($assignments);
            usort($order, static function ($a, $b) use ($fractions, $assignments): int {
                $cmp = $fractions[$a] <=> $fractions[$b];
                if ($cmp === 0) {
                    return $assignments[$b] <=> $assignments[$a];
                }
                return $cmp;
            });
            while ($excess > 0) {
                $adjusted = false;
                foreach ($order as $idx) {
                    if ($excess <= 0) {
                        break 2;
                    }
                    if ($assignments[$idx] <= 1) {
                        continue;
                    }
                    $assignments[$idx]--;
                    $excess--;
                    $adjusted = true;
                    if ($excess <= 0) {
                        break;
                    }
                }
                if (!$adjusted) {
                    break;
                }
            }
            $allocated = array_sum($assignments);
        }

        if ($allocated < $usableChars) {
            $remaining = $usableChars - $allocated;
            $order = array_keys($assignments);
            usort($order, static function ($a, $b) use ($fractions): int {
                return $fractions[$b] <=> $fractions[$a];
            });
            while ($remaining > 0) {
                foreach ($order as $idx) {
                    if ($remaining <= 0) {
                        break 2;
                    }
                    $assignments[$idx]++;
                    $remaining--;
                }
            }
        }

        ksort($assignments);

        return array_values($assignments);
    }

    private function resolveTableColumnWidthsInPoints(array $columnWidths): array
    {
        $count = count($columnWidths);
        if ($count === 0) {
            return [];
        }

        $availableWidth = max(0.0, $this->width - $this->marginLeft - $this->marginRight);
        if ($availableWidth <= 0.0) {
            return array_fill(0, $count, 1.0);
        }

        $weights = [];
        foreach ($columnWidths as $width) {
            $weights[] = max(1.0, (float)$width);
        }

        $total = array_sum($weights);
        if ($total <= 0.0) {
            return array_fill(0, $count, $availableWidth / $count);
        }

        $result = [];
        foreach ($weights as $weight) {
            $result[] = $availableWidth * ($weight / $total);
        }

        return $result;
    }

    private function monospaceCharacterWidth(float $fontSize): float
    {
        return max(0.1, $fontSize * 0.6);
    }

    private function formatFloat(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

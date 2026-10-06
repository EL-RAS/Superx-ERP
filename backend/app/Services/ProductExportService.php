<?php

namespace App\Services;

use App\Models\Product;

/**
 * Renders the tenant's product catalog as a downloadable spreadsheet.
 *
 * The .xlsx writer is deliberately dependency-free (ZipArchive + inline-string
 * cells), mirroring the hand-rolled reader in ProductImportService, so the
 * project keeps its "no spreadsheet library" rule.
 */
class ProductExportService
{
    /**
     * Column headers, in the order they are written to the file.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'ID',
        'Name',
        'SKU',
        'Barcode',
        'Category',
        'Cost Price',
        'Selling Price',
        'Current Stock',
        'Status',
        'Created At',
    ];

    /**
     * Header row plus one row per product, scoped to the given tenant.
     *
     * @return list<list<string|float>>
     */
    public function rows(string $businessId): array
    {
        $rows = [self::COLUMNS];

        // The BusinessScope global scope already restricts Product::query() to
        // the active tenant; the explicit filter makes the guarantee hold even
        // if this ever runs outside the business middleware.
        $products = Product::query()
            ->with('category:id,name')
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();

        foreach ($products as $product) {
            $rows[] = [
                (string) $product->id,
                (string) $product->name,
                (string) ($product->sku ?? ''),
                (string) ($product->barcode ?? ''),
                $this->categoryName($product),
                (float) $product->cost,
                (float) $product->price,
                (float) $product->stock_quantity,
                $product->is_active ? 'Active' : 'Inactive',
                $product->created_at?->format('Y-m-d H:i') ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Resolve the display name of a product's category.
     *
     * Product carries a legacy `category` string column AND a `category()`
     * relation, so `$product->category` always returns the string and the
     * eager-loaded relation has to be read through getRelation().
     */
    protected function categoryName(Product $product): string
    {
        if ($product->relationLoaded('category')) {
            $related = $product->getRelation('category');

            if ($related !== null) {
                return (string) $related->name;
            }
        }

        return (string) ($product->getAttributes()['category'] ?? '');
    }

    /**
     * UTF-8 BOM + RFC 4180 CSV, readable by Excel without extra configuration.
     *
     * @param  list<list<string|float>>  $rows
     */
    public function toCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Could not allocate a buffer for the CSV export.');
        }

        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Could not read the generated CSV export.');
        }

        return "\xEF\xBB\xBF".$csv;
    }

    /**
     * Build a minimal but spec-valid .xlsx workbook and return its bytes.
     *
     * @param  list<list<string|float>>  $rows
     */
    public function toXlsx(array $rows, string $sheetName = 'Products'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sx_export_');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the XLSX export.');
        }

        try {
            $zip = new \ZipArchive;

            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the XLSX export for writing.');
            }

            $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES_XML);
            $zip->addFromString('_rels/.rels', self::PACKAGE_RELS_XML);
            $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS_XML);
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
            $zip->close();

            $bytes = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($bytes === false) {
            throw new \RuntimeException('Could not read the generated XLSX export.');
        }

        return $bytes;
    }

    /** @param  list<list<string|float>>  $rows */
    protected function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>';

        foreach (array_values($rows) as $rowNumber => $row) {
            $ref = $rowNumber + 1;
            $xml .= '<row r="'.$ref.'">';

            foreach (array_values($row) as $columnNumber => $value) {
                $cell = $this->columnLetter($columnNumber).$ref;

                if (is_float($value) || is_int($value)) {
                    $xml .= '<c r="'.$cell.'"><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'
                        .htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                        .'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    protected function workbookXml(string $sheetName): string
    {
        $name = htmlspecialchars(
            // Excel forbids these characters in sheet names and caps them at 31.
            mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $sheetName), 0, 31),
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /** 0 -> A, 25 -> Z, 26 -> AA, ... */
    protected function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private const CONTENT_TYPES_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>';

    private const PACKAGE_RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    private const WORKBOOK_RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'</Relationships>';
}

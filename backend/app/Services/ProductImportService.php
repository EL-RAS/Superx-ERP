<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parses product bulk-import files (.xlsx / .csv) and upserts rows
 * against the products table keyed by barcode.
 *
 * Expected columns (header row): barcode, name, cost_price,
 * selling_price, stock_quantity, tax_rate, category.
 *
 * Matching rules:
 *  - Rows with a barcode that already exists are UPDATED (cost, price,
 *    tax, stock, category) and re-activated.
 *  - Rows with a new (or empty) barcode are INSERTED; when no barcode
 *    is present, the product name is used as the uniquing key instead.
 */
class ProductImportService
{
    /**
     * @param  string  $path  absolute path to the uploaded file
     * @param  string|null  $extension  original client extension (fallback for
     *                                  temp paths that drop their extension)
     * @param  float  $defaultTaxRate  business default applied when the file has
     *                                 no tax_rate column (products.tax_rate NOT NULL)
     * @return array{created:int,updated:int,rows:int}
     */
    public function import(string $path, ?string $extension, string $businessId, int $userId, float $defaultTaxRate = 16): array
    {
        $rows = $this->parse($path, $extension)
            ->filter(fn (array $row) => ($row['name'] ?? null) !== null && ($row['name'] ?? null) !== '')
            ->values();

        $imported = DB::transaction(function () use ($rows, $businessId, $userId, $defaultTaxRate) {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $barcode = $this->normalizeBarcode($row['barcode'] ?? null);

                if ($barcode !== null) {
                    $product = Product::where('business_id', $businessId)
                        ->where('barcode', $barcode)
                        ->first();
                } else {
                    $product = Product::where('business_id', $businessId)
                        ->where('name', $row['name'])
                        ->first();
                }

                $attributes = [
                    'name' => $row['name'],
                    'cost' => $row['cost_price'] ?? 0,
                    'price' => $row['selling_price'] ?? 0,
                    'tax_rate' => $row['tax_rate'] ?? $defaultTaxRate,
                    'has_expiry' => true,
                    'has_batch' => true,
                    'is_active' => true,
                    'created_by' => $userId,
                ];

                $category = $row['category'] ?? null;
                if ($category !== null && $category !== '') {
                    $attributes['category'] = $category;
                }

                $importedStock = round((float) ($row['stock_quantity'] ?? 0), 2);

                if ($product) {
                    // Preserve SKU / unit on existing rows; only update
                    // pricing, tax and category (stock lands in a new batch,
                    // exactly like a goods receipt).
                    $product->fill($attributes);
                    $product->save();
                    $updated++;
                } else {
                    $attributes['business_id'] = $businessId;
                    $attributes['unit'] = 'pcs';
                    $attributes['min_stock'] = 0;
                    $attributes['stock_quantity'] = $importedStock;
                    $attributes['sku'] = $this->generateSku($businessId, $category);
                    $attributes['barcode'] = $barcode;
                    $product = Product::create($attributes);
                    $created++;
                }

                // POS deducts stock from active batches (FEFO), so every
                // imported quantity must be backed by a real batch row or
                // checkout fails with "Insufficient batch stock".
                if ($importedStock > 0) {
                    $this->provisionInitialBatch(
                        $product,
                        $businessId,
                        $importedStock,
                        (float) ($row['cost_price'] ?? 0),
                        (float) ($row['selling_price'] ?? 0),
                    );
                }

                // Re-sync product stock to the batch total so the invariant
                // "stock == sum of active batch quantities" always holds.
                $product->recalculateStockQuantity();
            }

            return [$created, $updated];
        });

        return [
            'created' => $imported[0],
            'updated' => $imported[1],
            'rows' => $rows->count(),
        ];
    }

    /**
     * Back an imported quantity with a real, active batch row so POS / FEFO
     * deduction can find it. Uses a deterministic, per-product unique number
     * (an existing batch or a re-import of the same product gets the next
     * suffix, keeping the [business_id, product_id, batch_number] unique key).
     */
    protected function provisionInitialBatch(Product $product, string $businessId, float $quantity, float $costPerUnit, float $sellingPrice): void
    {
        $existing = ProductBatch::withTrashed()
            ->where('product_id', $product->id)
            ->count();

        ProductBatch::create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'batch_number' => sprintf('IMP-INIT-%s-%d', $product->id, $existing + 1),
            'quantity' => $quantity,
            'cost_per_unit' => round($costPerUnit, 2),
            'total_cost' => round($quantity * $costPerUnit, 2),
            'selling_price' => round($sellingPrice, 2),
            'received_date' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * Parse either a .csv or .xlsx file into a collection of assoc rows.
     *
     * @return Collection<int, array<string, string|null>>
     */
    protected function parse(string $path, ?string $extension): Collection
    {
        $ext = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION) ?? '');

        if ($ext === 'csv') {
            return $this->parseCsv($path);
        }

        if ($ext === 'xlsx') {
            return $this->parseXlsx($path);
        }

        throw ValidationException::withMessages([
            'file' => ['Unsupported file type. Upload a .xlsx or .csv file.'],
        ]);
    }

    /** @return Collection<int, array<string, string|null>> */
    protected function parseCsv(string $path): Collection
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['Could not read the uploaded file.']]);
        }

        $header = null;
        $rows = [];

        try {
            while (($raw = fgetcsv($handle)) !== false) {
                if ($raw === [null]) {
                    continue;
                }
                if ($header === null) {
                    $header = array_map(fn ($h) => $this->canonicalColumn((string) $h), $raw);

                    continue;
                }
                $rows[] = $this->zipRow($header, $raw);
            }
        } finally {
            fclose($handle);
        }

        return collect($rows);
    }

    /**
     * Lightweight .xlsx reader using PHP's built-in ZipArchive + SimpleXML
     * (no external dependency). Reads the first worksheet together with the
     * shared string table.
     *
     * @return Collection<int, array<string, string|null>>
     */
    protected function parseXlsx(string $path): Collection
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => ['Could not open the .xlsx file.']]);
        }

        try {
            $shared = $this->readSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                // Fall back to the first sheet name found in the workbook.
                $sheetXml = $this->firstSheetXml($zip);
            }
            if ($sheetXml === false) {
                throw ValidationException::withMessages(['file' => ['No worksheet found in the .xlsx file.']]);
            }
        } finally {
            $zip->close();
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw ValidationException::withMessages(['file' => ['Could not read the .xlsx worksheet.']]);
        }

        $ns = $xml->getNamespaces(true);
        $main = $ns[''] ?? null;

        $rows = [];
        $header = null;

        foreach ($xml->sheetData->row as $rowNode) {
            $cells = [];
            foreach ($rowNode->c as $cell) {
                $ref = (string) $cell['r'];
                $type = (string) $cell['t'];
                $value = trim((string) $cell->v);

                if ($type === 's') {
                    $idx = (int) $value;
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = trim((string) $cell->is->t);
                } elseif ($type === 'str') {
                    $value = trim((string) $cell->v);
                }

                $column = preg_replace('/[0-9]+/', '', $ref) ?: '';
                $cells[$column] = $value;
            }

            // Re-order cells by their column letter so merged layouts don't break.
            $ordered = [];
            foreach (array_keys($cells) as $col) {
                $ordered[$this->colIndex($col)] = $cells[$col];
            }
            ksort($ordered);

            if ($header === null) {
                $header = array_map(fn ($h) => $this->canonicalColumn((string) $h), array_values($ordered));

                continue;
            }

            $rows[] = $this->zipRow($header, array_values($ordered));
        }

        return collect($rows);
    }

    /** @return array<int, string> */
    protected function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = [];
        $sst = simplexml_load_string($xml);
        if ($sst === false) {
            return $shared;
        }

        foreach ($sst->si as $si) {
            // Concatenate all <t> fragments to recover full cell text.
            $text = '';
            if ($si->t !== null) {
                $text = (string) $si->t;
            } elseif ($si->r !== null) {
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
            }
            $shared[] = trim($text);
        }

        return $shared;
    }

    protected function firstSheetXml(\ZipArchive $zip): string|false
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb === false) {
            return false;
        }

        $xml = simplexml_load_string($wb);
        if ($xml === false) {
            return false;
        }

        $ns = $xml->getNamespaces(true);
        $main = $ns[''] ?? null;
        $name = null;
        foreach ($xml->sheets->sheet as $sheet) {
            $name = (string) ($main ? $sheet->attributes($main)->name : $sheet['name']);
            break;
        }

        if ($name === null) {
            return false;
        }

        // Worksheet files are named by r:id → find it in workbook rels.
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            return false;
        }
        $rels = simplexml_load_string($relsXml);
        if ($rels === false) {
            return false;
        }

        $rid = null;
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Target'] === 'worksheets/sheet1.xml') {
                $rid = (string) $rel['Id'];
                break;
            }
        }

        if ($rid === null) {
            return false;
        }

        return $zip->getFromName("xl/worksheets/{$rid}.xml") ?: $zip->getFromName('xl/worksheets/sheet1.xml') ?: false;
    }

    /** @param array<int, string> $header @param array<int, string> $raw */
    protected function zipRow(array $header, array $raw): array
    {
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = $raw[$i] ?? null;
        }

        return $row;
    }

    protected function slugify(string $value): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value) ?? '', '_'));
    }

    /**
     * Resolve a raw header cell to a canonical import column.
     *
     * Matching is case-insensitive and space/punctuation-insensitive, and a
     * number of common synonyms are folded onto the canonical keys the importer
     * actually reads. Unknown columns collapse to a null key so they are ignored.
     */
    protected function canonicalColumn(string $header): ?string
    {
        $slug = $this->slugify($header);

        $aliases = [
            'barcode' => ['barcode', 'code', 'upc', 'upc_a', 'ean', 'ean13', 'sku', 'sku_cd', 'supplier_code'],
            'name' => ['name', 'product_name', 'item_name', 'description', 'title', 'product'],
            'cost_price' => ['cost_price', 'costprice', 'cost', 'unit_cost', 'purchase_price', 'buying_price', 'supplier_price'],
            'selling_price' => ['selling_price', 'sellingprice', 'price', 'unit_price', 'sell_price', 'retail_price', 'sale_price'],
            'stock_quantity' => ['stock_quantity', 'stockqty', 'stock', 'quantity', 'qty', 'qty_on_hand', 'on_hand', 'available', 'quantity_on_hand'],
            'tax_rate' => ['tax_rate', 'taxrate', 'tax', 'vat', 'vat_rate', 'gst'],
            'category' => ['category', 'category_name', 'group', 'department', 'division'],
        ];

        foreach ($aliases as $canonical => $synonyms) {
            if (in_array($slug, $synonyms, true)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Normalize a barcode cell into a clean plain-digits string.
     *
     * Handles scientific notation and floats that Excel/CSV writers produce for
     * long numeric codes (e.g. "6.291E+12"), casting back to the real digits.
     * Returns null when the value contains no usable digits.
     */
    protected function normalizeBarcode(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        // Scientific / engineering notation produced by Excel for big numbers.
        // e.g. 6.29104150021321E+12 -> 6291041500213
        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?[eE]([+-]?)([0-9]+)$/', $value, $m)) {
            $sign = ($m[1] === '-' ? '-' : '');
            $int = $m[2];
            $frac = $m[3] ?? '';
            $exp = (int) ($m[4] === '-' ? -$m[5] : $m[5]);

            $plain = $int.$frac;
            $decimalPos = strlen($int) + $exp;

            if ($decimalPos > 0 && $decimalPos <= strlen($plain)) {
                $digits = $sign.substr($plain, 0, $decimalPos);
            } elseif ($decimalPos > strlen($plain)) {
                $digits = $sign.$plain.str_repeat('0', $decimalPos - strlen($plain));
            } else {
                return null; // fractional result — not a barcode
            }

            return $this->barcodeDigits($digits);
        }

        // Float-formatted integer ("6291041500213.0") — the fractional part is
        // Excel noise, keep the integer. A real fraction ("6291041500213.5")
        // is not a barcode, so drop it rather than silently corrupt it.
        if (preg_match('/^(\d+)\.(\d+)$/', $value, $m)) {
            return ((int) $m[2] === 0) ? $m[1] : null;
        }

        // Plain numeric cell, possibly float-formatted ("6291041500213.0").
        return $this->barcodeDigits($value);
    }

    /**
     * Extract a clean plain-digits barcode string, returning null when empty.
     */
    protected function barcodeDigits(string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        return $digits;
    }

    protected function colIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }

    protected function generateSku(string $businessId, ?string $category): string
    {
        $business = Business::with('businessType')->find($businessId);
        $typeSlug = $business?->businessType?->slug ?? 'gen';
        $typeCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $typeSlug), 0, 4));

        $categoryCode = 'GEN';
        if ($category) {
            $categoryCode = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $category), 0, 3)) ?: 'GEN';
        }

        $count = Product::where('business_id', $businessId)->count();

        return sprintf('%s-%s-%04d', $typeCode, $categoryCode, $count + 1);
    }
}

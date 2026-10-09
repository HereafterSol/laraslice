<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Security\Access;

/**
 * Writes records on behalf of the AI copilot, behind AiEngine's table access rules.
 */
final class AiRecordWriter
{
    public function __construct(private readonly AiEngine $engine) {}

    /**
     * Insert a record for the signed-in user (from the copilot's form or a parsed request).
     * Only tables the user may create in are accepted; protected tables and secret columns never are.
     */
    public function createRecord(string $table, array $data, string $mode = 'manual'): array
    {
        if (! $this->engine->canAccessTable($table, 'create')) {
            return ['success' => false, 'message' => "You don't have permission to create records in '{$table}'."];
        }

        try {
            if (! Schema::hasTable($table)) {
                return ['success' => false, 'message' => "Table '{$table}' does not exist in the database."];
            }

            $cols = Schema::getColumnListing($table);
            $insertData = [];

            foreach ($data as $k => $v) {
                $serverOwned = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'];
                if (is_string($k) && in_array($k, $cols, true) && ! in_array($k, $serverOwned, true) && ! $this->engine->isSensitiveColumn($k)) {
                    if (str_starts_with($k, 'is_') || str_starts_with($k, 'has_')) {
                        $insertData[$k] = ($v === '1' || $v === 1 || $v === true || $v === 'on' || $v === 'true' || $v === 'active') ? 1 : 0;
                    } elseif (str_ends_with($k, '_id')) {
                        $insertData[$k] = (int) $v;
                    } elseif (preg_match('/(price|amount|cost|rate|total)/i', $k)) {
                        $insertData[$k] = (float) $v;
                    } elseif (preg_match('/(quantity|stock|count|sort|priority)/i', $k)) {
                        $insertData[$k] = (int) $v;
                    } else {
                        $insertData[$k] = $v;
                    }
                }
            }

            // Entity-specific intelligent defaults
            $nameVal = $insertData['name'] ?? ($insertData['title'] ?? '');
            if (in_array('name', $cols) && empty($insertData['name'])) {
                $insertData['name'] = $insertData['title'] ?? ($nameVal ?: 'New Item');
            }
            if (in_array('title', $cols) && empty($insertData['title'])) {
                $insertData['title'] = $insertData['name'] ?? ($nameVal ?: 'New Item');
            }
            if (in_array('slug', $cols) && empty($insertData['slug'])) {
                $insertData['slug'] = Str::slug($nameVal ?: 'item-'.rand(100, 999));
            }
            if (in_array('status', $cols) && empty($insertData['status'])) {
                $insertData['status'] = 'active';
            }
            if ($table === 'shop_products') {
                if (empty($insertData['sku']) && in_array('sku', $cols)) {
                    $insertData['sku'] = 'SKU-'.strtoupper(Str::random(6));
                }
                if (empty($insertData['price']) && in_array('price', $cols)) {
                    $insertData['price'] = 99.99;
                }
                if (empty($insertData['shop_category_id']) && in_array('shop_category_id', $cols)) {
                    $firstCat = DB::table('shop_categories')->value('id');
                    $insertData['shop_category_id'] = (int) ($firstCat ?: 1);
                }
            }
            if ($table === 'shop_variants') {
                if (empty($insertData['sku']) && in_array('sku', $cols)) {
                    $prefix = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $nameVal ?: 'VAR'), 0, 6));
                    $insertData['sku'] = ($prefix ?: 'VAR').'-'.rand(100, 999);
                }
                if (empty($insertData['price']) && in_array('price', $cols)) {
                    $insertData['price'] = 199.99;
                }
                if (empty($insertData['stock_quantity']) && in_array('stock_quantity', $cols)) {
                    $insertData['stock_quantity'] = 25;
                }
                if (in_array('shop_product_id', $cols)) {
                    $prodExists = ! empty($insertData['shop_product_id']) && DB::table('shop_products')->where('id', (int) $insertData['shop_product_id'])->exists();
                    if (! $prodExists) {
                        $firstProd = DB::table('shop_products')->value('id');
                        $insertData['shop_product_id'] = (int) ($firstProd ?: 1);
                    }
                }
            }

            // Generic foreign key guard for any table
            foreach ($cols as $c) {
                if (str_ends_with($c, '_id') && in_array($c, array_keys($insertData))) {
                    $insertData[$c] = (int) $insertData[$c];
                }
            }

            if (in_array('created_by', $cols)) {
                $insertData['created_by'] = auth()->id();
            }
            if (in_array('created_at', $cols)) {
                $insertData['created_at'] = now();
            }
            if (in_array('updated_at', $cols)) {
                $insertData['updated_at'] = now();
            }

            $id = DB::table($table)->insertGetId($insertData);
            $totalNow = DB::table($table)->count();
            $entityTitle = ucwords(str_replace(['shop_', '_'], ['', ' '], $table));

            // Log security audit safely
            try {
                if (Schema::hasTable('laraslice_audit_logs')) {
                    DB::table('laraslice_audit_logs')->insert([
                        'event' => 'ai.record.created',
                        'auditable_type' => $table,
                        'auditable_id' => $id,
                        'new_values' => json_encode($insertData),
                        'created_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
            }

            return [
                'success' => true,
                'id' => $id,
                'table' => $table,
                'entity' => $entityTitle,
                'total' => $totalNow,
                'mode' => $mode,
                'summary' => $insertData,
                'message' => "✅ **New {$entityTitle} Record Created!**\n\nSuccessfully inserted **ID #{$id}** into `{$table}` table. Current total: **{$totalNow} {$entityTitle}**.",
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Failed to insert the record. Check the values and try again.'];
        }
    }
}

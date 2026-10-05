<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\PearlXpProductUpdate;

class PearlXpController extends Controller
{
    /**
     * @OA\Post(
     *      path="/pearl-xp/product-updates",
     *      operationId="submitPearlXpProductUpdates",
     *      tags={"Pearl XP"},
     *      summary="Submit product MRP / price / stock updates by barcode",
     *      description="Master-token protected intake endpoint. Updates are stored for admin approval and do NOT change products immediately.",
     *      security={{"bearerAuth":{}}},
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="items", type="array",
     *              @OA\Items(type="object",
     *                  @OA\Property(property="barcode", type="string", example="8901234567890"),
     *                  @OA\Property(property="mrp", type="number", example=120.00),
     *                  @OA\Property(property="price", type="number", example=99.00),
     *                  @OA\Property(property="stock", type="integer", example=45)
     *              )
     *          )
     *      )),
     *      @OA\Response(response=200, description="Returns overall success flag plus per-item results"),
     *      @OA\Response(response=401, description="Missing or invalid master token"),
     * )
     */
    public function store(Request $request)
    {
        $items = $this->extractItems($request);

        if ($items === []) {
            Log::error('Pearl XP update rejected: empty or malformed payload', [
                'payload' => $request->all(),
                'reason'  => 'No items found. Expected {"items":[...]} or a single object.',
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'No update items found. Send {"items":[...]} or a single update object.',
                'results' => [],
            ], 200);
        }

        $results = [];
        $allOk = true;

        foreach ($items as $item) {
            $result = $this->processItem($item);
            $results[] = $result;

            if (! $result['success']) {
                $allOk = false;
            }
        }

        return response()->json([
            'success'   => $allOk,
            'accepted'  => count(array_filter($results, fn ($r) => $r['success'])),
            'failed'    => count(array_filter($results, fn ($r) => ! $r['success'])),
            'results'   => $results,
        ], 200);
    }

    /**
     * Normalise the request into a list of update items.
     *
     * Accepts:
     *   {"items":[{...},{...}]}
     *   {"barcode":"...","mrp":100}
     *   [{...},{...}]
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(Request $request): array
    {
        if ($request->has('items')) {
            $items = $request->input('items');

            return is_array($items) ? array_values($items) : [];
        }

        $all = $request->all();

        // A bare JSON array body.
        if ($all !== [] && array_keys($all) === range(0, count($all) - 1)) {
            return array_values($all);
        }

        // A single update object.
        if (array_key_exists('barcode', $all)) {
            return [$all];
        }

        return [];
    }

    /**
     * Validate + stage a single update item.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function processItem(array $item): array
    {
        $validator = Validator::make($item, [
            'barcode' => ['required', 'string'],
            'mrp'     => ['nullable', 'numeric', 'min:0'],
            'price'   => ['nullable', 'numeric', 'min:0'],
            'stock'   => ['nullable', 'integer', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($item) {
            $hasChange = array_key_exists('mrp', $item)
                || array_key_exists('price', $item)
                || array_key_exists('stock', $item);

            if (! $hasChange) {
                $validator->errors()->add('mrp', 'At least one of mrp, price or stock must be supplied.');
            }
        });

        $barcode = (string) ($item['barcode'] ?? '');

        if ($validator->fails()) {
            $message = $validator->errors()->first();

            Log::error('Pearl XP update failed validation', [
                'barcode' => $barcode,
                'payload' => $item,
                'reason'  => $message,
            ]);

            return ['barcode' => $barcode, 'success' => false, 'error' => $message];
        }

        // Products only — variations are deliberately not matched.
        $product = Product::where('barcode', $barcode)->first();

        if (! $product) {
            $message = "No product found with barcode: {$barcode}";

            Log::error('Pearl XP update failed', [
                'barcode' => $barcode,
                'payload' => $item,
                'reason'  => $message,
            ]);

            return ['barcode' => $barcode, 'success' => false, 'error' => $message];
        }

        try {
            $update = PearlXpProductUpdate::create([
                'barcode'    => $barcode,
                'product_id' => $product->id,
                // Snapshot the live values so reviewers can compare.
                'old_mrp'    => $product->price,
                'old_price'  => $product->sale_price,
                'old_stock'  => $product->quantity,
                'new_mrp'    => array_key_exists('mrp', $item) ? $item['mrp'] : null,
                'new_price'  => array_key_exists('price', $item) ? $item['price'] : null,
                'new_stock'  => array_key_exists('stock', $item) ? $item['stock'] : null,
                'status'     => PearlXpProductUpdate::STATUS_PENDING,
                'payload'    => $item,
            ]);

            return [
                'barcode'   => $barcode,
                'success'   => true,
                'update_id' => $update->id,
                'status'    => PearlXpProductUpdate::STATUS_PENDING,
            ];

        } catch (Exception $e) {
            Log::error('Pearl XP update failed to store', [
                'barcode'   => $barcode,
                'product_id' => $product->id,
                'payload'   => $item,
                'reason'    => $e->getMessage(),
            ]);

            return ['barcode' => $barcode, 'success' => false, 'error' => 'Failed to store update.'];
        }
    }
}

<?php

namespace App\Http\Controllers;

use Exception;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\PearlXpProductUpdate;
use App\Repositories\Eloquents\ProductRepository;

class PearlXpProductUpdateController extends Controller
{
    public $repository;

    public function __construct(ProductRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Normalise every business-rule rejection to {"success": false, "message"}
     * with a real HTTP status.
     *
     * The rest of this API throws App\GraphQL\Exceptions\ExceptionHandler, which
     * extends a plain Exception — Laravel's HTTP renderer therefore ignores the
     * status code passed to it and always answers 500. These endpoints are new,
     * so they answer proper 4xx instead, which keeps routine admin actions out
     * of 5xx error monitoring.
     */
    private function error(string $message, int $status)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * A genuine failure (DB error, repository blow-up): log it server-side and
     * answer 500. Real server faults SHOULD be 5xx.
     */
    private function serverError(\Throwable $e, array $context = [])
    {
        Log::error('Pearl XP product update failed', $context + [
            'reason' => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        return $this->error($e->getMessage(), 500);
    }

    /**
     * Message for a staged row that does not exist.
     */
    private function missing($id)
    {
        return "No staged product update found with id {$id}.";
    }

    /**
     * @OA\Get(
     *      path="/pearl-xp/product-updates",
     *      operationId="listPearlXpProductUpdates",
     *      tags={"Pearl XP"},
     *      summary="List staged product updates awaiting review",
     *      description="Returns paginated staged updates. Filter with ?status=pending|approved|rejected|failed (defaults to pending) and ?barcode=",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="barcode", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="paginate", in="query", @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Successful operation"),
     * )
     */
    public function index(Request $request)
    {
        try {
            $query = PearlXpProductUpdate::with([
                'product:id,name,barcode,price,sale_price,quantity,stock_status,type',
                'reviewer:id,name,email',
            ]);

            $status = $request->input('status', PearlXpProductUpdate::STATUS_PENDING);

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            if ($request->filled('barcode')) {
                $query->where('barcode', $request->input('barcode'));
            }

            return $query->latest('id')->paginate($request->input('paginate', 15));

        } catch (Exception $e) {
            return $this->serverError($e, ['endpoint' => 'pearl-xp.index']);
        }
    }

    /**
     * @OA\Get(
     *      path="/pearl-xp/product-updates/{id}",
     *      operationId="showPearlXpProductUpdate",
     *      tags={"Pearl XP"},
     *      summary="Show one staged update alongside the live product values",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=404, description="Not found"),
     * )
     */
    public function show($id)
    {
        $update = PearlXpProductUpdate::with([
            'product:id,name,barcode,price,sale_price,quantity,stock_status,type,store_id',
            'reviewer:id,name,email',
        ])->find($id);

        if (! $update) {
            return $this->error($this->missing($id), 404);
        }

        return [
            'data'           => $update,
            // Live values right now, for side-by-side review against old_*.
            'current_product' => $update->product ? [
                'mrp'   => $update->product->price,
                'price' => $update->product->sale_price,
                'stock' => $update->product->quantity,
            ] : null,
        ];
    }

    /**
     * @OA\Put(
     *      path="/pearl-xp/product-updates/{id}",
     *      operationId="editPearlXpProductUpdate",
     *      tags={"Pearl XP"},
     *      summary="Edit a pending staged update before approving it",
     *      description="Only pending rows can be edited. Send any combination of mrp, price and stock.",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\RequestBody(required=true, @OA\JsonContent(
     *          @OA\Property(property="mrp", type="number"),
     *          @OA\Property(property="price", type="number"),
     *          @OA\Property(property="stock", type="integer")
     *      )),
     *      @OA\Response(response=200, description="Updated"),
     *      @OA\Response(response=422, description="Validation error"),
     * )
     */
    public function update(Request $request, $id)
    {
        $update = PearlXpProductUpdate::find($id);

        if (! $update) {
            return $this->error($this->missing($id), 404);
        }

        if (! $update->isPending()) {
            return $this->error(
                "Only pending updates can be edited. Current status: {$update->status}",
                422
            );
        }

        // Deliberately outside any catch: ValidationException must reach the
        // framework so it renders as the standard 422 {message, errors}.
        $validated = $request->validate([
            'mrp'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        if ($validated === []) {
            return $this->error('Provide at least one of mrp, price or stock.', 422);
        }

        try {
            if (array_key_exists('mrp', $validated)) {
                $update->new_mrp = $validated['mrp'];
            }
            if (array_key_exists('price', $validated)) {
                $update->new_price = $validated['price'];
            }
            if (array_key_exists('stock', $validated)) {
                $update->new_stock = $validated['stock'];
            }

            $update->save();
        } catch (Exception $e) {
            return $this->serverError($e, [
                'endpoint'  => 'pearl-xp.update',
                'update_id' => $update->id,
            ]);
        }

        return [
            'success' => true,
            'message' => 'Pending update revised.',
            'data'    => $update->fresh(),
        ];
    }

    /**
     * @OA\Post(
     *      path="/pearl-xp/product-updates/{id}/approve",
     *      operationId="approvePearlXpProductUpdate",
     *      tags={"Pearl XP"},
     *      summary="Approve a staged update and apply it to the real product",
     *      description="Moves the values from the staging table into products. The staging row is retained as an audit trail.",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Approved and applied"),
     *      @OA\Response(response=422, description="Not pending / product missing"),
     * )
     */
    public function approve($id)
    {
        $update = PearlXpProductUpdate::find($id);

        if (! $update) {
            return $this->error($this->missing($id), 404);
        }

        if (! $update->isPending()) {
            return $this->error(
                "Only pending updates can be approved. Current status: {$update->status}",
                422
            );
        }

        // Product may have been deleted since the update arrived.
        $product = Product::find($update->product_id);

        if (! $product) {
            $message = "Product {$update->product_id} (barcode: {$update->barcode}) no longer exists.";

            Log::error('Pearl XP approve failed', [
                'update_id' => $update->id,
                'reason'    => $message,
            ]);

            $update->update([
                'status'       => PearlXpProductUpdate::STATUS_FAILED,
                'error'        => $message,
                'reviewed_by'  => Helpers::getCurrentUserId(),
                'reviewed_at'  => now(),
            ]);

            return $this->error($message, 422);
        }

        try {
            $attributes = $update->toProductAttributes();

            // Reuse the repository so stock_status + is_approved stay consistent
            // with every other product update path.
            $this->repository->update($attributes, $product->id);

            $update->update([
                'status'      => PearlXpProductUpdate::STATUS_APPROVED,
                'error'       => null,
                'reviewed_by' => Helpers::getCurrentUserId(),
                'reviewed_at' => now(),
            ]);

            Log::info('Pearl XP update approved and applied', [
                'update_id' => $update->id,
                'product_id' => $product->id,
                'barcode'   => $update->barcode,
                'reviewed_by' => Helpers::getCurrentUserId(),
                'applied'    => collect($attributes)->except(['is_random_related_products'])->all(),
            ]);
        } catch (Exception $e) {
            return $this->serverError($e, [
                'endpoint'   => 'pearl-xp.approve',
                'update_id'  => $update->id,
                'product_id' => $product->id,
            ]);
        }

        return [
            'success' => true,
            'message' => 'Update applied to product.',
            'data'    => $update->fresh(['product:id,name,barcode,price,sale_price,quantity,stock_status']),
        ];
    }

    /**
     * @OA\Post(
     *      path="/pearl-xp/product-updates/{id}/reject",
     *      operationId="rejectPearlXpProductUpdate",
     *      tags={"Pearl XP"},
     *      summary="Reject a staged update without touching the product",
     *      security={{"sanctum":{}}},
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *      @OA\Response(response=200, description="Rejected"),
     * )
     */
    public function reject(Request $request, $id)
    {
        $update = PearlXpProductUpdate::find($id);

        if (! $update) {
            return $this->error($this->missing($id), 404);
        }

        if (! $update->isPending()) {
            return $this->error(
                "Only pending updates can be rejected. Current status: {$update->status}",
                422
            );
        }

        try {
            $update->update([
                'status'      => PearlXpProductUpdate::STATUS_REJECTED,
                'error'       => $request->input('reason'),
                'reviewed_by' => Helpers::getCurrentUserId(),
                'reviewed_at' => now(),
            ]);
        } catch (Exception $e) {
            return $this->serverError($e, [
                'endpoint'  => 'pearl-xp.reject',
                'update_id' => $update->id,
            ]);
        }

        Log::info('Pearl XP update rejected', [
            'update_id'   => $update->id,
            'product_id'  => $update->product_id,
            'barcode'     => $update->barcode,
            'reviewed_by' => Helpers::getCurrentUserId(),
            'reason'      => $request->input('reason'),
        ]);

        return [
            'success' => true,
            'message' => 'Update rejected.',
            'data'    => $update->fresh(),
        ];
    }
}

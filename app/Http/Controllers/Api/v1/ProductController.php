<?php

namespace App\Http\Controllers\Api\v1;
use App\Models\Product;
use App\Services\InventoryGrpcClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /**
     * List products with Redis caching.
     * Inventory stock is always retrieved live through gRPC.
     */
    public function index(Request $request, InventoryGrpcClient $inventory)
    {

        $queryParameters = $request->query();
        ksort($queryParameters);

        $cacheKey = 'index:' . sha1(http_build_query($queryParameters));

        $perPage = max(
            1,
            min((int) $request->query('per_page', 10), 100)
        );

        // Cache a plain array instead of a paginator object.
        $loadProducts = function () use ($request, $perPage) {
            return Product::query()
                ->when($request->filled('category'), function ($query) use ($request) {
                    $query->where('category', $request->category);
                })
                ->latest()
                ->paginate($perPage)
                ->withQueryString()
                ->toArray();
        };

        try {
            $products = Cache::tags(['products'])->remember(
                $cacheKey,
                now()->addSeconds(60),
                $loadProducts
            );
        } catch (Throwable $exception) {
            report($exception);

            // Redis failure must not prevent product listing.
            $products = $loadProducts();
        }

        try {
            // Inventory quantities are always fetched live through gRPC.
            $products['data'] = array_map(
                function (array $product) use ($inventory) {
                    $stock = $inventory->getStock((int) $product['id']);

                    if (! $stock['found']) {
                        throw new RuntimeException(
                            "Inventory record not found for product {$product['id']}."
                        );
                    }

                    $product['stock'] = $stock['available_quantity'];

                    return $product;
                },
                $products['data'] ?? []
            );
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Could not retrieve product inventory.',
                'error' => $exception->getMessage(),
            ], 503);
        }

        return $this->etaggedJson($request, $products);
    }

    public function store(Request $request, InventoryGrpcClient $inventory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'category' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')
                ->store('products', 'public');
        }

        unset($validated['image']);

        $initialStock = (int) $validated['stock'];

        $product = Product::create($validated);

        try {
            $result = $inventory->setStock($product->id, $initialStock);

            if (! $result['success']) {
                throw new RuntimeException($result['message']);
            }
        } catch (Throwable $exception) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }

            $product->delete();

            return response()->json([
                'message' => 'Product was not created because inventory setup failed.',
                'error' => $exception->getMessage(),
            ], 503);
        }

        $this->invalidateProductCache();

        $product->setAttribute('stock', $initialStock);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product,
        ], 201);
    }

    public function show(Product $product, InventoryGrpcClient $inventory)
    {
        try {
            $productData = Cache::tags(['products'])->remember(
                "show:{$product->id}",
                now()->addSeconds(60),
                fn () => $product->toArray()
            );

            $stock = $inventory->getStock($product->id);

            if (! $stock['found']) {
                return response()->json([
                    'message' => 'Inventory record not found for this product.',
                ], 503);
            }

            $productData['stock'] = $stock['available_quantity'];

            return response()->json($productData);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Could not retrieve product inventory.',
                'error' => $exception->getMessage(),
            ], 503);
        }
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'prohibited',
            'category' => 'sometimes|required|string|max:255',
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store('products', 'public');

            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }

            $validated['image_path'] = $newImagePath;
        }

        unset($validated['image']);

        $product->update($validated);

        $this->invalidateProductCache();

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->fresh(),
        ]);
    }

    public function destroy(Product $product)
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        $this->invalidateProductCache();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Invalidate all cached product listings and details.
     */
    private function invalidateProductCache(): void
    {
        try {
            Cache::tags(['products'])->flush();
        } catch (Throwable $exception) {
            // A cache failure must not turn a successful write into an API failure.
            report($exception);
        }
    }

    /**
     * Return JSON with an ETag for conditional HTTP requests.
     */
    private function etaggedJson(Request $request, array $data)
    {
        $response = response()->json($data);

        // Generate ETag from the complete response body.
        $etag = hash('sha256', $response->getContent());

        $response->setEtag($etag);

        // Allow browser storage, but require revalidation.
        $response->headers->set(
            'Cache-Control',
            'private, no-cache, must-revalidate'
        );

        // Convert the response to 304 when the ETag matches.
        $response->isNotModified($request);

        return $response;
    }
}

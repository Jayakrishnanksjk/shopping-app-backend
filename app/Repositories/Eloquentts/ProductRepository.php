if (isset($request->trending)) {
    if ($request->trending) {
        $product = $product->whereHas('trending_product');
    } else {
        $product = $product->whereDoesntHave('trending_product');
    }
}

if (isset($request->is_trending)) {
    if ($request->is_trending) {
        $product = $product->where('is_trending', 1);
    } else {
        $product = $product->where('is_trending', 0);
    }
}

if (isset($request->bestselling)) {
    if ($request->bestselling) {
        $product = $product->whereHas('bestselling_product');
    } else {
        $product = $product->whereDoesntHave('bestselling_product');
    }
}

if (isset($request->is_bestselling)) {
    if ($request->is_bestselling) {
        $product = $product->where('is_bestselling', 1);
    } else {
        $product = $product->where('is_bestselling', 0);
    }
}

if (isset($request->is_featured)) {
    ...
}

if ($request->out_of_stock) {
    ...
}

if ($request->filled('stock_status')) {
    ...
}

if ($request->ids) {
    ...
}

if (isset($request->min) && isset($request->max)) {
    ...
}

if ($request->category) {
    ...
}

if ($request->tag) {
    ...
}

if ($request->field && $request->sort) {
    ...
}

if (isset($request->status)) {
    ...
}

if ($request->sortBy) {
    ...
}

if ($request->store_id) {
    ...
}

if ($request->store_slug) {
    ...
}

if ($request->attribute) {
    ...
}

if ($request->price) {
    ...
}

if ($request->store_ids) {
    ...
}

if ($request->category_ids) {
    ...
}

if ($request->tag_ids) {
    ...
}
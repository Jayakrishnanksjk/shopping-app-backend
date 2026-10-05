<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class CreateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'short_description'     => ['nullable', 'string'],
            'cost'                  => ['nullable', 'numeric'],
            'store_id'              => ['nullable','exists:stores,id,deleted_at,NULL'],
            'type'                  => ['nullable','in:simple,classified'],
            'price'                 => ['required_if:type,==,simple', 'nullable', 'numeric'],
            'categories'            => ['nullable','array','exists:categories,id,deleted_at,NULL'],
            'tags'                  => ['nullable','array','exists:tags,id,deleted_at,NULL'],
            'stock_status'          => ['nullable', 'in:in_stock,out_of_stock'],
            'quantity'              => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'attributes_ids'        => ['required_if:type,==,classified','exists:attributes,id,deleted_at,NULL'],
            'product_meta_image_id' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'product_thumbnail_id'  => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'product_galleries_id.*' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'sku' => ['required_if:type,==,simple'],
            'is_external' => ['min:0', 'max:1'],
            'external_url' => ['required_if:is_external,==,1', 'nullable'],
            'external_button_text' => ['required_if:type,==,external', 'nullable'],
            'discount' => ['nullable','numeric','regex:/^([0-9]{1,2}){1}(\.[0-9]{1,2})?$/'],
            'tax_id' => ['nullable','exists:taxes,id,deleted_at,NULL'],
            'show_stock_quantity' => ['min:0', 'max:1'],
            'is_featured' => ['min:0', 'max:1'],
            'size_chart_image_id' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'secure_checkout' => ['min:0', 'max:1'],
            'safe_checkout' => ['min:0', 'max:1'],
            'social_share' => ['min:0', 'max:1'],
            'encourage_order' => ['min:0', 'max:1'],
            'encourage_view' => ['min:0', 'max:1'],
            'is_cod' => ['min:0', 'max:1'],
            'is_return' => ['min:0', 'max:1'],
            'is_free_shipping' => ['min:0', 'max:1'],
            'is_changeable' => ['min:0', 'max:1'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_expired_at' => ['nullable','date', 'after:sale_starts_at'],
            'status' => ['nullable','min:0','max:1'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'barcode'  => ['nullable', 'string', 'max:100'],
            'cross_sell_products' => ['nullable','exists:products,id,deleted_at,NULL'],
            'related_products' => ['nullable','exists:products,id,deleted_at,NULL'],
            'visible_time' => ['nullable','date'],
            'default_variation_id' => ['nullable', 'exists:variations,id,deleted_at,NULL'],
            'default_variation_index' => ['nullable', 'integer'],
            'variations.*.name' => ['required_if:type,==,classified', 'string'],
            'variations.*.price' => ['required_if:type,==,classified', 'numeric'],
            'variations.*.cost' => ['nullable', 'numeric'],
            'variations.*.sale_price' => ['nullable', 'numeric'],
            'variations.*.stock_status' => ['required_if:type,==,classified', 'in:in_stock,out_of_stock,coming_soon'],
            'variations.*.quantity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'variations.*.attribute_values' => ['required_if:type,==,classified','exists:attribute_values,id,deleted_at,NULL'],
            'variations.*.discount' => ['nullable','numeric', 'regex:/^([0-9]{1,2}){1}(\.[0-9]{1,2})?$/'],
            'variations.*.sku' => ['required_if:type,==,classified', 'string'],
            'variations.*.status' => ['required_if:type,==,classified','min:0','max:1'],
            'variations.*.variation_image_id' => ['nullable','exists:attachments,id,deleted_at,NULL']
        ];
    }

    public function messages()
    {
        return [
            'discount.regex' => 'Enter discount between 0 to 99.99',
            'type.in' => 'Product type can be either simple or classified',
            'stock_status.in' => 'Stock status can be either in_stock or out_of_stock',
            'video_provider.in' => 'Video Provider can in youtube or vimeo or daily_motion',
            'variations.*.discount.regex' => 'Enter Variations discount between 0 to 99.99',
            'variations.*.stock_status.in' => 'Variations Stock status can be either in_stock or out_of_stock or coming_soon',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $sku = $this->input('sku');
            if ($sku) {
                $existingProduct = \App\Models\Product::where('sku', $sku)->first();
                if ($existingProduct) {
                    $validator->errors()->add('sku', "The SKU '{$sku}' is already taken by product '{$existingProduct->name}' (active).");
                }
                
                $existingVar = \App\Models\Variation::where('sku', $sku)->whereHas('product')->first();
                if ($existingVar) {
                    $parentProduct = $existingVar->product()->first();
                    $pName = $parentProduct ? $parentProduct->name : 'Unknown Product';
                    $validator->errors()->add('sku', "The SKU '{$sku}' is already taken by variation in product '{$pName}' (active).");
                }
            }

            $variations = $this->input('variations', []);
            if (is_array($variations)) {
                foreach ($variations as $index => $variation) {
                    if (!empty($variation['sku'])) {
                        $vSku = $variation['sku'];
                        
                        $existingProduct = \App\Models\Product::where('sku', $vSku)->first();
                        if ($existingProduct) {
                            $validator->errors()->add("variations.{$index}.sku", "The variation SKU '{$vSku}' is already taken by product '{$existingProduct->name}' (active).");
                            continue;
                        }

                        $existingVar = \App\Models\Variation::where('sku', $vSku)
                            ->whereHas('product')
                            ->when(!empty($variation['id']), function ($q) use ($variation) {
                                return $q->where('id', '!=', $variation['id']);
                            })
                            ->first();
                        if ($existingVar) {
                            $parentProduct = $existingVar->product()->first();
                            $pName = $parentProduct ? $parentProduct->name : 'Unknown Product';
                            $validator->errors()->add("variations.{$index}.sku", "The variation SKU '{$vSku}' is already taken by variation in product '{$pName}' (active).");
                        }
                    }
                }
            }
        });
    }
}

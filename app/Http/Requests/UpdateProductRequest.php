<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class UpdateProductRequest extends FormRequest
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
     * @return array
     */
    public function rules()
    {
        $productParam = $this->route('product');
        $id = ($productParam instanceof \Illuminate\Database\Eloquent\Model) ? $productParam->id : $productParam;
        if (!$id) {
            $id = $this->id;
        }
        if (isset($this->related_products)) {
            foreach ($this->related_products as $related_product) {
                if ($id == $related_product) {
                    throw new ExceptionHandler("Can't insert same Product in Related Products", 400);
                }
            }
        }

        if (isset($this->cross_sell_products)) {
            foreach ($this->cross_sell_products as $cross_sell_product) {
                if ($id == $cross_sell_product) {
                    throw new ExceptionHandler("Can't insert same Product in Cross Sell Products", 400);
                }
            }
        }

        $rules = [
            'name'                  => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'short_description'     => ['nullable', 'string'],
            'cost'                  => ['nullable', 'numeric'],
            'type'                  => ['nullable','in:simple,classified'],
            'stock_status'          => ['nullable', 'in:in_stock,out_of_stock'],
            'sku'                   => ['nullable'],
            'quantity'              => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'price'                 => ['required_if:type,==,simple', 'nullable', 'numeric'],
            'categories'            => ['nullable', 'exists:categories,id,deleted_at,NULL'],
            'tags'                  => ['nullable', 'exists:tags,id,deleted_at,NULL'],
            'tax_id'                => ['nullable', 'exists:taxes,id,deleted_at,NULL'],
            'status'                => ['nullable', 'min:0', 'max:1'],
            'hsn_code'              => ['nullable', 'string', 'max:20'],
            'barcode'               => ['nullable', 'string', 'max:100'],
            'is_featured' => ['min:0', 'max:1'],
            'is_cod' => ['min:0', 'max:1'],
            'is_return' => ['min:0', 'max:1'],
            'is_free_shipping' => ['min:0', 'max:1'],
            'is_changeable' => ['min:0', 'max:1'],
            'discount' => ['nullable','numeric','between:0,100'],
            'is_external' => ['min:0', 'max:1'],
            'external_url' => ['required_if:is_external,==,1', 'nullable'],
            'external_button_text' => ['required_if:type,==,external', 'nullable'],
            'show_stock_quantity' => ['min:0', 'max:1'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_expired_at' => ['nullable','date', 'after:sale_starts_at'],
            'product_meta_image_id' =>['nullable','exists:attachments,id,deleted_at,NULL'],
            'product_thumbnail_id' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'product_galleries_id.*' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'attributes_ids' => ['nullable','required_if:type,==,classified','exists:attributes,id,deleted_at,NULL'],
            'is_random_related_products' => ['min:0', 'max:1'],
            'related_products' => ['nullable','exists:products,id,deleted_at,NULL'],
            'cross_sell_products' => ['nullable', 'exists:products,id,deleted_at,NULL'],
            'visible_time' => ['nullable','date'],
            'default_variation_id' => ['nullable', 'exists:variations,id,deleted_at,NULL'],
            'default_variation_index' => ['nullable', 'integer'],
            'variations.*.id' => ['nullable','exists:variations,id,deleted_at,NULL'],
            'variations.*.name' => ['nullable','required_if:type,==,classified','string'],
            'variations.*.price' => ['nullable','required_if:type,==,classified','numeric'],
            'variations.*.cost' => ['nullable','numeric'],
            'variations.*.sale_price' => ['nullable','numeric'],
            'variations.*.stock_status' => ['nullable','required_if:type,==,classified', 'in:in_stock,out_of_stock,coming_soon'],
            'variations.*.quantity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'variations.*.discount' => ['nullable', 'numeric', 'between:0,100'],
            'variations.*.attribute_values' => ['nullable','required_if:type,==,classified','exists:attribute_values,id'],
            'variations.*.variation_image_id' => ['nullable','exists:attachments,id,deleted_at,NULL'],
            'variations.*.status' => ['required_if:type,==,classified','min:0','max:1'],
        ];

        if (!empty($this->input('variations'))) {
            return $this->withUniqueVariationSkuRule($rules, $this->input('variations', []));
        }

        return $rules;
    }

    public function withUniqueVariationSkuRule($rules, $variations)
    {
        foreach ($variations as $key => $variation) {
            $rules['variations.'.$key.'.sku'] = ['nullable','required_if:type,==,classified', 'string'];
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'discount.between' => 'Discount must be between 0 and 100',
            'video_provider.in' => 'Video Provider can in youtube or vimeo or daily_motion',
            'type.in' => 'Product type can be either simple or classified or external',
            'variations.*.stock_status.in' => 'Variations Stock status can be either in_stock or out_of_stock or coming_soon',
            'stock_status.in' => 'Stock status can be either in_stock or out_of_stock',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $productParam = $this->route('product');
            $id = ($productParam instanceof \Illuminate\Database\Eloquent\Model) ? $productParam->id : $productParam;
            if (!$id) {
                $id = $this->id;
            }
            $sku = $this->input('sku');
            if ($sku) {
                $existingProduct = \App\Models\Product::where('sku', $sku)->first();
                if ($existingProduct && $existingProduct->id != $id) {
                    $validator->errors()->add('sku', "The SKU '{$sku}' is already taken by product '{$existingProduct->name}' (active).");
                }
                
                $existingVar = \App\Models\Variation::where('sku', $sku)
                    ->whereHas('product')
                    ->where('product_id', '!=', $id)
                    ->first();
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
                        if ($existingProduct && $existingProduct->id != $id) {
                            $validator->errors()->add("variations.{$index}.sku", "The variation SKU '{$vSku}' is already taken by product '{$existingProduct->name}' (active).");
                            continue;
                        }

                        $existingVar = \App\Models\Variation::where('sku', $vSku)
                            ->whereHas('product')
                            ->when(!empty($variation['id']), function ($q) use ($variation) {
                                return $q->where('id', '!=', $variation['id']);
                            })
                            ->when(empty($variation['id']), function ($q) use ($id) {
                                return $q->where('product_id', '!=', $id);
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

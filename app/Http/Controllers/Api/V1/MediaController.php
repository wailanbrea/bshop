<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\GalleryService;
use App\Domain\Media\MediaUploadService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductImageResource;
use App\Models\MediaUploadSession;
use App\Models\Product;
use App\Models\StorageQuota;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function gallery(Request $request)
    {
        $product = Product::with('images.asset.renditions')->findOrFail($request->route('product'));
        abort_if($product->status !== 'active' && ! $request->user()->permits('catalog.manage'), 404);
        $result = ['images' => ProductImageResource::collection($product->images)->resolve($request), 'version' => $product->lock_version];
        if ($request->user()->permits('catalog.manage')) {
            $quota = StorageQuota::findOrFail(1);
            $result['quota'] = ['max_bytes' => $quota->max_bytes, 'ready_bytes' => $quota->bytes_ready, 'pending_bytes' => $quota->bytes_pending, 'retained_bytes' => $quota->bytes_retained, 'max_images_per_product' => $quota->max_images_per_product];
            $result['pending_uploads'] = MediaUploadSession::where('product_id', $product->id)->whereIn('status', ['issued', 'uploaded'])->count();
        }

        return response()->json(['data' => $result]);
    }

    public function upload(Request $request, MediaUploadService $service)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:10240'], 'variant_id' => ['nullable', 'integer', 'min:1'], 'alt_text' => ['nullable', 'string', 'max:255']]);
        $result = $service->upload((int) $request->route('product'), $request->file('file'), $data, $request->user());

        return response()->json(['data' => ['request_key' => $result['session']->request_key, 'status' => $result['session']->status, 'replay' => $result['replay']]], $result['replay'] ? 200 : 201);
    }

    public function status(Request $request)
    {
        $session = MediaUploadSession::with('asset')->where('product_id', $request->route('product'))->where('request_key', $request->route('key'))->where('created_by_user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => ['request_key' => $session->request_key, 'status' => $session->status, 'retryable' => $session->status === 'failed' && $session->reserved_bytes === 0 && ! $session->asset->cleanup_pending, 'error_code' => $session->asset->error_code]]);
    }

    public function update(Request $request, GalleryService $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'primary_id' => ['present', 'nullable', 'integer'], 'images' => ['present', 'array', 'list', 'max:100'], 'images.*.id' => ['required', 'integer', 'distinct'], 'images.*.variant_id' => ['nullable', 'integer', 'min:1'], 'images.*.alt_text' => ['nullable', 'string', 'max:255']]);
        $data['version'] = (int) $data['version'];
        $data['primary_id'] = isset($data['primary_id']) ? (int) $data['primary_id'] : null;
        foreach ($data['images'] as &$image) {
            $image['id'] = (int) $image['id'];
        }
        unset($image);
        $service->update((int) $request->route('product'), $data, $request->user());

        return $this->gallery($request);
    }

    public function remove(Request $request, GalleryService $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $service->remove((int) $request->route('product'), (int) $request->route('image'), $data['version'], $request->user());

        return $this->gallery($request);
    }
}

<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Requests\Admin\StoreAssetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    use ResolvesOrganization;

    /**
     * Картинка для письма: кладётся на публичный диск, в письмо идёт абсолютная ссылка.
     */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('sender/'.$this->organization($request)->id, 'public');
        $size = @getimagesize($file->getRealPath()) ?: [null, null];

        return response()->json([
            'data' => [
                'url' => Storage::disk('public')->url($path),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'width' => $size[0],
                'height' => $size[1],
            ],
        ], 201);
    }
}

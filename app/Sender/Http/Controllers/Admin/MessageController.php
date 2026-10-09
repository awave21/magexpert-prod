<?php

namespace App\Sender\Http\Controllers\Admin;

use App\Sender\Http\Resources\MessageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class MessageController extends Controller
{
    use ResolvesOrganization;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->organization($request)->messages()->with('template')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('q')) {
            $query->where('to_email', 'like', '%'.$request->string('q')->lower().'%');
        }

        return MessageResource::collection($query->paginate(25)->withQueryString());
    }

    public function show(Request $request, string $uuid): MessageResource
    {
        return new MessageResource($this->organization($request)->messages()->with('template')->where('uuid', $uuid)->firstOrFail());
    }
}

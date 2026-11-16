<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pixelforge only takes reference images as public http(s) URLs (it bills 765 tokens each
 * and never downloads them). So a file from the user's device is stored here first and its
 * public URL is what we send.
 */
class ReferenceImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
        ]);

        $path = $request->file('image')->store('references/'.$request->user()->id, 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)], 201);
    }
}

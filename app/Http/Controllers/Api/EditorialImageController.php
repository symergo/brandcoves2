<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Images\EditorialImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/editorial/images`: put a picture on our own server for an article.
 *
 * Send a `file` (multipart) or a `url` (https). The answer is our address for
 * the picture and the `[[image:…]]` line to paste into an article body, with the
 * `alt` sent as its description. Why the copy is kept here rather than linked:
 * App\Services\Images\EditorialImages.
 *
 * A write, not a publish: storing a picture shows it to nobody until an article
 * names it, and the article's own approval is what reaches a reader.
 */
class EditorialImageController extends Controller
{
    public function store(Request $request, EditorialImages $images): JsonResponse
    {
        $request->validate([
            'file' => ['nullable', 'required_without:url', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/gif'],
            'url' => ['nullable', 'required_without:file', 'string', 'max:2048', 'url:https'],
            'alt' => ['nullable', 'string', 'max:200'],
        ]);

        $result = $request->hasFile('file')
            ? $images->fromBytes((string) file_get_contents((string) $request->file('file')->getRealPath()))
            : $images->fromUrl((string) $request->input('url'));

        if (is_string($result)) {
            return response()->json(['message' => $result, 'errors' => ['image' => [$result]]], 422);
        }

        $url = rtrim((string) config('app.url'), '/').$result['path'];
        // `|` and brackets would end the token early; the description is plain words anyway.
        $alt = trim(str_replace(['|', '[', ']'], ' ', (string) $request->input('alt', '')));

        return response()->json([
            'data' => [
                'url' => $url,
                'path' => $result['path'],
                'width' => $result['width'],
                'height' => $result['height'],
                // The description is the alt text and is required by the article
                // markup; without one, a placeholder that is obviously not done.
                'token' => '[[image:'.$url.'|'.($alt !== '' ? $alt : 'DESCRIBE THE PICTURE').']]',
            ],
        ], 201);
    }
}

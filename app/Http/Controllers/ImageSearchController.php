<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Product;

class ImageSearchController extends Controller
{
    // ── PUBLIC — Find listings that resemble an uploaded photo ────
    // Sends the photo to Hugging Face's free image-classification
    // Inference API, then fuzzy-matches the returned labels against
    // approved listing titles/categories.
    public function search(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:5120', // 5MB
        ]);

        $token = config('services.huggingface.token');
        if (!$token) {
            return response()->json([
                'message' => 'No related searches yet.',
            ], 503);
        }

        $model = config('services.huggingface.vision_model');
        $file  = $request->file('image');

        try {
            $response = Http::withToken($token)
                ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
                ->timeout(20)
                ->post("https://router.huggingface.co/hf-inference/models/{$model}");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'message' => 'Couldn’t reach the image search service. Please try again.',
            ], 502);
        }

        // Hugging Face's free tier "cold-starts" models on first use and
        // returns 503 with an estimated_time while it loads — surface that
        // as a retry-able 503 rather than a hard failure.
        if ($response->status() === 503) {
            return response()->json([
                'message' => 'The image model is warming up — please try again in a few seconds.',
            ], 503);
        }

        // An expired/invalid token is a server misconfiguration, not
        // something the user can fix by retrying — report it the same way
        // as "not configured" so it's obvious this needs an admin's attention.
        if (in_array($response->status(), [401, 403], true)) {
            return response()->json([
                'message' => 'Photo search isn’t configured on the server yet.',
            ], 503);
        }

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Couldn’t analyze that photo. Please try again.',
            ], 502);
        }

        $predictions = $response->json();
        if (!is_array($predictions)) {
            return response()->json([
                'message' => 'Couldn’t analyze that photo. Please try again.',
            ], 502);
        }

        // Each prediction label looks like "desktop computer, desktop" —
        // split on commas and keep short, meaningful keywords.
        $labels = collect($predictions)
            ->sortByDesc('score')
            ->take(5)
            ->flatMap(fn ($p) => explode(',', $p['label'] ?? ''))
            ->map(fn ($l) => trim(strtolower($l)))
            ->filter(fn ($l) => strlen($l) >= 3)
            ->unique()
            ->values();

        $products = collect();
        if ($labels->isNotEmpty()) {
            $products = Product::with([
                'seller:id,name,location',
                'category:category_id,name',
                'images',
            ])
                ->where('status', 'approved')
                ->where(function ($q) use ($labels) {
                    foreach ($labels as $label) {
                        $q->orWhere('title', 'like', "%{$label}%")
                          ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$label}%"));
                    }
                })
                ->latest()
                ->take(12)
                ->get();
        }

        return response()->json([
            'labels' => $labels->take(4)->values(),
            'products' => $products,
        ]);
    }
}

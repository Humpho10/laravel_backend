<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SellerRating;
use App\Models\Product;
use App\Models\User;
use App\Helpers\AuditLogger;
use Illuminate\Support\Facades\Auth;

class SellerRatingController extends Controller
{
    // ── BUYER — Submit / update a star rating for a seller ─────
    public function store(Request $request, $sellerId)
    {
        $buyerId = Auth::id();

        // Business rule: cannot rate yourself
        if ((int) $sellerId === (int) $buyerId) {
            return response()->json(['error' => 'You cannot rate yourself.'], 400);
        }

        // Target must actually be a seller (has at least one listing) — buyers
        // aren't required to have messaged them first, since many deals close
        // over a phone call instead of the in-app messaging system.
        if (!$this->isSeller($sellerId)) {
            return response()->json(['error' => 'Seller not found.'], 404);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);

        $rating = SellerRating::updateOrCreate(
            ['seller_id' => $sellerId, 'buyer_id' => $buyerId],
            ['rating' => $validated['rating']]
        );

        AuditLogger::log('seller_ratings', $rating->rating_id, 'rated', null, $rating->toArray());

        return response()->json([
            'message'   => 'Thank you for rating this seller.',
            'my_rating' => $rating->rating,
            'summary'   => self::summaryFor($sellerId),
        ], 200);
    }

    // ── BUYER — Own rating status for a seller ─────────────────
    public function myStatus($sellerId)
    {
        $buyerId = Auth::id();

        $canRate = (int) $sellerId !== (int) $buyerId
            && $this->isSeller($sellerId);

        $myRating = SellerRating::where('seller_id', $sellerId)
            ->where('buyer_id', $buyerId)
            ->value('rating');

        return response()->json([
            'can_rate'  => $canRate,
            'my_rating' => $myRating, // null if not yet rated
            'summary'   => self::summaryFor($sellerId),
        ], 200);
    }

    // Does this user have at least one listing (i.e. are they actually a seller)?
    private function isSeller($sellerId): bool
    {
        return Product::where('seller_id', $sellerId)->exists();
    }

    // ── PUBLIC — Top 5 most-rated sellers, for the homepage ────
    public function topRated()
    {
        $limit   = 5;
        $minimum = 2; // ratings needed to qualify for the "confident" ranking

        $baseQuery = fn () => SellerRating::selectRaw('seller_id, AVG(rating) as average, COUNT(*) as count')
            ->groupBy('seller_id')
            ->orderByDesc('average')
            ->orderByDesc('count');

        $top = $baseQuery()->havingRaw('COUNT(*) >= ?', [$minimum])->limit($limit)->get();

        // Not enough well-established sellers yet — fill remaining spots with
        // whoever else has ratings, same ordering.
        if ($top->count() < $limit) {
            $excludeIds = $top->pluck('seller_id');
            $fallback = $baseQuery()
                ->when($excludeIds->isNotEmpty(), fn ($q) => $q->whereNotIn('seller_id', $excludeIds))
                ->limit($limit - $top->count())
                ->get();
            $top = $top->concat($fallback);
        }

        $sellers = User::whereIn('id', $top->pluck('seller_id'))
            ->get(['id', 'name', 'location', 'avatar'])
            ->keyBy('id');

        $result = $top->map(function ($row) use ($sellers) {
            $seller = $sellers->get($row->seller_id);
            return [
                'id'             => $row->seller_id,
                'name'           => $seller?->name,
                'location'       => $seller?->location,
                'avatar'         => $seller?->avatar,
                'rating_average' => round((float) $row->average, 1),
                'rating_count'   => (int) $row->count,
            ];
        })->values();

        return response()->json(['sellers' => $result], 200);
    }

    // Aggregate rating for a single seller
    public static function summaryFor($sellerId): array
    {
        $agg = SellerRating::where('seller_id', $sellerId)
            ->selectRaw('AVG(rating) as average, COUNT(*) as count')
            ->first();

        $count = $agg ? (int) $agg->count : 0;

        return [
            'average' => $count > 0 ? round((float) $agg->average, 1) : 0,
            'count'   => $count,
        ];
    }

    // Aggregate ratings for many sellers at once (avoids N+1)
    public static function summariesFor($sellerIds): array
    {
        $ids = collect($sellerIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return SellerRating::whereIn('seller_id', $ids)
            ->selectRaw('seller_id, AVG(rating) as average, COUNT(*) as count')
            ->groupBy('seller_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->seller_id => [
                    'average' => round((float) $row->average, 1),
                    'count'   => (int) $row->count,
                ],
            ])
            ->toArray();
    }
}

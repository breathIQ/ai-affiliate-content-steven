<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;
use App\Models\{Chapter,AffiliateClick,Post,Media};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;  
use Illuminate\Support\Facades\Storage;

class UserDashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {
        try {
            $user = Auth::user();
            $currentMonthStart = \Carbon\Carbon::now()->startOfMonth();
            $currentMonthEnd = \Carbon\Carbon::now()->endOfMonth();

            // Stats Cards
            $postsGenerated = $user->posts()->count();
            $draftPosts = $user->posts()->where('status', 'draft')->count(); // Assuming status column
            $scheduledPosts = $user->posts()->where('status', 'scheduled')->count();
            $affiliateClicks = $user->affiliateClicks()->count();

            // This Month Stats
            $monthlyClicks = $user->affiliateClicks()->whereBetween('affiliate_clicks.created_at', [$currentMonthStart, $currentMonthEnd])->count();
            $postsPublished = $user->posts()->where('status', 'published')->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])->count();
            
            // Calc avg clicks per post for manual calc or general avg? 
            // Design says "Avg Clicks / Post" for "This Month" probably means total clicks this month / posts published this month or all time?
            // Let's assume average clicks per published post ALL TIME for robust data, or this month. 
            // Design context ("This Month in Stats") implies monthly.
            $avgClicksPerPost = $postsPublished > 0 ? round($monthlyClicks / $postsPublished, 1) : 0;

            // Chart Data (Affiliate Clicks visual) - Let's give last 30 days or daily breakdown for current month
            // Simple daily count for current month
            $clicksGraph = AffiliateClick::join('posts', 'posts.id', '=', 'affiliate_clicks.post_id')
                ->where('posts.user_id', $user->id)
                ->whereBetween('affiliate_clicks.created_at', [$currentMonthStart, $currentMonthEnd])
                ->selectRaw('DATE(affiliate_clicks.created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->get();

            // Recent Posts
            $recentPosts = $user->posts()->with(['chapter', 'media'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($post) {
                    $media = $post->media->sortBy('media_order')->first();
                    $mediaUrl = $media ? asset(Storage::url($media->media_path)) : null;
                    
                    // Parse hashtags count
                    $hashtagsCount = 0;
                    if ($post->hastag) {
                        // assuming comma or space separated string based on typical text column usage
                        // If it's json stored as text, try decode first, else split
                        $decoded = json_decode($post->hastag, true);
                        if (is_array($decoded)) {
                            $hashtagsCount = count($decoded);
                        } else {
                            $hashtagsCount = count(array_filter(explode(',', $post->hastag))); // simple count
                        }
                    }

                    return [
                        'id' => $post->id,
                        'media' => $mediaUrl, 
                        'post_content' => Str::limit($post->caption ?? $post->script, 50), // Use caption or script as content
                        'chapter_name' => $post->chapter->name ?? $post->chapter->chapter_title ?? 'N/A',
                        'hashtags_count' => $hashtagsCount,
                        'ai_generated' => true, 
                        'ai_model' => $post->ai_model,
                        'status' => $post->status,
                        'created_at' => $post->created_at->format('M d, Y')
                    ];
                });

            $data = [
                'stats' => [
                    'generated' => $postsGenerated,
                    'drafts' => $draftPosts,
                    'scheduled' => $scheduledPosts,
                    'total_clicks' => $affiliateClicks,
                ],
                'month_stats' => [
                    'affiliate_clicks' => $monthlyClicks,
                    'posts_published' => $postsPublished,
                    'avg_clicks_per_post' => $avgClicksPerPost,
                    'clicks_graph' => $clicksGraph
                ],
                'recent_posts' => $recentPosts,
                'social_accounts_status' => [
                     'instagram' => $user->socialAccounts()->where('provider', 'instagram')->exists(),
                     'tiktok' => $user->socialAccounts()->where('provider', 'tiktok')->exists()
                ]
            ];

            return $this->sendResponse($data, 'Dashboard data fetched successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch dashboard data', ['error' => $e->getMessage()], 500);
        }
    }

    public function getChapter(Request $request)
    {
        try{

            $chapters = Chapter::select('id','book_id','chapter','chapter_title')->get();
            if(!$chapters){
                return $this->sendError('Chapters data not found.', [], 500);
            }

            return $this->sendResponse($chapters, 'chapters get successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError('chapter get failed.', ['error' => $e->getMessage()], 500);
        }
    }
}

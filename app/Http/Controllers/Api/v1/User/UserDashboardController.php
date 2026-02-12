<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;
use App\Models\{Chapter,AffiliateClick,Post,Media,File,BookChapter};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;  
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class UserDashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {
        try {
            $user = Auth::user();
            $currentMonthStart = Carbon::now()->startOfMonth();
            $currentMonthEnd = Carbon::now()->endOfMonth();

            $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
            $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

            // Stats Cards
            $postsGenerated = $user->posts()->count();
            //$draftPosts = $user->posts()->where('status', 'draft')->count(); // Assuming status column
            //$scheduledPosts = $user->posts()->where('status', 'scheduled')->count();
            $affiliateClicks = $user->posts()->sum('total_clicks');

            // This Month Stats
            $monthlyClicks = $user->posts()
                ->join('affiliate_clicks', 'posts.id', '=', 'affiliate_clicks.post_id')
                ->whereBetween('affiliate_clicks.created_at', [$currentMonthStart, $currentMonthEnd])
                ->count();

            // Get Last Month Clicks
            $lastMonthClicks = $user->posts()
                ->join('affiliate_clicks', 'posts.id', '=', 'affiliate_clicks.post_id')
                ->whereBetween('affiliate_clicks.created_at', [$lastMonthStart, $lastMonthEnd])
                ->count();

            // 3. Calculate Growth Rate
            $growthRate = 0;
            if ($lastMonthClicks > 0) {
                $growthRate = (($monthlyClicks - $lastMonthClicks) / $lastMonthClicks) * 100;
            } elseif ($monthlyClicks > 0) {
                // If there were 0 clicks last month but some this month, growth is 100%
                $growthRate = 100;
            }

            // Round to nearest integer or 1 decimal point
            $growthRate = round($growthRate, 2);

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
                    $media_type = $media ? $media->media_type : null;
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
                        'media_type' => $media_type,
                        'post_content' => Str::limit($post->caption ?? $post->script, 50), // Use caption or script as content
                        'chapter_name' => $post->chapter->chapter ?? $post->chapter->chapter_title ?? 'N/A',
                        'hashtags_count' => $hashtagsCount,
                        'ai_generated' => true, 
                        'ai_model' => $post->ai_model,
                        'status' => $post->status,
                        'created_at' => $post->created_at->format('M d, Y')
                    ];
                });

            //Book URL
            $file = File::first();
            $book_url = $file ? asset(Storage::url($file->file_path)) : null;

            //Social account
            $instagram = $user->socialAccounts->where('provider', 'instagram')->first();
            $tiktok = $user->socialAccounts->where('provider', 'tiktok')->first();

            $data = [
                'stats' => [
                    'generated' =>  $postsGenerated,
                    //'drafts' => $draftPosts,
                    //'scheduled' => $scheduledPosts,
                    'total_clicks' => $affiliateClicks,
                ],
                'month_stats' => [
                    'affiliate_clicks' => $monthlyClicks,
                    'growth_rate' => $growthRate,
                    'posts_published' => $postsPublished,
                    'avg_clicks_per_post' => $avgClicksPerPost,
                    'clicks_graph' => $clicksGraph
                ],
                'book_url' => $book_url,
                'recent_posts' => $recentPosts,

                'social_accounts_status' => [
                    'instagram' => [
                        'connected' => (bool)$instagram,
                        'username' => $instagram ? $instagram->username : null,
                    ],
                    'tiktok' => [
                        'connected' => (bool)$tiktok,
                        'username' => $tiktok ? $tiktok->username : null,
                    ]
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

            $chapters = Chapter::select('chapters.id','chapters.book_id','chapters.chapter','book_chapters.chapter_title')
                ->join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
                ->get();
            if(!$chapters){
                return $this->sendError('Chapters data not found.', [], 500);
            }

            return $this->sendResponse($chapters, 'chapters get successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError('chapter get failed.', ['error' => $e->getMessage()], 500);
        }
    }
}

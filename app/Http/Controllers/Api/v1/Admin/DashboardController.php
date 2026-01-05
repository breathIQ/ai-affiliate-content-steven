<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\{User,Post,Chapter,AffiliateClick};
use Carbon\Carbon;

class DashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {   
        $auth_user = Auth::user();
        $currentMonthStart = Carbon::now()->startOfMonth();
$currentMonthEnd   = Carbon::now()->endOfMonth();

$previousMonthStart = Carbon::now()->subMonth()->startOfMonth();
$previousMonthEnd   = Carbon::now()->subMonth()->endOfMonth();
        return $this->sendResponse([
            'total_users' => $this->totalUsers($currentMonthEnd,$previousMonthEnd),
            'publishing_stats' => $this->publishingStats($currentMonthStart, $currentMonthEnd),
            'affiliate_clicks' => $this->affiliateClicks($currentMonthStart, $currentMonthEnd),
            'top_affiliates' => $this->topAffiliates($currentMonthStart, $currentMonthEnd),
            'most_used_chapters' => $this->mostUsedChapters($currentMonthStart, $currentMonthEnd),

        ],'dashboard data get successfully', 200);
    }

    protected function totalUsers($currentMonthEnd,$previousMonthEnd)
    {
        $currentTotal = User::where('created_at', '<=', $currentMonthEnd)->count();
        $previousTotal = User::where('created_at', '<=', $previousMonthEnd)->count();

        $growthRate = $previousTotal > 0
            ? round((($currentTotal - $previousTotal) / $previousTotal) * 100)
            : 0;
        return [
            'count' => User::whereHas('role', function($q) {
                            $q->where('role', 'User');
                        })->count(),
            'growth_percent' => $growthRate
        ];
    }

    protected function publishingStats($start, $end)
    {
        $generated = Post::whereBetween('created_at', [$start, $end])->count();
        $published = Post::whereBetween('created_at', [$start, $end])
            ->where('status', 'published')
            ->count();

        return [
            'published' => $published,
            'generated' => $generated,
            'success_rate' => $generated > 0
                ? round(($published / $generated) * 100)
                : 0
        ];
    }

    protected function affiliateClicks($start, $end)
    {   
        $affiliateClicks = AffiliateClick::whereBetween('created_at', [$start, $end])->count();
        return $affiliateClicks;
    }

    protected function topAffiliates($start, $end)
    {
        //top published
        $topPublished = User::where('role_id', 2) // exclude admin
        ->withCount([
            'posts as total' => function ($q) use ($start, $end) {
                $q->where('status', 'published')
                ->whereBetween('created_at', [$start, $end]);
            }
        ])
        ->having('total', '>', 0) // remove zero results
        ->orderByDesc('total')
        ->limit(5)
        ->get(['id', 'name', 'email', 'avatar']);

        //top generated 
        $topGenerated = User::where('role_id', 2)
        ->withCount([
        'posts as total' => function ($q) use ($start, $end) {
            $q->where('status', 'generated')
            ->whereBetween('created_at', [$start, $end]);
        }
        ])
        ->having('total', '>', 0)
        ->orderByDesc('total')
        ->limit(5)
        ->get(['id', 'name', 'email', 'avatar']);

        
        //top clicks
        $topClicks = User::where('role_id', 2)
        ->withCount([
            'affiliateClicks as total' => function ($q) use ($start, $end) {
                $q->whereBetween('affiliate_clicks.created_at', [$start, $end]);
            }
        ])
        ->having('total', '>', 0)
        ->orderByDesc('total')
        ->limit(5)
        ->get(['id', 'name', 'email', 'avatar']);



        return [
            'published' => $topPublished,
            'generated' => $topGenerated,
            'clicks' => $topClicks,
        ];
    }

    protected function mostUsedChapters($start, $end)
    {
        return Chapter::select(
                'chapters.id',
                'chapters.chapter',
                DB::raw('COUNT(posts.id) as total')
            )
            ->leftJoin('posts', function ($join) use ($start, $end) {
                $join->on('posts.chapter_id', '=', 'chapters.id')
                     ->whereBetween('posts.created_at', [$start, $end]);
            })
            ->groupBy('chapters.id', 'chapters.chapter')
            ->get();
    }
}

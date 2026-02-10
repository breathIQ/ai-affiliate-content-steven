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
            'most_used_chapters' => $this->mostUsedChapters($currentMonthStart, now(),'month'),

        ],'dashboard data get successfully', 200);
    }

    protected function totalUsers($currentMonthEnd,$previousMonthEnd)
    {
        $currentTotal = User::whereHas('role', function($q) {
                            $q->where('role', 'User');
                        })->where('created_at', '<=', $currentMonthEnd)->count();
        $previousTotal = User::whereHas('role', function($q) {
                            $q->where('role', 'User');
                        })->where('created_at', '<=', $previousMonthEnd)->count();

        $growthRate = $previousTotal > 0
            ? round((($currentTotal - $previousTotal) / $previousTotal) * 100)  
            : 0;

        // Graph data: Daily user accounts created in the current month
        $startOfMonth = Carbon::parse($currentMonthEnd)->startOfMonth();
        $todayDay = Carbon::now()->day; // Loop until today
        
        $dailyCounts = User::whereHas('role', function($q) {
                $q->where('role', 'User');
            })
            ->whereBetween('created_at', [$startOfMonth, $currentMonthEnd])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $graphData = [];
        for ($i = 1; $i <= $todayDay; $i++) {
            $date = $startOfMonth->copy()->day($i)->toDateString();
            if (isset($dailyCounts[$date]) && $dailyCounts[$date] > 0) {
                $graphData[] = [
                    'label' => $date,
                    'value' => $dailyCounts[$date]
                ];
            }
        }

        return [
            'count' => User::whereHas('role', function($q) {
                            $q->where('role', 'User');
                        })->count(),
            'growth_percent' => $growthRate,
            'graph_data' => $graphData
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
        $totalClicks = AffiliateClick::whereBetween('created_at', [$start, $end])->count();
        
        // Graph data: Daily affiliate clicks in the current month
        $startOfMonth = Carbon::parse($start);
        $todayDay = Carbon::now()->day; // Loop until today
        
        $dailyCounts = AffiliateClick::whereBetween('created_at', [$start, $end])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $graphData = [];
        for ($i = 1; $i <= $todayDay; $i++) {
            $date = $startOfMonth->copy()->day($i)->toDateString();
            if (isset($dailyCounts[$date]) && $dailyCounts[$date] > 0) {
                $graphData[] = [
                    'label' => $date,
                    'value' => $dailyCounts[$date]
                ];
            }
        }

        return [
            'total' => $totalClicks,
            'graph_data' => $graphData
        ];
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
            $q->whereBetween('created_at', [$start, $end]);
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

    // protected function mostUsedChapters($start, $end)
    // {
    //     return Chapter::select(
    //             'chapters.id',
    //             'chapters.chapter',
    //             DB::raw('COUNT(posts.id) as posts_count'),
    //             DB::raw('COALESCE(SUM(posts.total_clicks), 0) as clicks_count')
    //         )
    //         ->leftJoin('posts', function ($join) use ($start, $end) {
    //             $join->on('posts.chapter_id', '=', 'chapters.id')
    //                  ->whereBetween('posts.created_at', [$start, $end]);
    //         })
    //         ->groupBy('chapters.id', 'chapters.chapter')
    //         ->having('posts_count', '>', 0)
    //         ->orderByDesc('posts_count')
    //         ->limit(10)
    //         ->get()
    //         ->map(function ($item) {
    //             // Return in a format suitable for charts, or just raw data
    //             // For a bubble chart, the frontend likely maps:
    //             // x: posts_count, y: clicks_count, r: posts_count (or vice versa)
    //             $avg_clicks = $item->posts_count > 0 ? ($item->clicks_count / $item->posts_count) : 0;
    //             return [
    //                 'id' => $item->id,
    //                 'label' =>  preg_replace('/^CHAPTER\s+/i', 'Ch-', $item->chapter),
    //                 'data' => [
    //                     'posts_count' => $item->posts_count, 
    //                     'clicks_count' => (int)$item->clicks_count, 
    //                     'avg_clicks' => round($avg_clicks, 2) // Scaled for visibility
    //                 ],
    //             ];
    //         });
    // }

    // public function getMostUsedChapter(Request $request)
    // {
    //     $dataBY = $request->query('filter_by', 'month');

    //     if ($dataBY === 'week') {
    //         $data = $this->mostUsedChapters(now()->subWeek(), now());
           
    //     } else {
    //         $data = $this->mostUsedChapters(now()->subMonth(), now());
    //     }

    //     return $this->sendResponse(['most_used_chapters' =>$data],'Most Used Chapters by ' . $dataBY,200);
    // }

    public function getMostUsedChapter(Request $request)
    {
        $filterBy = $request->query('filter_by', 'month');

        // $start = $filterBy === 'week'
        //     ? now()->subWeek()
        //     : now()->subMonth();

        $start = $filterBy === 'week'
            ? now()->startOfWeek()
            : now()->startOfMonth();
        
        $rows = $this->mostUsedChapters($start, now(), $filterBy);

        // $datasets = $this->formatBubbleDatasets($rows, $filterBy);

        return $this->sendResponse(
            ['most_used_chapters' => $rows],
            'Most Used Chapters by ' . ucfirst($filterBy),
            200
        );
    }

    protected function formatBubbleDatasets($rows, $filterBy)
    {
        return $rows
            ->groupBy('chapter_id')
            ->map(function ($items) use ($filterBy) {

                $chapterName = optional($items->first()->chapter)->chapter;

                $data = $items->map(function ($row) use ($filterBy) {

                    $date = \Carbon\Carbon::parse($row->day);

                    // X axis (use date string for Chart.js time scale)
                    $x = $date->format('Y-m-d');

                    return [
                        'x' => $x,
                        'y' => (int) $row->usage_count,
                        'r' => max(5, round($row->usage_count / 2)),
                    ];
                })->values();

                return [
                    'label' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapterName),
                    'data'  => $data,
                ];
            })
            ->values();
    }

    protected function mostUsedChapters($start, $end,$filterBy)
    {
        // STEP 1: get top 10 chapter IDs by usage
        $topChapterIds = Post::whereBetween('created_at', [$start, $end])
            ->select('chapter_id', DB::raw('COUNT(*) as total_usage'))
            ->groupBy('chapter_id')
            ->orderByDesc('total_usage')
            ->limit(10)
            ->pluck('chapter_id');

        // STEP 2: fetch daily usage only for those chapters
        $rows = Post::with('chapter')
            ->whereIn('chapter_id', $topChapterIds)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'chapter_id',
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(id) as usage_count')
            )
            ->groupBy('chapter_id', DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get();
            
            
        return $this->formatBubbleDatasets($rows,$filterBy);
    }



}

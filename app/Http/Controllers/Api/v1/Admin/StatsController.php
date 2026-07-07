<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\{User, Post, CreditTransaction, HeygenGeneration, GrokVideoGeneration};
use Carbon\Carbon;

class StatsController extends ResponseController
{
    public function getStats(Request $request)
    {
        $periods = [
            'today'      => Carbon::now()->startOfDay(),
            'this_week'  => Carbon::now()->startOfWeek(),
            'this_month' => Carbon::now()->startOfMonth(),
            'all_time'   => null,
        ];

        $stats = [];
        foreach ($periods as $key => $start) {
            $stats[$key] = $this->periodStats($start);
        }

        return $this->sendResponse([
            'periods' => $stats,
            'daily'   => $this->dailySeries(30),
            'price_cents_per_credit' => $this->priceCentsPerCredit(),
        ], 'stats data get successfully', 200);
    }

    protected function priceCentsPerCredit(): int
    {
        return (int) config('services.credits.price_cents_per_credit');
    }

    protected function periodStats(?Carbon $start): array
    {
        $since = fn ($query) => $start ? $query->where('created_at', '>=', $start) : $query;

        $newUsers = $since(User::whereHas('role', fn ($q) => $q->where('role', 'User')))->count();

        $postsGenerated = $since(Post::query())->count();
        $postsPublished = $since(Post::query())->where('status', 'published')->count();
        $activePosters  = $since(Post::query())->distinct('user_id')->count('user_id');

        $creditsPurchased = (int) $since(CreditTransaction::query())
            ->whereIn('type', ['purchase', 'auto_recharge'])
            ->sum('credits');
        $payingUsers = $since(CreditTransaction::query())
            ->whereIn('type', ['purchase', 'auto_recharge'])
            ->distinct('user_id')
            ->count('user_id');
        $creditsSpent = (int) abs($since(CreditTransaction::query())
            ->where('type', 'deduction')
            ->sum('credits'));
        $creditsRefunded = (int) $since(CreditTransaction::query())
            ->where('type', 'refund')
            ->sum('credits');

        $videosGenerated = $since(HeygenGeneration::query())->count()
            + $since(GrokVideoGeneration::query())->count();

        return [
            'new_users'         => $newUsers,
            'posts_generated'   => $postsGenerated,
            'posts_published'   => $postsPublished,
            'active_posters'    => $activePosters,
            'videos_generated'  => $videosGenerated,
            'credits_purchased' => $creditsPurchased,
            'credits_spent'     => $creditsSpent,
            'credits_refunded'  => $creditsRefunded,
            'paying_users'      => $payingUsers,
            // Transactions store credits, not dollars, so revenue is derived at
            // the current per-credit price.
            'revenue_cents'     => $creditsPurchased * $this->priceCentsPerCredit(),
        ];
    }

    protected function dailySeries(int $days): array
    {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $signups = User::whereHas('role', fn ($q) => $q->where('role', 'User'))
            ->where('created_at', '>=', $start)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $posts = Post::where('created_at', '>=', $start)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $purchasedCredits = CreditTransaction::whereIn('type', ['purchase', 'auto_recharge'])
            ->where('created_at', '>=', $start)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(credits) as credits'))
            ->groupBy('date')
            ->pluck('credits', 'date');

        $price = $this->priceCentsPerCredit();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $series[] = [
                'date'          => $date,
                'signups'       => (int) ($signups[$date] ?? 0),
                'posts'         => (int) ($posts[$date] ?? 0),
                'revenue_cents' => (int) ($purchasedCredits[$date] ?? 0) * $price,
            ];
        }

        return $series;
    }
}

<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\AutomationCampaign;
use App\Models\AutomationStep;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * User-facing CRUD for automation campaigns - a sequence of content steps
 * (day N: carousel/heygen/image-to-video/product promo) generated and
 * published automatically by the automation:run-due command.
 */
class AutomationCampaignController extends ResponseController
{
    private const CONTENT_TYPES = ['carousel_images', 'heygen_video', 'image_to_video', 'product_promo'];
    private const PLATFORMS = ['instagram', 'instagram_story', 'tiktok'];

    public function index()
    {
        $campaigns = AutomationCampaign::with('steps')
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->get();

        return $this->sendResponse($campaigns, 'Automation campaigns retrieved.');
    }

    public function show($id)
    {
        $campaign = AutomationCampaign::with(['steps.post'])
            ->where('user_id', Auth::id())
            ->find($id);

        if (! $campaign) {
            return $this->sendError('Automation campaign not found.', [], 404);
        }

        return $this->sendResponse($campaign, 'Automation campaign retrieved.');
    }

    public function store(Request $request)
    {
        $validator = $this->validateCampaign($request);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }

        $user = Auth::user();
        $startDate = Carbon::parse($request->start_date)->startOfDay();

        $campaign = DB::transaction(function () use ($request, $user, $startDate) {
            $campaign = AutomationCampaign::create([
                'user_id' => $user->id,
                'name' => $request->name,
                'status' => 'active',
                'start_date' => $startDate->toDateString(),
                'platforms' => $request->platforms,
                'defaults' => $request->input('defaults', []),
            ]);

            foreach ($request->steps as $s) {
                $this->createStep($campaign, $startDate, $s);
            }

            return $campaign;
        });

        return $this->sendResponse($campaign->load('steps'), 'Automation campaign created.', 201);
    }

    public function update(Request $request, $id)
    {
        $campaign = AutomationCampaign::where('user_id', Auth::id())->find($id);
        if (! $campaign) {
            return $this->sendError('Automation campaign not found.', [], 404);
        }

        $validator = $this->validateCampaign($request);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();

        DB::transaction(function () use ($campaign, $request, $startDate) {
            $campaign->update([
                'name' => $request->name,
                'start_date' => $startDate->toDateString(),
                'platforms' => $request->platforms,
                'defaults' => $request->input('defaults', []),
                'status' => $request->input('status', $campaign->status),
            ]);

            // Only steps that haven't run yet can be rewritten. Executed/queued
            // steps stay as history; we replace the still-pending ones.
            $campaign->steps()->where('status', 'pending')->delete();
            foreach ($request->steps as $s) {
                // Skip steps the client marks as already-run (they carry an id
                // that is no longer pending) - only (re)create pending ones.
                if (! empty($s['locked'])) {
                    continue;
                }
                $this->createStep($campaign, $startDate, $s);
            }
        });

        return $this->sendResponse($campaign->fresh()->load('steps'), 'Automation campaign updated.');
    }

    /** Pause / resume without editing steps. */
    public function setStatus(Request $request, $id)
    {
        $campaign = AutomationCampaign::where('user_id', Auth::id())->find($id);
        if (! $campaign) {
            return $this->sendError('Automation campaign not found.', [], 404);
        }
        $validator = Validator::make($request->all(), ['status' => 'required|in:active,paused']);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }
        $campaign->update(['status' => $request->status]);

        return $this->sendResponse($campaign, 'Status updated.');
    }

    public function destroy($id)
    {
        $campaign = AutomationCampaign::where('user_id', Auth::id())->find($id);
        if (! $campaign) {
            return $this->sendError('Automation campaign not found.', [], 404);
        }
        $campaign->delete(); // cascades to steps

        return $this->sendResponse([], 'Automation campaign deleted.');
    }

    // ---- helpers ------------------------------------------------------------

    private function validateCampaign(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'start_date' => 'required|date',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'in:' . implode(',', self::PLATFORMS),
            'defaults' => 'nullable|array',
            'status' => 'nullable|in:active,paused',
            'steps' => 'required|array|min:1',
            'steps.*.day_number' => 'required|integer|min:1|max:365',
            'steps.*.run_at_time' => 'nullable|date_format:H:i',
            'steps.*.content_type' => 'required|in:' . implode(',', self::CONTENT_TYPES),
            'steps.*.chapter_id' => 'nullable|integer|exists:chapters,id',
            'steps.*.campaign_slug' => 'nullable|string|exists:campaigns,slug',
            'steps.*.params' => 'nullable|array',
        ]);
    }

    private function createStep(AutomationCampaign $campaign, Carbon $startDate, array $s): AutomationStep
    {
        $time = $s['run_at_time'] ?? '09:00';
        $scheduledFor = $startDate->copy()
            ->addDays(((int) $s['day_number']) - 1)
            ->setTimeFromTimeString($time . ':00');

        // Book content types need a chapter; product promos need a campaign slug.
        $isProduct = $s['content_type'] === 'product_promo';
        if ($isProduct && empty($s['campaign_slug'])) {
            abort(422, 'A product promo step needs a campaign.');
        }
        if (! $isProduct && empty($s['chapter_id'])) {
            abort(422, 'This step needs a chapter.');
        }

        return AutomationStep::create([
            'automation_campaign_id' => $campaign->id,
            'day_number' => (int) $s['day_number'],
            'run_at_time' => $time . ':00',
            'content_type' => $s['content_type'],
            'chapter_id' => $isProduct ? null : $s['chapter_id'],
            'campaign_slug' => $isProduct ? $s['campaign_slug'] : null,
            'params' => $s['params'] ?? [],
            'status' => 'pending',
            'scheduled_for' => $scheduledFor,
        ]);
    }
}

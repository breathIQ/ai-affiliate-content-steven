<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use App\Mail\AffiliateInviteMail;
use App\Models\{InviteUser};
use Illuminate\Support\Facades\Mail;

class AffiliateController extends ResponseController
{
    public function sendAffiliateInvite(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'emails' => 'required|string', // multiple emails separated by newline
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            // Convert textarea input into array
            $emails = array_filter(
                array_map('trim', preg_split("/\r\n|\n|\r/", $request->emails))
            );

            if (empty($emails)) {
                return $this->sendError('No valid email provided.', [], 422);
            }

            $invalidEmails = [];

            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalidEmails[] = $email;
                }
            }

            if (!empty($invalidEmails)) {
                return $this->sendError(
                    'Invalid email(s) provided.',
                    ['invalid_emails' => $invalidEmails],
                    422
                );
            }

            foreach ($emails as $email) {

                // Skip if already invited
                // if (InviteUser::where('email', $email)->where('is_used', false)->exists()) {
                //     continue;
                // }

                $token = Str::uuid();

                $invite = InviteUser::create([
                    'email' => $email,
                    'token' => $token,
                    'expires_at' => now()->addDays(7),
                ]);

                // Send invite email
                Mail::to($email)->send(new AffiliateInviteMail($invite));
            }

            return $this->sendResponse([], 'Invite(s) sent successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError(
                'Failed to send invite.',
                ['error' => $e->getMessage()],
                500
            );
        }
    }

}

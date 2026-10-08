<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWrangleWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-Wrangle-Timestamp');
        $signature = $request->header('X-Wrangle-Signature');

        $secret = config('services.wrangle.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Webhook secret is not configured.',
            ], 500);
        }

        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return response()->json([
                'ok' => false,
                'message' => 'Missing or invalid webhook timestamp.',
            ], 401);
        }

        if (! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return response()->json([
                'ok' => false,
                'message' => 'Missing or invalid webhook signature.',
            ], 401);
        }

        /*
         * Reject old/future requests to reduce replay attacks.
         * Allow a 5-minute clock difference.
         */
        if (abs(time() - (int) $timestamp) > 300) {
            return response()->json([
                'ok' => false,
                'message' => 'Webhook timestamp has expired.',
            ], 401);
        }

        /*
         * IMPORTANT:
         * Sign the exact raw bytes received from WRANGLE.
         *
         * HMAC-SHA256(
         *     timestamp + "." + RAW_REQUEST_BODY,
         *     merchant_token
         * )
         */
        $rawBody = $request->getContent();

        $expectedSignature = hash_hmac(
            'sha256',
            $timestamp.'.'.$rawBody,
            $secret
        );

        if (! hash_equals($expectedSignature, strtolower($signature))) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Wrangle\WrangleMarketSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WrangleWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WrangleMarketSyncService $syncService
    ): JsonResponse {
        $payload = $request->json()->all();

        if (! is_array($payload)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid JSON payload.',
            ], 422);
        }

        $eventName = $payload['event'] ?? null;
        $marketId = $payload['market']['id'] ?? null;

        /*
         * market_url receives only the initial publication.
         * callback_url receives lifecycle updates.
         */
        $allowedEvents = match ($request->route()?->getName()) {
            'webhooks.wrangle.market' => [
                'market.published',
            ],

            'webhooks.wrangle.callback' => [
                'market.resolving',
                'market.resolved',
                'market.cancelled',
            ],

            default => [],
        };

        if (! in_array($eventName, $allowedEvents, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unsupported event for this endpoint.',
            ], 422);
        }

        if (! $marketId) {
            return response()->json([
                'ok' => false,
                'message' => 'Market ID is required.',
            ], 422);
        }

        /*
         * Hash raw HTTP body rather than re-encoded JSON.
         */
        $payloadHash = hash('sha256', $request->getContent());

        $existing = WebhookEvent::query()
            ->where('payload_hash', $payloadHash)
            ->first();

        /*
         * A successfully processed webhook is safely idempotent.
         */
        if ($existing && $existing->status === 'processed') {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'status' => 'processed',
            ]);
        }

        /*
         * Another request may currently be processing the same webhook.
         * Return non-2xx so the sender can retry later.
         */
        if ($existing && $existing->status === 'pending') {
            return response()->json([
                'ok' => false,
                'duplicate' => true,
                'status' => 'pending',
                'message' => 'Webhook is already being processed.',
            ], 409);
        }

        /*
         * If a previous attempt failed, reuse the event and try again.
         */
        if ($existing && $existing->status === 'failed') {
            $webhook = $existing;

            $webhook->update([
                'status' => 'pending',
                'error' => null,
                'processed_at' => null,
                'received_at' => now(),
            ]);
        } else {
            try {
                $webhook = WebhookEvent::query()->create([
                    'event' => $eventName,
                    'wrangle_market_id' => $marketId,
                    'payload_hash' => $payloadHash,
                    'payload' => $payload,
                    'status' => 'pending',
                    'received_at' => now(),
                ]);
            } catch (Throwable $e) {
                /*
                 * Handles a race where identical requests arrive
                 * simultaneously and unique(payload_hash) wins.
                 */
                $existing = WebhookEvent::query()
                    ->where('payload_hash', $payloadHash)
                    ->first();

                if ($existing) {
                    return response()->json([
                        'ok' => false,
                        'duplicate' => true,
                        'status' => $existing->status,
                        'message' => 'Webhook is already being processed.',
                    ], 409);
                }

                throw $e;
            }
        }

        try {
            $market = $syncService->process($payload);

            $webhook->update([
                'status' => 'processed',
                'processed_at' => now(),
                'error' => null,
            ]);

            return response()->json([
                'ok' => true,
                'event' => $eventName,
                'market_id' => $market->wrangle_market_id,
                'public_id' => $market->public_id,
            ]);
        } catch (Throwable $e) {
            $webhook->update([
                'status' => 'failed',
                'error' => mb_substr(
                    $e->getMessage(),
                    0,
                    65000
                ),
            ]);

            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Webhook processing failed.',
            ], 500);
        }
    }
}

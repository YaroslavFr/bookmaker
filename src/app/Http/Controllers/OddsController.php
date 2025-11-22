<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Event;

class OddsController extends Controller
{
    /**
     * Return extra markets for a given local Event by matching it to sstats game and fetching odds/list.
     */
    public function markets(Event $event, Request $request)
    {
        \Barryvdh\Debugbar\Facades\Debugbar::addMessage($event->external_id, 'external_id');
        if ((string)$event->competition === 'TEST' || str_starts_with((string)$event->external_id, 'test:')) {
            $path2 = base_path('test_extra_odds.json');
            $markets = [];
            if (is_string($path2) && file_exists($path2)) {
                $json2 = json_decode(file_get_contents($path2), true);
                $markets = data_get($json2, 'data.0.odds');
            }
            return $this->marketsByGame($request, $event->external_id, is_array($markets) ? $markets : []);
        }

        if (!$event->external_id) {
            return response()->json(['ok' => false, 'error' => 'Missing external_id for event'], 400);
        }
        \Barryvdh\Debugbar\Facades\Debugbar::addMessage($event->external_id, 'external_id');
        return $this->marketsByGame($request, $event->external_id, null);
    }

    /**
     * Fetch extra markets by direct gameId via /Odds/{gameId} with bookmakerId=2.
     */
    public function marketsByGame(Request $request, $gameId, $preloadedMarkets = null)
    {
        if (is_array($preloadedMarkets) && !empty($preloadedMarkets)) {
            $out = [];
            foreach ($preloadedMarkets as $m) {
                $marketId = $m['marketId'] ?? null;
                if ($marketId === 1) { continue; }
                $name = (string)($m['marketName'] ?? ('Market '.(string)$marketId));
                $sels = $m['odds'] ?? [];
                $norm = [];
                foreach ($sels as $s) {
                    $label = (string)($s['name'] ?? '');
                    $price = $s['value'] ?? $s['odd'] ?? $s['rate'] ?? null;
                    if (!is_numeric($price)) continue;
                    $norm[] = ['label' => $label, 'price' => (float)$price];
                }
                if (!empty($norm)) { $out[] = ['name' => $name, 'selections' => $norm]; }
                if (count($out) >= 12) break;
            }
            return response()->json(['ok' => true, 'count' => count($out), 'markets' => $out]);
        }
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        if (!$apiKey) {
            return response()->json(['ok' => false, 'error' => 'Missing SSTATS_API_KEY'], 500);
        }
        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $bookmakerId = (int) ($request->query('bookmakerId', 2));

        try {
            $endpoint = $base.'/Odds/'.urlencode((string)$gameId);
            $resp = \Illuminate\Support\Facades\Http::withHeaders($headers)->timeout(12)->get($endpoint, ['bookmakerId' => $bookmakerId]);
            if (!$resp->ok()) {
                return response()->json(['ok' => false, 'error' => 'Failed to fetch markets', 'status' => $resp->status()], 502);
            }
            $payload = $resp->json() ?? [];
            // Ответ /Odds/{gameId} обычно содержит массив букмекеров; выберем нужного
            $books = [];
            if (isset($payload['data'])) {
                $data = $payload['data'];
                $books = is_array($data) ? ($data['odds'] ?? $data['bookmakers'] ?? (isset($data[0]) ? $data : [])) : [];
            } elseif (is_array($payload)) {
                $books = $payload['odds'] ?? $payload['bookmakers'] ?? [];
            }
            if (!is_array($books)) { $books = []; }

            $chosen = null;
            foreach ($books as $b) {
                $bid = $b['bookmakerId'] ?? data_get($b, 'bookmaker.id') ?? $b['id'] ?? null;
                if ((int) $bid === $bookmakerId) { $chosen = $b; break; }
            }
            if (!$chosen && isset($books[0])) { $chosen = $books[0]; }
            if (!$chosen) {
                return response()->json(['ok' => false, 'error' => 'Bookmaker odds not found'], 404);
            }

            $blocks = $chosen['odds'] ?? $chosen['markets'] ?? [];
            if (!is_array($blocks)) { $blocks = []; }

            $out = [];
            foreach ($blocks as $m) {
                $name = (string)($m['marketName'] ?? $m['name'] ?? $m['market'] ?? '');
                $lower = strtolower($name);
                $marketId = $m['marketId'] ?? $m['id'] ?? null;
                // Отбрасываем основной 1x2 / Match Odds
                if ($marketId === 1 || str_contains($lower, '1x2') || str_contains($lower, 'match odds') || str_contains($lower, 'win-draw-win') || str_contains($lower, 'full time')) {
                    continue;
                }
                $sels = $m['odds'] ?? $m['selections'] ?? $m['runners'] ?? [];
                $normSels = [];
                foreach ($sels as $s) {
                    $label = (string)($s['name'] ?? $s['selectionName'] ?? $s['label'] ?? $s['runner'] ?? '');
                    $price = $s['value'] ?? $s['price'] ?? $s['decimal'] ?? $s['odds'] ?? $s['rate'] ?? null;
                    if (!is_numeric($price)) continue;
                    $normSels[] = [ 'label' => $label, 'price' => (float)$price ];
                }
                if (!empty($normSels)) {
                    $out[] = [ 'name' => $name, 'selections' => $normSels ];
                }
                if (count($out) >= 10) break;
            }
            \Barryvdh\Debugbar\Facades\Debugbar::addMessage($out, 'out');
            return response()->json(['ok' => true, 'count' => count($out), 'markets' => $out]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

}

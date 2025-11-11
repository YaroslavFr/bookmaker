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
        // Для тестовой лиги: читаем локальный JSON (по умолчанию base_path('odds_test.json'))
        if ((string)$event->competition === 'TEST' || str_starts_with((string)$event->external_id, 'test:')) {
            $path = env('TEST_ODDS_FILE', base_path('odds_test.json'));
            try {
                if (is_string($path) && file_exists($path)) {
                    $json = json_decode(file_get_contents($path), true);
                    $data = is_array($json) ? ($json['data'] ?? []) : [];
                    $first = is_array($data) && isset($data[0]) ? $data[0] : null;
                    $blocks = is_array($first) ? ($first['odds'] ?? []) : [];
                    $out = [];
                    foreach ($blocks as $m) {
                        $marketId = $m['marketId'] ?? null;
                        $name = (string)($m['marketName'] ?? '');
                        // пропускаем основной рынок 1x2
                        if ($marketId === 1) { continue; }
                        $sels = $m['odds'] ?? [];
                        $norm = [];
                        foreach ($sels as $s) {
                            $label = (string)($s['name'] ?? '');
                            $price = $s['value'] ?? $s['odd'] ?? $s['rate'] ?? null;
                            if (!is_numeric($price)) continue;
                            $norm[] = ['label' => $label, 'price' => (float)$price];
                        }
                        if (!empty($norm)) {
                            // Если имя рынка пустое, сформируем из id или первой метки
                            if ($name === '' || $name === null) {
                                $name = match ((int)$marketId) {
                                    12 => 'Double Chance',
                                    5 => 'Total 2.5',
                                    16 => 'Total 1.5',
                                    17 => 'Total 1.5 (Alt)',
                                    default => ($norm[0]['label'] ?? 'Market '.(string)$marketId),
                                };
                            }
                            $out[] = ['name' => $name, 'selections' => $norm];
                        }
                        if (count($out) >= 12) break;
                    }
                    return response()->json(['ok' => true, 'count' => count($out), 'markets' => $out]);
                }
            } catch (\Throwable $e) {
                // Фолбэк: возвращаем синтетические рынки на основе базовых коэффициентов
            }
            // Синтетический фолбэк (если файла нет или парсинг не удался)
            $home = (float)($event->home_odds ?? 2.0);
            $draw = (float)($event->draw_odds ?? 3.2);
            $away = (float)($event->away_odds ?? 4.0);
            $inv = function($x) { return $x > 0 ? (1.0 / $x) : 0.0; };
            $dc = function($a, $b) use ($inv) { $p = $inv($a) + $inv($b); return $p > 0 ? round(1.0 / $p, 2) : 1.5; };
            $clamp = function($v, $min, $max) { return max($min, min($max, $v)); };
            $markets = [
                [ 'name' => 'Double Chance', 'selections' => [
                    [ 'label' => '1X', 'price' => $clamp($dc($home, $draw), 1.15, 2.20) ],
                    [ 'label' => '12', 'price' => $clamp($dc($home, $away), 1.30, 2.50) ],
                    [ 'label' => 'X2', 'price' => $clamp($dc($draw, $away), 1.20, 2.30) ],
                ]],
                [ 'name' => 'Total 2.5', 'selections' => [
                    [ 'label' => 'Over 2.5', 'price' => $clamp(round(1.88 + (($home - $away) * 0.05), 2), 1.55, 2.50) ],
                    [ 'label' => 'Under 2.5', 'price' => $clamp(round(1.92 - (($home - $away) * 0.05), 2), 1.55, 2.60) ],
                ]],
            ];
            return response()->json(['ok' => true, 'count' => count($markets), 'markets' => $markets]);
        }

        if (!$event->external_id) {
            return response()->json(['ok' => false, 'error' => 'Missing external_id for event'], 400);
        }
        // Делегируем к прямому запросу рынков по gameId
        return $this->marketsByGame($request, $event->external_id);
    }

    /**
     * Fetch extra markets by direct gameId via /Odds/{gameId} with bookmakerId=2.
     */
    public function marketsByGame(Request $request, $gameId)
    {
        // Для тестовой лиги/локальных gameId используем файл odds_test.json
        if (str_starts_with((string)$gameId, 'test:') || env('TEST_LEAGUE', false)) {
            $path = env('TEST_ODDS_FILE', base_path('odds_test.json'));
            if (is_string($path) && file_exists($path)) {
                try {
                    $json = json_decode(file_get_contents($path), true);
                    $data = is_array($json) ? ($json['data'] ?? []) : [];
                    $first = is_array($data) && isset($data[0]) ? $data[0] : null;
                    $blocks = is_array($first) ? ($first['odds'] ?? []) : [];
                    $out = [];
                    foreach ($blocks as $m) {
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
                        if (!empty($norm)) {
                            $out[] = ['name' => $name, 'selections' => $norm];
                        }
                        if (count($out) >= 12) break;
                    }
                    return response()->json(['ok' => true, 'count' => count($out), 'markets' => $out]);
                } catch (\Throwable $e) {
                    // fallthrough to API
                }
            }
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
            
            return response()->json(['ok' => true, 'count' => count($out), 'markets' => $out]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

}
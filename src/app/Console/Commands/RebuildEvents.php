<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Event;
use App\Models\Bet;
use Carbon\Carbon;

class RebuildEvents extends Command
{
    protected $signature = 'events:rebuild {--json=} {--results-json=}';
    protected $description = 'Preserving bets and refresh upcoming events from API. ';

    public function handle(): int
    {
        // Обновляем события через API (EPL, UCL, ITA и т.д.) они же ставки на главной.
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        $jsonPath = (string) ($this->option('json') ?? '');
        $resultsPath = (string) ($this->option('results-json') ?? '');
        if ($jsonPath !== '') {
            [$addedJson, $updatedJson] = $this->refreshFromJson($jsonPath);
            $this->info("JSON refresh complete. Added: {$addedJson}, Updated: {$updatedJson}");
            if ($resultsPath !== '') {
                $applied = $this->applyResultsFromJson($resultsPath);
                $this->info("Results applied from JSON: {$applied}");
            }
            return self::SUCCESS;
        }

        if (env('TEST_LEAGUE', false)) {
            [$addedTest, $updatedTest] = $this->generateTestEvents();
            $this->info("TEST league generated. Added: {$addedTest}, Updated: {$updatedTest}");
        }
        if (!$apiKey) {
            $this->error('Missing SSTATS_API_KEY; skipping refresh.');
            return self::SUCCESS;
        }

        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        $tz = config('app.timezone');
        // Карта код -> id из общего конфига
        $defLeagueIds = [];
        foreach (config('leagues.leagues') as $code => $info) {
            $defLeagueIds[$code] = $info['id'] ?? null;
        }
        $added = 0; 
        $updated = 0;
        $years = [2024, 2025];
        foreach ($defLeagueIds as $competition => $leagueId) {
            // Пропускаем HTTP‑обновление для тестовой лиги — она генерируется локально
            if ($competition === 'TEST') { continue; }
            foreach ($years as $year) {
                try {
                    $resp = Http::withHeaders($headers)->timeout(10)->get($base.'/games/list', [
                        'LeagueId' => $leagueId,
                        'Year' => $year,
                        'Status' => 2,
                        'Limit' => 32,
                    ]);
                    $json = $resp->ok() ? ($resp->json() ?? []) : [];
                    $games = [];
                    if (is_array($json)) {
                        if (isset($json[0])) {
                            $games = $json;
                        } elseif (isset($json['data'])) {
                            $data = $json['data'];
                            if (is_array($data)) {
                                $games = $data['games'] ?? $data['items'] ?? $data['list'] ?? $data['results'] ?? (isset($data[0]) ? $data : []);
                            }
                        } else {
                            $games = $json['games'] ?? $json['items'] ?? $json['list'] ?? $json['results'] ?? (isset($json[0]) ? $json : []);
                            if (!is_array($games)) { $games = []; }
                        }
                    }
                    $now = Carbon::now($tz);
                    foreach ($games as $g) {
                        $homeName = data_get($g, 'homeTeam.name') ?? data_get($g, 'home.name') ?? data_get($g, 'home') ?? data_get($g, 'Home');
                        $awayName = data_get($g, 'awayTeam.name') ?? data_get($g, 'away.name') ?? data_get($g, 'away') ?? data_get($g, 'Away');
                        if (!$homeName || !$awayName) continue;
                        $gameId = data_get($g, 'id') ?? data_get($g, 'game.id') ?? data_get($g, 'GameId') ?? data_get($g, 'gameid') ?? null;
                        if (!$gameId) continue;
                        $dateRaw = data_get($g, 'date') ?? data_get($g, 'start') ?? data_get($g, 'datetime') ?? null;
                        $dt = null;
                        try { $dt = Carbon::parse($dateRaw ?: (data_get($g, 'dateUtc') ? '@'.data_get($g, 'dateUtc') : null)); } catch (\Throwable $e) {}
                        if (!$dt) continue;
                        $statusName = data_get($g, 'statusName') ?? data_get($g, 'status');
                        $isEnded = $statusName ? (stripos((string)$statusName, 'finish') !== false || stripos((string)$statusName, 'ended') !== false) : false;
                        try { $dt = $dt->copy()->setTimezone($tz)->second(0)->micro(0); } catch (\Throwable $e) {}
                        if ($isEnded || $dt->lte($now)) continue;
                        [$h,$d,$a] = $this->extractInlineOdds($g);
                        if (!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) continue;
                        $title = trim((string)$homeName.' vs '.(string)$awayName);
                        $existing = Event::where('external_id', (string)$gameId)->first();
                        Event::updateOrCreate(
                            [ 'external_id' => (string)$gameId ],
                            [
                                'competition' => $competition,
                                'title' => $title,
                                'status' => 'scheduled',
                                'home_team' => (string)$homeName,
                                'away_team' => (string)$awayName,
                                'starts_at' => $dt,
                                'home_odds' => (float)$h,
                                'draw_odds' => (float)$d,
                                'away_odds' => (float)$a,
                            ]
                        );
                        if ($existing) { $updated++; } else { $added++; }
                    }
                } catch (\Throwable $e) {
                    $this->error('Refresh failed for '.$competition.' year '.$year.': '.$e->getMessage());
                }
            }
        }

        $this->info("Refresh complete. Added: {$added}, Updated: {$updated}");
        return self::SUCCESS;
    }

    /**
     * Генерация тестовых событий в «Тестовой лиге» с фиксированными командами и коэффициентами.
     * Возвращает [added, updated].
     */
    private function generateTestEvents(int $count = 6): array
    {
        $added = 0; $updated = 0;
        $now = Carbon::now();
        for ($i = 1; $i <= $count; $i++) {
            $homeName = 'Тестовая команда '.$i;
            $awayName = 'Тестовая команда '.($i + 1);
            $dt = $now->copy()->addHours($i)->second(0)->micro(0);
            // Простая шкала коэффициентов
            $home = round(1.80 + 0.05 * $i, 2);
            $draw = round(3.20 + 0.05 * $i, 2);
            $away = round(4.00 + 0.10 * $i, 2);
            $extId = 'test:'.$i;
            $title = trim($homeName.' vs '.$awayName);

            $existing = Event::where('external_id', (string)$extId)->first();
            Event::updateOrCreate(
                [ 'external_id' => (string)$extId ],
                [
                    'competition' => 'TEST',
                    'title' => $title,
                    'status' => 'scheduled',
                    'home_team' => (string)$homeName,
                    'away_team' => (string)$awayName,
                    'starts_at' => $dt,
                    'home_odds' => (float)$home,
                    'draw_odds' => (float)$draw,
                    'away_odds' => (float)$away,
                ]
            );
            if ($existing) { $updated++; } else { $added++; }
        }
        return [$added, $updated];
    }

    private function extractInlineOdds(array $game): array
    {
        $markets = data_get($game, 'odds');
        if (!is_array($markets)) return [null, null, null];
        $home = null; $draw = null; $away = null;
        foreach ($markets as $m) {
            $marketId = $m['marketId'] ?? $m['id'] ?? null;
            $name = strtolower((string)($m['marketName'] ?? $m['name'] ?? ''));
            if ($marketId === 1 || str_contains($name, '1x2') || str_contains($name, 'match odds') || str_contains($name, 'win-draw-win')) {
                $sels = $m['odds'] ?? $m['selections'] ?? [];
                foreach ($sels as $sel) {
                    $label = strtolower((string)($sel['name'] ?? $sel['label'] ?? ''));
                    $value = $sel['value'] ?? $sel['price'] ?? $sel['decimal'] ?? $sel['odds'] ?? null;
                    if (!is_numeric($value)) continue;
                    if (str_contains($label, 'home')) $home = (float)$value;
                    elseif (str_contains($label, 'draw')) $draw = (float)$value;
                    elseif (str_contains($label, 'away')) $away = (float)$value;
                }
                break;
            }
        }
        return [$home, $draw, $away];
    }

    private function refreshFromJson(string $path): array
    {
        $added = 0; $updated = 0;
        try {
            $fullPath = $this->normalizeJsonPath($path);
            $this->line('Reading JSON from: '.$fullPath);
            $raw = file_exists($fullPath) ? file_get_contents($fullPath) : '';
            $json = $raw !== '' ? json_decode($raw, true) : [];
            $items = [];
            if (is_array($json)) {
                if (isset($json['data'])) { $items = $json['data']; }
                elseif (isset($json[0])) { $items = $json; }
            }
            $tz = config('app.timezone');
            $now = Carbon::now($tz);
            foreach ($items as $g) {
                $homeName = data_get($g, 'homeTeam.name') ?? data_get($g, 'home.name') ?? data_get($g, 'home') ?? data_get($g, 'Home');
                $awayName = data_get($g, 'awayTeam.name') ?? data_get($g, 'away.name') ?? data_get($g, 'away') ?? data_get($g, 'Away');
                if (!$homeName || !$awayName) continue;
                $gameId = data_get($g, 'id') ?? data_get($g, 'game.id') ?? data_get($g, 'GameId') ?? data_get($g, 'gameid') ?? null;
                if (!$gameId) continue;
                $dateRaw = data_get($g, 'date') ?? data_get($g, 'start') ?? data_get($g, 'datetime') ?? null;
                $dt = null;
                try { $dt = Carbon::parse($dateRaw ?: (data_get($g, 'dateUtc') ? '@'.data_get($g, 'dateUtc') : null)); } catch (\Throwable $e) {}
                if (!$dt) continue;
                try { $dt = $dt->copy()->setTimezone($tz)->second(0)->micro(0); } catch (\Throwable $e) {}
                [$h,$d,$a] = $this->extractInlineOdds($g);
                if (!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) continue;
                $competition = 'TEST';
                $title = trim((string)$homeName.' vs '.(string)$awayName);
                $existing = Event::where('external_id', (string)$gameId)->first();
                Event::updateOrCreate(
                    [ 'external_id' => (string)$gameId ],
                    [
                        'competition' => $competition,
                        'title' => $title,
                        'status' => 'scheduled',
                        'home_team' => (string)$homeName,
                        'away_team' => (string)$awayName,
                        'starts_at' => $dt,
                        'home_odds' => (float)$h,
                        'draw_odds' => (float)$d,
                        'away_odds' => (float)$a,
                    ]
                );
                if ($existing) { $updated++; } else { $added++; }
            }
        } catch (\Throwable $e) {}
        return [$added, $updated];
    }

    private function applyResultsFromJson(string $path): int
    {
        $applied = 0;
        try {
            $fullPath = $this->normalizeJsonPath($path);
            $raw = file_exists($fullPath) ? file_get_contents($fullPath) : '';
            $json = $raw !== '' ? json_decode($raw, true) : [];
            $items = [];
            if (is_array($json)) {
                if (isset($json['data'])) { $items = $json['data']; }
                elseif (isset($json[0])) { $items = $json; }
            }
            foreach ($items as $g) {
                $gameId = data_get($g, 'id') ?? null;
                if (!$gameId) continue;
                $ev = Event::where('external_id', (string)$gameId)->first();
                if (!$ev) continue;
                $hr = data_get($g, 'homeResult');
                $ar = data_get($g, 'awayResult');
                $hht = data_get($g, 'homeHTResult');
                $aht = data_get($g, 'awayHTResult');
                $hft = data_get($g, 'homeFTResult');
                $aft = data_get($g, 'awayFTResult');
                $ev->home_result = is_numeric($hr) ? (int)$hr : null;
                $ev->away_result = is_numeric($ar) ? (int)$ar : null;
                $ev->home_ht_result = is_numeric($hht) ? (int)$hht : null;
                $ev->away_ht_result = is_numeric($aht) ? (int)$aht : null;
                $ev->home_st2_result = is_numeric($hft) && is_numeric($hht) ? max(0, (int)$hft - (int)$hht) : null;
                $ev->away_st2_result = is_numeric($aft) && is_numeric($aht) ? max(0, (int)$aft - (int)$aht) : null;
                $ev->result = ($ev->home_result !== null && $ev->away_result !== null)
                    ? ($ev->home_result === $ev->away_result ? 'draw' : ($ev->home_result > $ev->away_result ? 'home' : 'away'))
                    : null;
                $ev->result_text = ($ev->home_ht_result !== null && $ev->away_ht_result !== null && $ev->home_result !== null && $ev->away_result !== null)
                    ? ('HT(' . $ev->home_ht_result . ':' . $ev->away_ht_result . ') FT ' . $ev->home_result . ':' . $ev->away_result)
                    : null;
                $ev->status = 'finished';
                $ev->ends_at = Carbon::now();
                $ev->save();
                $applied++;
            }
        } catch (\Throwable $e) {}
        return $applied;
    }

    private function normalizeJsonPath(string $path): string
    {
        $p = trim($path);
        if ($p === '') return $p;
        // Base path is already the application root (src)
        if ($p === 'upcoming') return base_path('test_upcoming_events.json');
        if ($p === 'results') return base_path('test_result_events.json');
        if (str_starts_with($p, './')) return base_path(ltrim($p, './'));
        if (str_starts_with($p, 'src/')) return base_path(substr($p, 4));
        return $p;
    }
}
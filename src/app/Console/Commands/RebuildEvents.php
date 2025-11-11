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
    protected $signature = 'events:rebuild {--hard : Truncate events and repopulate from API (dangerous)}';
    protected $description = 'Deduplicate Events preserving bets and refresh upcoming events from API. Use --hard to truncate and repopulate.';

    public function handle(): int
    {
        $hard = (bool) $this->option('hard');
        if ($hard) {
            $this->warn('Hard rebuild: truncating events may break foreign keys. Proceeding...');
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } catch (\Throwable $e) {}
            try {
                DB::table('events')->truncate();
            } catch (\Throwable $e) {
                $this->error('Failed to truncate events: '.$e->getMessage());
                // Fallback to soft dedupe
            }
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable $e) {}
        } else {
            $this->info('Starting deduplication preserving bets...');
            $removed = 0;
            DB::transaction(function () use (&$removed) {
                // 1) Dedupe by external_id
                $dupeExtIds = Event::query()
                    ->whereNotNull('external_id')
                    ->select('external_id')
                    ->groupBy('external_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->pluck('external_id');
                foreach ($dupeExtIds as $extId) {
                    $rows = Event::where('external_id', $extId)
                        ->orderByDesc('starts_at')
                        ->orderByDesc('id')
                        ->get();
                    $keep = $rows->first();
                    $deleteIds = $rows->skip(1)->pluck('id')->all();
                    if (!empty($deleteIds)) {
                        Bet::whereIn('event_id', $deleteIds)->update(['event_id' => $keep->id]);
                        Event::whereIn('id', $deleteIds)->delete();
                        $removed += count($deleteIds);
                    }
                }

                // 2) Dedupe null external_id by raw names + start time (UTC minute)
                $rows = Event::whereNull('external_id')->get();
                $groups = [];
                foreach ($rows as $ev) {
                    $home = strtolower(trim((string) $ev->home_team));
                    $away = strtolower(trim((string) $ev->away_team));
                    $dt = $ev->starts_at instanceof \Illuminate\Support\Carbon ? $ev->starts_at : ($ev->starts_at ? Carbon::parse($ev->starts_at) : null);
                    $key = $home.'|'.$away.'|'.($dt ? $dt->copy()->utc()->format('Y-m-d H:i') : '');
                    $groups[$key] = $groups[$key] ?? [];
                    $groups[$key][] = $ev;
                }
                foreach ($groups as $list) {
                    if (count($list) <= 1) continue;
                    $sorted = collect($list)->sortByDesc(function ($e) {
                        return (($e->starts_at instanceof \Illuminate\Support\Carbon) ? $e->starts_at->getTimestamp() : (string)$e->starts_at).'|'.$e->id;
                    });
                    $keep = $sorted->first();
                    $deleteIds = $sorted->skip(1)->pluck('id')->all();
                    if (!empty($deleteIds)) {
                        Bet::whereIn('event_id', $deleteIds)->update(['event_id' => $keep->id]);
                        Event::whereIn('id', $deleteIds)->delete();
                        $removed += count($deleteIds);
                    }
                }
            });
            $this->info("Removed duplicates: {$removed}");
        }

        // Refresh upcoming events via API (EPL, UCL, ITA и т.д.)
        $base = rtrim(config('services.sstats.base_url', 'https://api.sstats.net'), '/');
        $apiKey = config('services.sstats.key');
        // Генерация тестовой лиги независимо от наличия API‑ключа
        if (env('TEST_LEAGUE', false)) {
            [$addedTest, $updatedTest] = $this->generateTestEvents();
            $this->info("TEST league generated. Added: {$addedTest}, Updated: {$updatedTest}");
        }
        if (!$apiKey) {
            $this->error('Missing SSTATS_API_KEY; skipping refresh.');
            return self::SUCCESS;
        }

        $headers = ['X-API-KEY' => $apiKey, 'Accept' => 'application/json'];
        // Карта код -> id из общего конфига
        $defLeagueIds = [];
        foreach (config('leagues.leagues') as $code => $info) {
            $defLeagueIds[$code] = $info['id'] ?? null;
        }
        $added = 0; 
        $updated = 0;
        foreach ($defLeagueIds as $competition => $leagueId) {
            // Пропускаем HTTP‑обновление для тестовой лиги — она генерируется локально
            if ($competition === 'TEST') { continue; }
            try {
                $resp = Http::withHeaders($headers)->timeout(10)->get($base.'/games/list', [
                    'LeagueId' => $leagueId,
                    'Year' => (int) date('Y'),
                    'Status' => 2,
                    'Limit' => 16,
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
                $now = Carbon::now();
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
                    if ($isEnded || $dt->lte($now)) continue;
                    try { $dt = $dt->copy()->utc()->second(0)->micro(0); } catch (\Throwable $e) {}
                    [$h,$d,$a] = $this->extractInlineOdds($g);
                    if (!is_numeric($h) || !is_numeric($d) || !is_numeric($a)) continue;
                    $title = trim((string)$homeName.' vs '.(string)$awayName);
                    $existing = Event::where('external_id', (string)$gameId)->first();
                    $event = Event::updateOrCreate(
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
                $this->error('Refresh failed for '.$competition.': '.$e->getMessage());
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
}
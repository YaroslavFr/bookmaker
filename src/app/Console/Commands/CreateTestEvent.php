<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Carbon\Carbon;

class CreateTestEvent extends Command
{
    protected $signature = 'events:create-test {--count=6} {--json=}';
    protected $description = 'Create or import TEST competition events for admin/moderator preview';

    public function handle(): int
    {
        $json = (string) ($this->option('json') ?? '');
        if ($json !== '') {
            [$added, $updated] = $this->importFromJson($json);
            $this->info("TEST import from JSON complete. Added: {$added}, Updated: {$updated}");
            return self::SUCCESS;
        }

        $count = (int) ($this->option('count') ?? 6);
        [$added, $updated] = $this->generateSynthetic($count);
        $this->line('<fg=green>' . "TEST synthetic generated. Added: {$added}, Updated: {$updated}" . '</>');
        return self::SUCCESS;
    }

    private function generateSynthetic(int $count): array
    {
        $added = 0; $updated = 0;
        $tz = config('app.timezone');
        $now = Carbon::now($tz);
        for ($i = 1; $i <= $count; $i++) {
            $homeName = 'Тестовая команда '.$i;
            $awayName = 'Тестовая команда '.($i + 1);
            $dt = $now->copy()->addHours($i)->second(0)->micro(0);
            $home = round(1.80 + 0.05 * $i, 2);
            $draw = round(3.20 + 0.05 * $i, 2);
            $away = round(4.00 + 0.10 * $i, 2);
            $extId = '0000000000'.$i;
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

    private function importFromJson(string $path): array
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
                $home = null; $draw = null; $away = null;
                $markets = data_get($g, 'odds');
                if (is_array($markets)) {
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
                }
                if (!is_numeric($home) || !is_numeric($draw) || !is_numeric($away)) continue;
                $title = trim((string)$homeName.' vs '.(string)$awayName);
                $existing = Event::where('external_id', (string)$gameId)->first();
                Event::updateOrCreate(
                    [ 'external_id' => (string)$gameId ],
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
        } catch (\Throwable $e) {}
        return [$added, $updated];
    }

    private function normalizeJsonPath(string $path): string
    {
        $p = trim($path);
        if ($p === '') return $p;
        if ($p === 'upcoming') return base_path('test_upcoming_events.json');
        if ($p === 'results') return base_path('test_result_events.json');
        if (str_starts_with($p, './')) return base_path(ltrim($p, './'));
        if (str_starts_with($p, 'src/')) return base_path(substr($p, 4));
        return $p;
    }
}
<div class="card {{ ($first ?? false) ? 'mt-20' : 'mt-8' }}">
    <h2 class="font-bold">{{ $title }}</h2>
    <table class="responsive-table line">
        <thead>
            <tr>
                <th>Матч</th>
                <th>Дата/время</th>
                <th>Коэфф. (П1 / Ничья / П2)</th>
                <th>Статус</th>
                <th>Доп. ставки</th>
            </tr>
        </thead>
        <tbody>
            @foreach($events as $ev)
                <tr>
                    <td data-label="Матч">
                        @if(!empty($ev->home_team) && !empty($ev->away_team))
                            <div class="teams-row">
                                <span class="team-name">{{ $ev->home_team }}</span>
                                <span class="vs-sep">vs</span>
                                <span class="team-name">{{ $ev->away_team }}</span>
                            </div>
                        @else
                            <b>{{ $ev->title ?? ('Event #'.$ev->id) }}</b>
                        @endif
                    </td>
                    <td class="text-sm muted" data-label="Дата/время">
                        {{ $ev->starts_at ? $ev->starts_at->format('d.m.Y H:i') : '—' }}
                    </td>
                    <td data-label="Коэфф. (Д/Н/Г)">
                        @php($h = $ev->home_odds)
                        @php($d = $ev->draw_odds)
                        @php($a = $ev->away_odds)
                        @if($h && $d && $a)
                            @if(($ev->status ?? 'scheduled') === 'scheduled')
                                <div class="odd-group">
                                    <span class="odd-btn odd-btn--home" data-event-id="{{ $ev->id }}" data-selection="home" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($h, 2) }}">П1 {{ number_format($h, 2) }}</span>
                                    <span class="odd-btn odd-btn--draw" data-event-id="{{ $ev->id }}" data-selection="draw" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($d, 2) }}">Ничья {{ number_format($d, 2) }}</span>
                                    <span class="odd-btn odd-btn--away" data-event-id="{{ $ev->id }}" data-selection="away" data-home="{{ $ev->home_team ?? '' }}" data-away="{{ $ev->away_team ?? '' }}" data-odds="{{ number_format($a, 2) }}">П2 {{ number_format($a, 2) }}</span>
                                </div>
                            @else
                                {{ number_format($h, 2) }} / {{ number_format($d, 2) }} / {{ number_format($a, 2) }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-sm muted {{ $ev->status === 'scheduled' ? 'muted' : 'text-green-500' }}" data-label="Статус">
                        {{ $ev->status ?? '—' }}
                        @if(($ev->status ?? 'scheduled') === 'scheduled')
                            
                        @endif
                    </td>
                    <td data-label="Доп. ставки">
                        <button type="button"
                                class="extra-toggle"
                                title="Доп. ставки"
                                data-event-id="{{ $ev->id }}"
                                data-target-id="extra-{{ $ev->id }}"
                                style="margin-left:8px; padding:2px 8px; border:1px solid #9ca3af; border-radius:4px; background:#fff;">+
                        </button>
                    </td>
                </tr>
                <tr id="extra-{{ $ev->id }}" class="extra-row" style="display:none;">
                    <td colspan="4">
                        <div class="muted" data-state="empty">Доп. ставки</div>
                        <div class="extra-markets" data-loaded="0"></div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
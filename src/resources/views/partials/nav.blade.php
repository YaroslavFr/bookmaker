<nav class="mt-2">
    <ul class="flex gap-4">
        <li>
            <a href="{{ url('/') }}"
               class="text-blue-600 hover:underline {{ url()->current() === url('/') ? 'font-semibold' : '' }}"
               aria-current="{{ url()->current() === url('/') ? 'page' : '' }}">Главная</a>
        </li>
        <li>
            <a href="{{ url('/stats') }}"
               class="text-blue-600 hover:underline {{ request()->is('stats') || request()->is('stats/*') ? 'font-semibold' : '' }}"
               aria-current="{{ request()->is('stats') || request()->is('stats/*') ? 'page' : '' }}">Статистика</a>
        </li>
        <li>
            <a href="{{ url('/docs') }}"
               class="text-blue-600 hover:underline {{ request()->is('docs') ? 'font-semibold' : '' }}"
               aria-current="{{ request()->is('docs') ? 'page' : '' }}">Документация</a>
        </li>
        @if(auth()->check() && strtolower((string) (auth()->user()->role ?? '')) === 'admin')
        <li>
            <a href="{{ route('admin.index') }}"
               class="text-blue-600 hover:underline {{ url()->current() === route('admin.index') ? 'font-semibold' : '' }}"
               aria-current="{{ url()->current() === route('admin.index') ? 'page' : '' }}">Админка</a>
        </li>
        @endif
        
    </ul>
</nav>
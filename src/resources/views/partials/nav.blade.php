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
    </ul>
</nav>
<div style="align-items: flex-end;" class="text-xl flex items-end gap-1 column flex-col">
        @auth
            <div class="font-bold">{{ auth()->user()->username ?? auth()->user()->name ?? auth()->user()->email }}</div>
            <div class="text-sm">Баланс: <span id="user-balance" data-balance="{{ (float) (auth()->user()->balance ?? 0) }}">{{ number_format((float) (auth()->user()->balance ?? 0), 0, '', ' ') }}</span> р.</div>
            <div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm underline">Выход</button>
                </form>
            </div>
        @endauth
        @guest
            <div>
                <a href="{{ route('login') }}" class="text-sm text-blue-600 hover:underline {{ request()->is('login') ? 'font-semibold' : '' }}">Вход</a>
            </div>
            <div>
                <a href="{{ route('register') }}" class="text-sm text-blue-600 hover:underline {{ request()->is('register') ? 'font-semibold' : '' }}">Регистрация</a>
            </div>
            <div>
                <a href="{{ route('password.request') }}" class="text-sm text-blue-600 hover:underline {{ request()->is('forgot-password') ? 'font-semibold' : '' }}">Забыли пароль?</a>
            </div>
        @endguest
        </div>
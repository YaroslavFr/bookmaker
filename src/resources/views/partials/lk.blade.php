        <div style="align-items: flex-end;" class="text-xl flex items-end gap-2.5 column flex-col">
        @auth
            <div class="font-bold">{{ auth()->user()->username ?? auth()->user()->name ?? auth()->user()->email }}</div>
            <div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="underline">Выход</button>
                </form>
            </div>
        @endauth
        @guest
            <div>
                <a href="{{ route('login') }}" class="text-blue-600 hover:underline {{ request()->is('login') ? 'font-semibold' : '' }}">Вход</a>
            </div>
            <div>
                <a href="{{ route('register') }}" class="text-blue-600 hover:underline {{ request()->is('register') ? 'font-semibold' : '' }}">Регистрация</a>
            </div>
            <div>
                <a href="{{ route('password.request') }}" class="text-blue-600 hover:underline {{ request()->is('forgot-password') ? 'font-semibold' : '' }}">Забыли пароль?</a>
            </div>
        @endguest
        </div>
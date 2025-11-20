<header class="hero">
    <div class="container grid grid-cols-1 md:grid-cols-[7fr_3fr]">
        <div>
            <div class="logo"><a href="{{ route('home') }}">SPORT-FREEBETS</a></div>
            <div class="description">Для тех кто любит смотреть спорт</div>
            @include('partials.nav')
        </div>
        <div>
            @include('partials.lk')
        </div>
    </div>
</header>